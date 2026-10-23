<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Backend;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Backend\Context\PageContext;
use TYPO3\CMS\Backend\Domain\Repository\Localization\LocalizationRepository;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;

/**
 * "Translate" for a content element missing in a language (comparison view gap): the languages
 * Core's translation wizard offers as source for the shadow, i.e. the languages the element
 * exists in, limited to the page translation's source language if that is enforced
 * (mod.web_layout.localization.enforceSourceLanguage, cms-backend patch; off by default here).
 */
final readonly class ElementTranslation
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private LocalizationRepository $localizationRepository,
        #[Autowire(service: 'cache.runtime')]
        private FrontendInterface $runtimeCache,
    ) {}

    /**
     * @return list<int> source languages; empty if the element cannot be translated into the language
     */
    public function getSourceLanguageIds(int $shadowUid, int $targetLanguage, PageContext $pageContext, BackendUserAuthentication $backendUser): array
    {
        if (!$backendUser->check('tables_modify', 'tt_content') || !$backendUser->checkLanguageAccess($targetLanguage)) {
            return [];
        }
        $languages = $this->getElementLanguages($pageContext->pageId, $backendUser->workspace)[$shadowUid] ?? [];
        $languages = array_values(array_diff($languages, [$targetLanguage]));
        if ($languages === [] || !$this->isSourceLanguageEnforced($pageContext)
            || !isset($pageContext->languageInformation->existingTranslations[$targetLanguage])
        ) {
            return $languages;
        }
        $used = $this->localizationRepository->getUsedSourceLanguages($pageContext->pageId, $targetLanguage, $backendUser->workspace);
        return $used === [] ? $languages : array_values(array_intersect($languages, $used));
    }

    private function isSourceLanguageEnforced(PageContext $pageContext): bool
    {
        return (bool)($pageContext->pageTsConfig['mod']['web_layout']['localization']['enforceSourceLanguage'] ?? true);
    }

    /**
     * Languages of the translations on a page, per shadow, once per request.
     *
     * @return array<int, list<int>>
     */
    private function getElementLanguages(int $pageId, int $workspaceId): array
    {
        $cacheIdentifier = 'structure-layer-element-languages-' . $pageId . '-' . $workspaceId;
        $languages = $this->runtimeCache->get($cacheIdentifier);
        if (is_array($languages)) {
            return $languages;
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($workspaceId));
        $rows = $queryBuilder->select('l18n_parent', 'sys_language_uid')->from('tt_content')->where(
            $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)),
            $queryBuilder->expr()->gt('sys_language_uid', 0),
            $queryBuilder->expr()->gt('l18n_parent', 0),
        )->executeQuery()->fetchAllAssociative();
        $languages = [];
        foreach ($rows as $row) {
            $languages[(int)$row['l18n_parent']][] = (int)$row['sys_language_uid'];
        }
        $languages = array_map(static fn(array $ids): array => array_values(array_unique($ids)), $languages);
        $this->runtimeCache->set($cacheIdentifier, $languages);
        return $languages;
    }
}
