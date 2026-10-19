<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Backend;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Whether the comparison view of the Layout module shows the structure column (plan §4 H, 3a/3b).
 * The backend user's choice ("View" menu), stored in uc; without one, page TSconfig
 * mod.web_layout.structureLayer.showStructureColumn applies.
 */
final readonly class StructureColumnVisibility
{
    public const string PARAMETER = 'showStructure';
    private const string UC_KEY = 'structureLayerShowStructureColumn';

    /**
     * @param array<array-key, mixed> $pageTsConfig
     */
    public function isVisible(BackendUserAuthentication $backendUser, array $pageTsConfig): bool
    {
        if (isset($backendUser->uc[self::UC_KEY])) {
            return (bool)$backendUser->uc[self::UC_KEY];
        }
        return (bool)($pageTsConfig['mod']['web_layout']['structureLayer']['showStructureColumn'] ?? false);
    }

    public function setVisible(BackendUserAuthentication $backendUser, bool $visible): void
    {
        if (($backendUser->uc[self::UC_KEY] ?? null) === $visible) {
            return;
        }
        $backendUser->uc[self::UC_KEY] = $visible;
        $backendUser->writeUC();
    }

    /**
     * @param array<array-key, mixed> $pageTsConfig
     * @return array<array-key, mixed> page TSconfig with the effective visibility
     */
    public function applyTo(BackendUserAuthentication $backendUser, array $pageTsConfig): array
    {
        $pageTsConfig['mod']['web_layout']['structureLayer']['showStructureColumn'] = $this->isVisible($backendUser, $pageTsConfig) ? '1' : '0';
        return $pageTsConfig;
    }
}
