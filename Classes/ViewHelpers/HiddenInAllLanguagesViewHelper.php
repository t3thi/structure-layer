<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\ViewHelpers;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use T3thi\StructureLayer\Backend\AllLanguagesVisibility;
use TYPO3\CMS\Backend\View\BackendLayout\Grid\GridColumnItem;
use TYPO3\CMS\Core\Domain\Record;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Whether a translation in the page module is hidden in all languages (all translations of its
 * shadow are hidden). Always false for shadows and "-1" records (their own "hidden" already
 * applies to all languages).
 *
 * <sl:hiddenInAllLanguages item="{item}" />
 */
#[Autoconfigure(public: true)]
final class HiddenInAllLanguagesViewHelper extends AbstractViewHelper
{
    public function __construct(
        private readonly AllLanguagesVisibility $allLanguagesVisibility,
    ) {}

    public function initializeArguments(): void
    {
        $this->registerArgument('item', GridColumnItem::class, 'Page module grid item', true);
    }

    public function render(): bool
    {
        /** @var GridColumnItem $item */
        $item = $this->arguments['item'];
        $record = $item->getRecord();
        if (!$record instanceof Record || (int)$record->getLanguageId() <= 0) {
            return false;
        }
        $shadowUid = (int)$record->getLanguageInfo()?->getTranslationParent();
        if ($shadowUid <= 0) {
            return false;
        }
        $state = $this->allLanguagesVisibility->getState(
            $item->getTable(),
            $shadowUid,
            $item->getContext()->getBackendUser()->workspace
        );
        return $state['hiddenEverywhere'] ?? false;
    }
}
