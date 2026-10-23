<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Backend\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use T3thi\StructureLayer\Backend\EditingLanguage;
use T3thi\StructureLayer\Backend\StructureColumnVisibility;
use TYPO3\CMS\Backend\Context\PageContext;
use TYPO3\CMS\Backend\Domain\Model\Language\LanguageItem;
use TYPO3\CMS\Backend\Domain\Model\Language\PageLanguageInformation;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\View\PageViewMode;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Layout module: replaces Core's "default language" (0) selection with the Editing Language
 * (plan §4 H, 3b). Runs after Core's page-context middleware and rewrites the PageContext:
 *
 * - the structure layer is never selected, and not offered in the language selector;
 * - the Editing Language is not offered either: it is always shown, the comparison view's
 *   selector only adds languages (unless its page translation is missing: "create" stays);
 * - an explicit selection ("languages" parameter) sets the Editing Language;
 * - single-language view: exactly the Editing Language, even if the page lacks it (the
 *   template then shows the "missing page" empty state);
 * - comparison view: the Editing Language first, then the other selected languages;
 * - the structure column of the comparison view follows the user's "View" menu choice
 *   ("showStructure" parameter), written into page TSconfig for the template.
 *
 * A changed Editing Language is flagged in the request attribute CHANGED_ATTRIBUTE; the page
 * tree and the top bar show it and are refreshed (RefreshOnEditingLanguageChange).
 *
 * Core's PageContextFactory has no event for this; a middleware is the extension point.
 */
#[Autoconfigure(public: true)]
final readonly class EditingLanguageInitialization implements MiddlewareInterface
{
    public const string CHANGED_ATTRIBUTE = 'structureLayer.editingLanguageChanged';

    public function __construct(
        private EditingLanguage $editingLanguage,
        private StructureColumnVisibility $structureColumnVisibility,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $pageContext = $request->getAttribute('pageContext');
        $backendUser = $request->getAttribute('backend.user', $GLOBALS['BE_USER'] ?? null);
        $route = $request->getAttribute('route');
        if (!$pageContext instanceof PageContext
            || !$pageContext->site instanceof Site
            || !$pageContext->isAccessible()
            || $pageContext->pageId === 0
            || !$backendUser instanceof BackendUserAuthentication
            || !$route instanceof Route
            || $route->getOption('_identifier') !== 'web_layout'
        ) {
            return $handler->handle($request);
        }
        $site = $pageContext->site;

        $requested = $this->getRequestedLanguageIds($request, $site);
        $editingLanguageId = $this->editingLanguage->get($backendUser, $site);
        if ($requested !== []) {
            if ($editingLanguageId === null || !in_array($editingLanguageId, $requested, true)) {
                $editingLanguageId = $requested[0];
            }
            if ($this->editingLanguage->set($backendUser, $site, $editingLanguageId)) {
                $request = $request->withAttribute(self::CHANGED_ATTRIBUTE, true);
            }
        }
        $showStructure = $request->getQueryParams()[StructureColumnVisibility::PARAMETER] ?? null;
        if (is_scalar($showStructure)) {
            $this->structureColumnVisibility->setVisible($backendUser, (bool)$showStructure);
        }
        if ($editingLanguageId === null) {
            return $handler->handle($request);
        }

        $selected = [$editingLanguageId];
        if ($this->isComparisonView($request)) {
            $others = $requested !== [] ? $requested : $pageContext->selectedLanguageIds;
            foreach ($others as $languageId) {
                if ($languageId > 0 && !in_array($languageId, $selected, true)) {
                    $selected[] = (int)$languageId;
                }
            }
        }

        return $handler->handle($request->withAttribute('pageContext', new PageContext(
            pageId: $pageContext->pageId,
            pageRecord: $pageContext->pageRecord,
            site: $site,
            rootLine: $pageContext->rootLine,
            pageTsConfig: $this->structureColumnVisibility->applyTo($backendUser, $pageContext->pageTsConfig),
            selectedLanguageIds: $selected,
            languageInformation: $this->withSelectableLanguageItems($pageContext->languageInformation, $editingLanguageId),
            pagePermissions: $pageContext->pagePermissions,
        )));
    }

    /**
     * @return list<int> requested languages of the site, without the structure layer
     */
    private function getRequestedLanguageIds(ServerRequestInterface $request, Site $site): array
    {
        $parsedBody = $request->getParsedBody();
        $languages = $request->getQueryParams()['languages'] ?? (is_array($parsedBody) ? $parsedBody['languages'] ?? null : null);
        if (!is_array($languages)) {
            return [];
        }
        $siteLanguages = $site->getAllLanguages();
        $requested = [];
        foreach ($languages as $languageId) {
            $languageId = (int)$languageId;
            if ($languageId > 0 && isset($siteLanguages[$languageId]) && !in_array($languageId, $requested, true)) {
                $requested[] = $languageId;
            }
        }
        return $requested;
    }

    private function isComparisonView(ServerRequestInterface $request): bool
    {
        $viewMode = $request->getQueryParams()['viewMode'] ?? null;
        $moduleData = $request->getAttribute('moduleData');
        if ($viewMode === null && $moduleData instanceof ModuleData) {
            $viewMode = $moduleData->get('viewMode');
        }
        return PageViewMode::tryFrom((int)$viewMode) === PageViewMode::LanguageComparisonView;
    }

    /**
     * Language selector items without the structure layer and the existing Editing Language.
     */
    private function withSelectableLanguageItems(PageLanguageInformation $information, int $editingLanguageId): PageLanguageInformation
    {
        return new PageLanguageInformation(
            pageId: $information->pageId,
            availableLanguages: $information->availableLanguages,
            languageStatuses: $information->languageStatuses,
            existingTranslations: $information->existingTranslations,
            creatableLanguageIds: $information->creatableLanguageIds,
            canUserCreateTranslations: $information->canUserCreateTranslations,
            languageItems: array_values(array_filter(
                $information->languageItems,
                static fn(LanguageItem $item): bool => $item->getLanguageId() !== 0
                    && ($item->getLanguageId() !== $editingLanguageId || $item->isCreatable())
            )),
        );
    }
}
