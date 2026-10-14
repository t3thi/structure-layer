<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Frontend;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendGroupRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Schema\Capability\LanguageAwareSchemaCapability;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Finds the translations of a shadow (the structure-layer record in language 0).
 *
 * "Visible" means visible for the given context (frontend restrictions), "existing" means
 * not deleted, regardless of hidden or the time window. A translation that exists but is
 * not visible is a disabled translation: intentional absence (plan §4 G).
 *
 * Workspaces are not handled (live records only, plan Phase 6).
 */
final readonly class TranslationLookup
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private TcaSchemaFactory $tcaSchemaFactory,
    ) {}

    /**
     * @param list<int> $languageIds
     * @return array<int, array<string, mixed>> visible translation rows by language id
     */
    public function findVisibleTranslations(string $table, int $shadowUid, array $languageIds, Context $context): array
    {
        $languageCapability = $this->getLanguageCapability($table);
        $languageField = $languageCapability->getLanguageField()->getName();
        $queryBuilder = $this->createQueryBuilder($table, $shadowUid, $languageIds);
        $queryBuilder->setRestrictions(new FrontendRestrictionContainer($context));
        $rows = [];
        foreach ($queryBuilder->select('*')->executeQuery()->fetchAllAssociative() as $row) {
            $rows[(int)$row[$languageField]] ??= $row;
        }
        return $rows;
    }

    public function translationExists(string $table, int $shadowUid, int $languageId): bool
    {
        $queryBuilder = $this->createQueryBuilder($table, $shadowUid, [$languageId]);
        $queryBuilder->getRestrictions()->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction(0));
        return $queryBuilder->count('uid')->executeQuery()->fetchOne() > 0;
    }

    /**
     * Pages whose translation in $languageId exists but is not visible for $context.
     *
     * @param list<int> $pageIds shadow page uids
     * @return list<int> shadow page uids
     */
    public function findPagesWithDisabledTranslation(array $pageIds, int $languageId, Context $context): array
    {
        if ($pageIds === []) {
            return [];
        }
        $transOrigPointerField = $this->getLanguageCapability('pages')->getTranslationOriginPointerField()->getName();

        $existing = $this->createQueryBuilder('pages', $pageIds, [$languageId]);
        $existing->getRestrictions()->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction(0));
        $existingIds = $existing->select($transOrigPointerField)->executeQuery()->fetchFirstColumn();
        if ($existingIds === []) {
            return [];
        }

        $visible = $this->createQueryBuilder('pages', $existingIds, [$languageId]);
        $visible->setRestrictions(new FrontendRestrictionContainer($context));
        // Same as PageRepository::getPageOverlaysForLanguage(): fe_group is synchronized, not checked on overlays.
        $visible->getRestrictions()->removeByType(FrontendGroupRestriction::class);
        $visibleIds = $visible->select($transOrigPointerField)->executeQuery()->fetchFirstColumn();

        return array_values(array_map(intval(...), array_diff($existingIds, $visibleIds)));
    }

    /**
     * @param int|list<int>|list<mixed> $shadowUids
     * @param list<int> $languageIds
     */
    private function createQueryBuilder(string $table, int|array $shadowUids, array $languageIds): QueryBuilder
    {
        $languageCapability = $this->getLanguageCapability($table);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->from($table)->where(
            $queryBuilder->expr()->in(
                $languageCapability->getTranslationOriginPointerField()->getName(),
                $queryBuilder->createNamedParameter(array_map(intval(...), (array)$shadowUids), Connection::PARAM_INT_ARRAY)
            ),
            $queryBuilder->expr()->in(
                $languageCapability->getLanguageField()->getName(),
                $queryBuilder->createNamedParameter($languageIds, Connection::PARAM_INT_ARRAY)
            ),
        );
        return $queryBuilder;
    }

    private function getLanguageCapability(string $table): LanguageAwareSchemaCapability
    {
        return $this->tcaSchemaFactory->get($table)->getCapability(TcaSchemaCapability::Language);
    }
}
