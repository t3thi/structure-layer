<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\ViewHelpers;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use T3thi\StructureLayer\Backend\AllLanguagesVisibility;
use TYPO3\CMS\Backend\View\BackendLayout\Grid\GridColumnItem;
use TYPO3\CMS\Core\Domain\Record;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * The "hide / show in all languages" toggle of a translation in the page module, or null for
 * shadows and "-1" records (their own "hidden" already applies to all languages).
 *
 * <sl:allLanguagesVisibility item="{item}" />
 */
#[Autoconfigure(public: true)]
final class AllLanguagesVisibilityViewHelper extends AbstractViewHelper
{
    public function __construct(
        private readonly AllLanguagesVisibility $allLanguagesVisibility,
    ) {}

    public function initializeArguments(): void
    {
        $this->registerArgument('item', GridColumnItem::class, 'Page module grid item', true);
    }

    /**
     * @return array{hiddenEverywhere: bool, url: string, title: string, icon: string}|null
     */
    public function render(): ?array
    {
        /** @var GridColumnItem $item */
        $item = $this->arguments['item'];
        $record = $item->getRecord();
        if (!$record instanceof Record || (int)$record->getLanguageId() <= 0) {
            return null;
        }
        $shadowUid = (int)$record->getLanguageInfo()?->getTranslationParent();
        if ($shadowUid <= 0) {
            return null;
        }
        $context = $item->getContext();
        return $this->allLanguagesVisibility->getToggle(
            $item->getTable(),
            $shadowUid,
            $context->getReturnUrl() . '#element-' . $item->getTable() . '-' . $record->getUid(),
            $context->getBackendUser()->workspace
        );
    }
}
