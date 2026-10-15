<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Backend;

use T3thi\StructureLayer\StructureLayer;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * The backend user's Editing Language per site (plan §4 H, 3b): the concrete language the
 * backend shows first. Never the structure layer, never "all languages" (2026-09-04).
 *
 * Stored in the user's uc, not in the module data of web_layout: the page tree (Phase 4)
 * resolves titles with it as well.
 */
final readonly class EditingLanguage
{
    private const string UC_KEY = 'structureLayerEditingLanguage';

    public function __construct(
        private StructureLayer $structureLayer,
    ) {}

    /**
     * The stored language if the site still has it, otherwise the primary language.
     */
    public function get(BackendUserAuthentication $backendUser, Site $site): ?int
    {
        $stored = (int)($backendUser->uc[self::UC_KEY][$site->getIdentifier()] ?? 0);
        if ($stored > 0 && isset($site->getAllLanguages()[$stored])) {
            return $stored;
        }
        return $this->structureLayer->getPrimaryLanguage($site)?->getLanguageId();
    }

    public function set(BackendUserAuthentication $backendUser, Site $site, int $languageId): void
    {
        if ($languageId <= 0 || !isset($site->getAllLanguages()[$languageId])) {
            return;
        }
        if ((int)($backendUser->uc[self::UC_KEY][$site->getIdentifier()] ?? 0) === $languageId) {
            return;
        }
        $backendUser->uc[self::UC_KEY][$site->getIdentifier()] = $languageId;
        $backendUser->writeUC();
    }
}
