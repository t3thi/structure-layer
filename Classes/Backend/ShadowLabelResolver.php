<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Backend;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Label of a shadow, resolved at display time (plan §3.2): shadows carry no payload, so the
 * label comes from a translation - the preferred (editing) language first, then the first
 * translation in site-language order. Because of I4 a translation always exists.
 */
final readonly class ShadowLabelResolver
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private TcaSchemaFactory $tcaSchemaFactory,
        private SiteFinder $siteFinder,
    ) {}

    /**
     * @param int $pageId the page the shadow lives on (for pages: the shadow page itself)
     * @return array{label: string, language: SiteLanguage, uid: int, record: array<string, mixed>}|null
     */
    public function resolve(string $table, int $shadowUid, int $pageId, ?int $preferredLanguageId = null, int $workspaceId = 0): ?array
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
        } catch (SiteNotFoundException) {
            return null;
        }
        $translations = $this->fetchTranslations($table, $shadowUid, $workspaceId);
        $languages = $site->getAllLanguages();
        if ($preferredLanguageId !== null && isset($languages[$preferredLanguageId])) {
            $languages = [$preferredLanguageId => $languages[$preferredLanguageId]] + $languages;
        }
        foreach ($languages as $languageId => $language) {
            if ($languageId > 0 && isset($translations[$languageId])) {
                return [
                    'label' => BackendUtility::getRecordTitle($table, $translations[$languageId]),
                    'language' => $language,
                    'uid' => (int)$translations[$languageId]['uid'],
                    'record' => $translations[$languageId],
                ];
            }
        }
        return null;
    }

    /**
     * @return array<int, array<string, mixed>> not deleted translation rows by language id
     */
    private function fetchTranslations(string $table, int $shadowUid, int $workspaceId): array
    {
        $languageCapability = $this->tcaSchemaFactory->get($table)->getCapability(TcaSchemaCapability::Language);
        $languageField = $languageCapability->getLanguageField()->getName();
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($workspaceId));
        $rows = $queryBuilder->select('*')->from($table)->where(
            $queryBuilder->expr()->eq(
                $languageCapability->getTranslationOriginPointerField()->getName(),
                $queryBuilder->createNamedParameter($shadowUid, Connection::PARAM_INT)
            ),
            $queryBuilder->expr()->gt($languageField, 0),
        )->executeQuery()->fetchAllAssociative();
        $translations = [];
        foreach ($rows as $row) {
            $translations[(int)$row[$languageField]] ??= $row;
        }
        return $translations;
    }
}
