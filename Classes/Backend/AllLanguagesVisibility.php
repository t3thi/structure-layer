<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Backend;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * "Hide / show in all languages" (plan §4 H, decision 3) of a shadow. DataHandler writes "hidden"
 * sent for a shadow on every translation (DataHandlerHook), the shadow itself stays visible (I5).
 * "Hidden in all languages" is derived: every existing translation is hidden.
 */
final readonly class AllLanguagesVisibility
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private TcaSchemaFactory $tcaSchemaFactory,
    ) {}

    /**
     * @return array{field: string, hiddenEverywhere: bool}|null null if the table has no "hidden"
     */
    public function getState(string $table, int $shadowUid, int $workspaceId = 0): ?array
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return null;
        }
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

        return [
            'field' => $hiddenField,
            'hiddenEverywhere' => $hiddenStates !== [] && !in_array(0, array_map(intval(...), $hiddenStates), true),
        ];
    }
}
