<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Backend\ContextMenu;

use T3thi\StructureLayer\Backend\AllLanguagesVisibility;
use TYPO3\CMS\Backend\ContextMenu\ItemProviders\ProviderInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Record context menu of a translation: "Hide / show in all languages" (plan §4 H, decision 3)
 * right after Core's per-language "Disable" / "Enable". It sends "hidden" for the shadow, which
 * DataHandlerHook writes on every translation (context-menu-actions.js).
 *
 * Offered where Core offers "Disable" / "Enable", i.e. the user may toggle visibility; the
 * structure permission is enforced by DataHandlerHook. Pages: ShadowPageVisibilityItemProvider.
 */
final class AllLanguagesVisibilityItemProvider implements ProviderInterface
{
    private const string LABELS = 'LLL:EXT:structure_layer/Resources/Private/Language/locallang.xlf:';

    private string $table = '';
    private string $identifier = '';

    public function __construct(
        private readonly AllLanguagesVisibility $allLanguagesVisibility,
        private readonly TcaSchemaFactory $tcaSchemaFactory,
        private readonly IconFactory $iconFactory,
    ) {}

    public function setContext(string $table, string $identifier, string $context = ''): void
    {
        $this->table = $table;
        $this->identifier = $identifier;
    }

    public function canHandle(): bool
    {
        return $this->table !== 'pages'
            && MathUtility::canBeInterpretedAsInteger($this->identifier) && (int)$this->identifier > 0
            && $this->tcaSchemaFactory->has($this->table) && $this->tcaSchemaFactory->get($this->table)->isLanguageAware();
    }

    /**
     * After Core's RecordProvider (60).
     */
    public function getPriority(): int
    {
        return 44;
    }

    /**
     * @param array<string, mixed> $items
     * @return array<string, mixed>
     */
    public function addItems(array $items): array
    {
        $current = isset($items['disable']) ? 'disable' : (isset($items['enable']) ? 'enable' : null);
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($current === null || !$backendUser instanceof BackendUserAuthentication) {
            return $items;
        }
        $languageCapability = $this->tcaSchemaFactory->get($this->table)->getCapability(TcaSchemaCapability::Language);
        $record = BackendUtility::getRecordWSOL($this->table, (int)$this->identifier);
        $shadowUid = (int)($record[$languageCapability->getTranslationOriginPointerField()->getName()] ?? 0);
        if ((int)($record[$languageCapability->getLanguageField()->getName()] ?? 0) <= 0 || $shadowUid <= 0) {
            // Shadows and "-1" records: their own "hidden" already applies to all languages
            return $items;
        }
        $state = $this->allLanguagesVisibility->getState($this->table, $shadowUid, $backendUser->workspace);
        if ($state === null) {
            return $items;
        }

        $hidden = $state['hiddenEverywhere'];
        $item = [
            'type' => 'item',
            'label' => htmlspecialchars($this->getLanguageService()->sL(self::LABELS . ($hidden ? 'allLanguages.show' : 'allLanguages.hide'))),
            'icon' => $this->iconFactory->getIcon($hidden ? 'actions-edit-unhide' : 'actions-edit-hide', IconSize::SMALL, 'flags-multiple')->render('inline'),
            'additionalAttributes' => [
                'data-callback-module' => '@t3thi/structure-layer/context-menu-actions',
                'data-shadow-uid' => (string)$shadowUid,
                'data-disable-field' => $state['field'],
                'data-hidden' => $hidden ? '0' : '1',
            ],
            'callbackAction' => 'setHiddenInAllLanguages',
        ];
        // Right after Core's per-language toggle
        $extended = [];
        foreach ($items as $key => $value) {
            $extended[$key] = $value;
            if ($key === $current) {
                $extended[$hidden ? 'enableInAllLanguages' : 'disableInAllLanguages'] = $item;
            }
        }
        return $extended;
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
