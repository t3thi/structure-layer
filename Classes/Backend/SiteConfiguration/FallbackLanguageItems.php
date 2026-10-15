<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Backend\SiteConfiguration;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\Configuration\TCA\ItemsProcessorFunctions;

/**
 * Fallback languages offered in the Sites module: Core's list without the structure layer (0).
 * Public: itemsProcFunc instantiates it via GeneralUtility::makeInstance().
 */
#[Autoconfigure(public: true)]
final readonly class FallbackLanguageItems
{
    public function __construct(
        private ItemsProcessorFunctions $itemsProcessorFunctions,
    ) {}

    /**
     * @param array<array-key, mixed> $fieldDefinition
     */
    public function populate(array &$fieldDefinition): void
    {
        $this->itemsProcessorFunctions->populateFallbackLanguages($fieldDefinition);
        $fieldDefinition['items'] = array_values(array_filter(
            $fieldDefinition['items'],
            static fn(array $item): bool => (int)$item['value'] !== 0
        ));
    }
}
