<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\ViewHelpers;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use T3thi\StructureLayer\Backend\ElementTranslation;
use TYPO3\CMS\Backend\View\BackendLayout\Grid\GridColumnItem;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Whether a shadow in the comparison view can be translated into a language: it exists in a
 * language the translation wizard offers as source (ElementTranslation).
 *
 * <sl:canTranslate item="{gridItem}" languageId="{languageId}" />
 */
#[Autoconfigure(public: true)]
final class CanTranslateViewHelper extends AbstractViewHelper
{
    public function __construct(
        private readonly ElementTranslation $elementTranslation,
    ) {}

    public function initializeArguments(): void
    {
        $this->registerArgument('item', GridColumnItem::class, 'Page module grid item of the shadow', true);
        $this->registerArgument('languageId', 'int', 'Target language', true);
    }

    public function render(): bool
    {
        /** @var GridColumnItem $item */
        $item = $this->arguments['item'];
        $context = $item->getContext();
        return $this->elementTranslation->getSourceLanguageIds(
            $item->getRecord()->getUid(),
            (int)$this->arguments['languageId'],
            $context->getPageContext(),
            $context->getBackendUser()
        ) !== [];
    }
}
