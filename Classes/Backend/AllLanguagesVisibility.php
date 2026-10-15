<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Backend;

use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * "Hide / show in all languages" (plan §4 H, decision 3): a toggle for a shadow. DataHandler
 * writes "hidden" sent for a shadow on every translation (DataHandlerHook), the shadow itself
 * stays visible (I5). "Hidden in all languages" is derived: every existing translation is hidden.
 */
final readonly class AllLanguagesVisibility
{
    private const string LABELS = 'LLL:EXT:structure_layer/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private ConnectionPool $connectionPool,
        private TcaSchemaFactory $tcaSchemaFactory,
        private UriBuilder $uriBuilder,
    ) {}

    /**
     * @return array{hiddenEverywhere: bool, url: string, title: string, icon: string}|null null if the table has no "hidden"
     */
    public function getToggle(string $table, int $shadowUid, string $returnUrl, int $workspaceId = 0): ?array
    {
        $schema = $this->tcaSchemaFactory->get($table);
        if (!$schema->hasCapability(TcaSchemaCapability::RestrictionDisabledField) || !$schema->isLanguageAware()) {
            return null;
        }
        $hiddenField = $schema->getCapability(TcaSchemaCapability::RestrictionDisabledField)->getField()->getName();
        $languageCapability = $schema->getCapability(TcaSchemaCapability::Language);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($workspaceId));
        $hiddenStates = $queryBuilder->select($hiddenField)->from($table)->where(
            $queryBuilder->expr()->eq(
                $languageCapability->getTranslationOriginPointerField()->getName(),
                $queryBuilder->createNamedParameter($shadowUid, Connection::PARAM_INT)
            ),
            $queryBuilder->expr()->gt($languageCapability->getLanguageField()->getName(), 0),
        )->executeQuery()->fetchFirstColumn();
        $hiddenEverywhere = $hiddenStates !== [] && !in_array(0, array_map(intval(...), $hiddenStates), true);

        $languageService = $this->getLanguageService();
        return [
            'hiddenEverywhere' => $hiddenEverywhere,
            'url' => (string)$this->uriBuilder->buildUriFromRoute('tce_db', [
                'data' => [$table => [$shadowUid => [$hiddenField => $hiddenEverywhere ? '0' : '1']]],
                'redirect' => $returnUrl,
            ]),
            'title' => $languageService?->sL(self::LABELS . ($hiddenEverywhere ? 'allLanguages.show' : 'allLanguages.hide')) ?? '',
            'icon' => $hiddenEverywhere ? 'actions-edit-unhide' : 'actions-edit-hide',
        ];
    }

    private function getLanguageService(): ?LanguageService
    {
        return $GLOBALS['LANG'] ?? null;
    }
}
