<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Backend\ToolbarItems;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use T3thi\StructureLayer\Backend\EditingLanguage;
use T3thi\StructureLayer\StructureLayer;
use TYPO3\CMS\Backend\Toolbar\RequestAwareToolbarItemInterface;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Top bar: the Editing Language (plan §4 H, 3b), switchable from every module. Per site, as it is
 * stored; with one structure-layer site the item shows its flag and title. Selecting a language
 * calls EditingLanguageController and reloads the page tree and the module (toolbar/editing-language-menu.js).
 */
#[Autoconfigure(public: true)]
final class EditingLanguageToolbarItem implements ToolbarItemInterface, RequestAwareToolbarItemInterface
{
    private ?ServerRequestInterface $request = null;

    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly StructureLayer $structureLayer,
        private readonly EditingLanguage $editingLanguage,
        private readonly BackendViewFactory $backendViewFactory,
    ) {}

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function checkAccess(): bool
    {
        return $this->getSites() !== [];
    }

    public function getItem(): string
    {
        $sites = $this->getSites();
        $active = count($sites) === 1 ? $this->getActiveLanguage(reset($sites)) : null;
        $view = $this->backendViewFactory->create($this->getRequest(), ['typo3/cms-backend', 't3thi/structure-layer']);
        $view->assignMultiple([
            'flag' => $active['flag'] ?? 'flags-multiple',
            'name' => $active['title'] ?? '',
        ]);
        return $view->render('ToolbarItems/EditingLanguageToolbarItem');
    }

    public function hasDropDown(): bool
    {
        return true;
    }

    public function getDropDown(): string
    {
        $sites = [];
        foreach ($this->getSites() as $site) {
            $sites[] = [
                'identifier' => $site->getIdentifier(),
                'title' => $site->getConfiguration()['websiteTitle'] ?? $site->getIdentifier(),
                'languages' => $this->getLanguages($site),
            ];
        }
        $view = $this->backendViewFactory->create($this->getRequest(), ['typo3/cms-backend', 't3thi/structure-layer']);
        $view->assignMultiple([
            'sites' => $sites,
            'multipleSites' => count($sites) > 1,
        ]);
        return $view->render('ToolbarItems/EditingLanguageToolbarItemDropDown');
    }

    /**
     * @return array<string, string>
     */
    public function getAdditionalAttributes(): array
    {
        return ['class' => 'structure-layer-editing-language-toolbar'];
    }

    public function getIndex(): int
    {
        return 25;
    }

    /**
     * Languages the user may choose: every language but the structure layer, with language access.
     *
     * @return list<array{id: int, title: string, flag: string, active: bool}>
     */
    public function getLanguages(Site $site): array
    {
        $backendUser = $this->getBackendUser();
        $editingLanguageId = $this->editingLanguage->get($backendUser, $site);
        $languages = [];
        foreach ($site->getAllLanguages() as $languageId => $language) {
            if ($languageId > 0 && $backendUser->checkLanguageAccess($languageId)) {
                $languages[] = [
                    'id' => $languageId,
                    'title' => $language->getTitle(),
                    'flag' => $language->getFlagIdentifier(),
                    'active' => $languageId === $editingLanguageId,
                ];
            }
        }
        return $languages;
    }

    /**
     * @return array{id: int, title: string, flag: string, active: bool}|null
     */
    private function getActiveLanguage(Site $site): ?array
    {
        foreach ($this->getLanguages($site) as $language) {
            if ($language['active']) {
                return $language;
            }
        }
        return null;
    }

    /**
     * @return array<string, Site> structure-layer sites
     */
    private function getSites(): array
    {
        return array_filter($this->siteFinder->getAllSites(), $this->structureLayer->isStructureSite(...));
    }

    private function getRequest(): ServerRequestInterface
    {
        return $this->request ?? $GLOBALS['TYPO3_REQUEST'];
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
