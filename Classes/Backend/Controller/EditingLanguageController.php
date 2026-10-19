<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Backend\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use T3thi\StructureLayer\Backend\EditingLanguage;
use T3thi\StructureLayer\Backend\ToolbarItems\EditingLanguageToolbarItem;
use T3thi\StructureLayer\StructureLayer;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * AJAX: sets the Editing Language of a site (top bar, EditingLanguageToolbarItem) and returns the
 * re-rendered toolbar item.
 */
#[AsController]
final readonly class EditingLanguageController
{
    public function __construct(
        private SiteFinder $siteFinder,
        private StructureLayer $structureLayer,
        private EditingLanguage $editingLanguage,
        private EditingLanguageToolbarItem $toolbarItem,
    ) {}

    public function setAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $siteIdentifier = is_array($body) ? (string)($body['site'] ?? '') : '';
        $languageId = is_array($body) ? (int)($body['language'] ?? 0) : 0;
        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (SiteNotFoundException) {
            return new JsonResponse(['success' => false], 400);
        }
        $backendUser = $GLOBALS['BE_USER'];
        if (!$backendUser instanceof BackendUserAuthentication
            || !$this->structureLayer->isStructureSite($site)
            || $languageId <= 0
            || !isset($site->getAllLanguages()[$languageId])
            || !$backendUser->checkLanguageAccess($languageId)
        ) {
            return new JsonResponse(['success' => false], 400);
        }
        $changed = $this->editingLanguage->set($backendUser, $site, $languageId);
        $this->toolbarItem->setRequest($request);
        return new JsonResponse([
            'success' => true,
            'changed' => $changed,
            'item' => $this->toolbarItem->getItem(),
            'dropDown' => $this->toolbarItem->getDropDown(),
        ]);
    }
}
