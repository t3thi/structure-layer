<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\ViewHelpers;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use T3thi\StructureLayer\Backend\ElementTranslation;
use T3thi\StructureLayer\Backend\ShadowLabelResolver;
use TYPO3\CMS\Backend\View\BackendLayout\Grid\GridColumnItem;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * A comparison view gap: an element (shadow) missing in a language. Returns its label, the
 * languages it exists in (site-language order) and whether it can be translated into the
 * language, i.e. the translation wizard has a source language for it (ElementTranslation).
 *
 * <f:variable name="gap" value="{sl:missingTranslation(item: gridItem, languageId: languageId)}" />
 */
#[Autoconfigure(public: true)]
final class MissingTranslationViewHelper extends AbstractViewHelper
{
    public function __construct(
        private readonly ElementTranslation $elementTranslation,
        private readonly ShadowLabelResolver $shadowLabelResolver,
    ) {}

    public function initializeArguments(): void
    {
        $this->registerArgument('item', GridColumnItem::class, 'Page module grid item of the shadow', true);
        $this->registerArgument('languageId', 'int', 'The language the element is missing in', true);
    }

    /**
     * @return array{label: string, languages: list<SiteLanguage>, translatable: bool}
     */
    public function render(): array
    {
        /** @var GridColumnItem $item */
        $item = $this->arguments['item'];
        $languageId = (int)$this->arguments['languageId'];
        $context = $item->getContext();
        $pageContext = $context->getPageContext();
        $backendUser = $context->getBackendUser();
        $shadowUid = $item->getRecord()->getUid();

        $existing = $this->elementTranslation->getLanguageIds($shadowUid, $pageContext->pageId, $backendUser->workspace);
        $languages = [];
        $siteLanguages = $pageContext->site instanceof Site ? $pageContext->site->getAllLanguages() : [];
        foreach ($siteLanguages as $siteLanguageId => $language) {
            if ($siteLanguageId > 0 && $siteLanguageId !== $languageId && in_array($siteLanguageId, $existing, true)) {
                $languages[] = $language;
            }
        }
        return [
            'label' => $this->shadowLabelResolver->resolve('tt_content', $shadowUid, $pageContext->pageId, null, $backendUser->workspace)['label'] ?? '',
            'languages' => $languages,
            'translatable' => $this->elementTranslation->getSourceLanguageIds($shadowUid, $languageId, $pageContext, $backendUser) !== [],
        ];
    }
}
