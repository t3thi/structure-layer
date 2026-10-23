<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Migration;

use T3thi\StructureLayer\Integrity\InvariantChecker;
use T3thi\StructureLayer\StructureLayer;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\ReferenceIndex;
use TYPO3\CMS\Core\DataHandling\Localization\State;
use TYPO3\CMS\Core\DataHandling\TableColumnType;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\Field\FieldTranslationBehaviour;
use TYPO3\CMS\Core\Schema\Field\FieldTypeInterface;
use TYPO3\CMS\Core\Schema\LanguageMarker;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Migrates a classic site to the structure layer (plan §4 I). Existing language-0 rows stay and
 * become the shadows, so uids, t3://page links, MM relations and l10n_parent pointers stay valid.
 *
 * 1. Site configuration: the old default language becomes a regular language N; language 0 becomes
 *    the disabled structure layer; "0" in fallbacks becomes N; strict/free lose their chain (moved
 *    to "inactiveFallbacks", it never selected records there).
 * 2. Every language-0 record gets a language-N translation with its payload (row copy, not
 *    DataHandler::localize(): that copies only some page fields and prefixes labels). Its file
 *    references move along.
 * 3. Visibility becomes explicit: a hidden default record → visible shadow, hidden translations
 *    wherever the default record's visibility applied: pages in every language (the page lookup
 *    reads the default page), other records in strict and fallback languages (overlays of the
 *    default row), not in free ones. A default page's time window is intersected into its translations.
 * 4. Shadows lose their payload (shadowContent "empty"), page shadows get l18n_cfg bit 1;
 *    structural fields and sorting are mirrored to the translations (components §3.3, C).
 * 5. Free-Mode records get a shadow each; they are never matched automatically. Floating records
 *    were only ever shown in their own language; as positions of their own they would now be a
 *    fallback for every language with theirs in the chain. A disabled translation in those
 *    languages keeps them absent there (the model's only way to express absence, plan §4 G).
 * 6. "-1" records are left alone; workspace versions are not migrated (Phase 6).
 *
 * Records are written in one transaction, the site configuration afterwards. A dry run rolls the
 * transaction back and writes no site configuration.
 */
final readonly class Migrator
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private TcaSchemaFactory $tcaSchemaFactory,
        private SiteFinder $siteFinder,
        private SiteWriter $siteWriter,
        private ReferenceIndex $referenceIndex,
        private InvariantChecker $invariantChecker,
    ) {}

    public function migrate(Site $site, bool $dryRun = false): MigrationReport
    {
        $report = new MigrationReport();
        $report->dryRun = $dryRun;
        $configuration = $site->getConfiguration();
        if (($configuration['languages'][0]['languageId'] ?? null) === 0 && ($configuration['languages'][0]['enabled'] ?? true) === false) {
            throw new \RuntimeException('Site "' . $site->getIdentifier() . '" already has a disabled language 0 (structure layer)', 1792060000);
        }
        $report->siteConfiguration = $this->migrateSiteConfiguration($configuration, $report);

        $pageIds = $this->fetchPageTree($site->getRootPageId());
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $connection->beginTransaction();
        try {
            $touched = [];
            $chains = $this->getFallbackChains($report->siteConfiguration);
            $overlaidLanguages = $this->getOverlaidLanguageIds($configuration);
            foreach (StructureLayer::SHADOWED_TABLES as $table) {
                $touched[$table] = $this->migrateTable($this->tcaSchemaFactory->get($table), $pageIds, $report->newLanguageId, $chains, $overlaidLanguages, $report);
            }
            if ($dryRun) {
                $connection->rollBack();
                return $report;
            }
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
        foreach ($touched as $table => $uids) {
            foreach (array_keys($uids) as $uid) {
                $this->referenceIndex->updateRefIndexTable($table, $uid);
            }
        }
        $this->siteWriter->write($site->getIdentifier(), $report->siteConfiguration);
        foreach ($this->invariantChecker->check($this->siteFinder->getSiteByIdentifier($site->getIdentifier())) as $violation) {
            if ($violation->isNotice) {
                $report->siteChanges[] = (string)$violation;
            } else {
                $report->violations[] = (string)$violation;
            }
        }
        return $report;
    }

    /**
     * Step 1. Pure: returns the new configuration, records the changes in the report.
     *
     * @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    public function migrateSiteConfiguration(array $configuration, MigrationReport $report): array
    {
        $languages = $configuration['languages'] ?? [];
        $newLanguageId = max([0, ...array_map(static fn(array $language): int => (int)($language['languageId'] ?? 0), $languages)]) + 1;
        $report->newLanguageId = $newLanguageId;
        $migrated = [];
        $others = [];
        foreach ($languages as $language) {
            $languageId = (int)($language['languageId'] ?? 0);
            if ($languageId === 0) {
                $primary = $language;
                $primary['languageId'] = $newLanguageId;
                unset($primary['fallbackType'], $primary['fallbacks']);
                $migrated[] = [
                    'languageId' => 0,
                    'title' => 'Structure',
                    'enabled' => false,
                    'base' => '/_structure/',
                    'locale' => $language['locale'] ?? 'en_US.UTF-8',
                    'flag' => 'black',
                ];
                $migrated[] = $primary;
                $report->siteChanges[] = 'language 0 "' . ($language['title'] ?? '') . '" becomes language ' . $newLanguageId . ', language 0 becomes the structure layer';
                continue;
            }
            $fallbacks = array_values(array_filter(array_map(trim(...), explode(',', (string)($language['fallbacks'] ?? ''))), static fn(string $id): bool => $id !== ''));
            // SiteLanguage's default
            $fallbackType = (string)($language['fallbackType'] ?? 'strict');
            $prefix = 'language ' . $languageId . ' (' . $fallbackType . '): ';
            $newFallbacks = match ($fallbackType) {
                // Records fall back along the chain and finally always to the default record, "0" or not
                'fallback' => array_values(array_unique([...array_diff($fallbacks, ['0']), (string)$newLanguageId])),
                // Records fall back along the chain, but never to the default record: "0" only switched whole pages
                'strict' => array_values(array_diff($fallbacks, ['0'])),
                // Records never fall back: the chain only switched whole pages
                default => [],
            };
            $oldFallbacks = implode(',', $fallbacks);
            $language['fallbacks'] = implode(',', $newFallbacks);
            if ($oldFallbacks !== $language['fallbacks']) {
                $report->siteChanges[] = $prefix . 'fallbacks "' . $oldFallbacks . '" become "' . $language['fallbacks'] . '"';
            }
            $lost = array_values(array_diff(
                array_map(static fn(string $id): string => $id === '0' ? (string)$newLanguageId : $id, $fallbacks),
                $newFallbacks
            ));
            if ($lost !== []) {
                // A chain now also selects records, so page-only fallbacks cannot be kept (plan §4 G compatibility note)
                $language['inactiveFallbacks'] = implode(',', $lost);
                $report->siteChanges[] = $prefix . 'page fallback to "' . $language['inactiveFallbacks'] . '" is lost, kept as inactiveFallbacks';
            }
            $others[] = $language;
        }
        $configuration['languages'] = [...$migrated, ...$others];
        return $configuration;
    }

    /**
     * Languages whose records were overlays of the default records (fallbackType strict or
     * fallback), so the default record's visibility applied to them.
     *
     * @param array<string, mixed> $configuration the site configuration before the migration
     * @return list<int>
     */
    private function getOverlaidLanguageIds(array $configuration): array
    {
        $languageIds = [];
        foreach ($configuration['languages'] ?? [] as $language) {
            if ((int)($language['languageId'] ?? 0) > 0 && ($language['fallbackType'] ?? 'strict') !== 'free') {
                $languageIds[] = (int)$language['languageId'];
            }
        }
        return $languageIds;
    }

    /**
     * @param array<string, mixed> $configuration
     * @return array<int, list<int>> language id => fallback chain
     */
    private function getFallbackChains(array $configuration): array
    {
        $chains = [];
        foreach ($configuration['languages'] ?? [] as $language) {
            $fallbacks = array_filter(array_map(trim(...), explode(',', (string)($language['fallbacks'] ?? ''))), static fn(string $id): bool => $id !== '');
            if ((int)($language['languageId'] ?? 0) > 0 && $fallbacks !== []) {
                $chains[(int)$language['languageId']] = array_map(intval(...), array_values($fallbacks));
            }
        }
        return $chains;
    }

    /**
     * Steps 2–6 for one table.
     *
     * @param list<int> $pageIds
     * @param array<int, list<int>> $chains language id => fallback chain after the migration
     * @param list<int> $overlaidLanguages languages that rendered overlays of default records before
     * @return array<int, true> uids of written records
     */
    private function migrateTable(TcaSchema $schema, array $pageIds, int $newLanguageId, array $chains, array $overlaidLanguages, MigrationReport $report): array
    {
        $table = $schema->getName();
        $languageCapability = $schema->getCapability(TcaSchemaCapability::Language);
        $languageField = $languageCapability->getLanguageField()->getName();
        $parentField = $languageCapability->getTranslationOriginPointerField()->getName();
        $sourceField = $languageCapability->getTranslationSourceField()?->getName();
        $diffSourceField = $languageCapability->getDiffSourceField()?->getName();
        $hiddenField = $schema->hasCapability(TcaSchemaCapability::RestrictionDisabledField)
            ? $schema->getCapability(TcaSchemaCapability::RestrictionDisabledField)->getField()->getName() : null;
        $sortingField = $schema->hasCapability(TcaSchemaCapability::SortByField)
            ? $schema->getCapability(TcaSchemaCapability::SortByField)->getFieldName() : null;
        $connection = $this->connectionPool->getConnectionForTable($table);

        $rows = $this->fetchRows($table, $pageIds, $parentField, $report);
        $report->count($table, 'rowsBefore', count($rows));
        $shadows = [];
        $translations = [];
        $floating = [];
        foreach ($rows as $uid => $row) {
            $languageId = (int)$row[$languageField];
            if ($languageId === LanguageMarker::ALL_LANGUAGES) {
                $report->count($table, 'allLanguagesRecordsUntouched');
            } elseif ($languageId === 0) {
                $shadows[$uid] = $row;
            } elseif (isset($rows[(int)$row[$parentField]]) && (int)$rows[(int)$row[$parentField]][$languageField] === 0) {
                $translations[(int)$row[$parentField]][$uid] = $row;
            } else {
                $floating[$uid] = $row;
            }
        }
        $touched = [];

        // Step 2 + 3: the old default record becomes a translation in language N, the row itself the shadow
        foreach ($shadows as $shadowUid => $shadow) {
            $translation = $shadow;
            unset($translation['uid']);
            $translation[$languageField] = $newLanguageId;
            $translation[$parentField] = $shadowUid;
            if ($sourceField !== null) {
                $translation[$sourceField] = 0;
            }
            if ($diffSourceField !== null) {
                $translation[$diffSourceField] = '';
            }
            $connection->insert($table, $translation);
            $translationUid = (int)$connection->lastInsertId();
            $report->count($table, 'translationsCreated');
            $touched[$translationUid] = true;
            $touched[$shadowUid] = true;
            $this->moveFileReferences($schema, $shadowUid, $translationUid, $newLanguageId, $report);
            $translations[$shadowUid][$translationUid] = ['uid' => $translationUid] + $translation;

            $shadowUpdate = [];
            if ($hiddenField !== null && $shadow[$hiddenField]) {
                $shadowUpdate[$hiddenField] = 0;
                $report->count($table, 'hiddenDefaultRecords');
                foreach ($translations[$shadowUid] as $uid => $row) {
                    if (!$row[$hiddenField] && ($table === 'pages' || in_array((int)$row[$languageField], $overlaidLanguages, true))) {
                        $connection->update($table, [$hiddenField => 1], ['uid' => $uid]);
                        $translations[$shadowUid][$uid][$hiddenField] = 1;
                        $report->count($table, 'translationsHiddenByParent');
                    }
                }
            }
            if ($table === 'pages' && ($shadow['starttime'] || $shadow['endtime'])) {
                $shadowUpdate['starttime'] = 0;
                $shadowUpdate['endtime'] = 0;
                foreach ($translations[$shadowUid] as $uid => $row) {
                    $window = [
                        'starttime' => max((int)$row['starttime'], (int)$shadow['starttime']),
                        'endtime' => min(array_filter([(int)$row['endtime'], (int)$shadow['endtime']]) ?: [0]),
                    ];
                    if ($window['starttime'] !== (int)$row['starttime'] || $window['endtime'] !== (int)$row['endtime']) {
                        $connection->update($table, $window, ['uid' => $uid]);
                        $translations[$shadowUid][$uid] = $window + $translations[$shadowUid][$uid];
                        $report->count($table, 'timeWindowsIntersected');
                    }
                }
            }
            $shadowUpdate += $this->emptyPayload($schema, $shadow);
            $connection->update($table, $shadowUpdate, ['uid' => $shadowUid]);
            $shadows[$shadowUid] = $shadowUpdate + $shadow;
        }

        // Step 5: Free-Mode and orphaned records get their own shadow at their position
        foreach ($floating as $uid => $row) {
            $orphan = (int)$row[$parentField] !== 0;
            $shadow = $row;
            unset($shadow['uid']);
            $shadow[$languageField] = 0;
            $shadow[$parentField] = 0;
            if ($sourceField !== null) {
                $shadow[$sourceField] = 0;
            }
            if ($diffSourceField !== null) {
                $shadow[$diffSourceField] = '';
            }
            if ($hiddenField !== null) {
                $shadow[$hiddenField] = 0;
            }
            if ($table === 'pages') {
                $shadow['starttime'] = 0;
                $shadow['endtime'] = 0;
                $shadow['l10n_state'] = null;
            }
            foreach ($this->getFileFieldNames($schema) as $fieldName) {
                $shadow[$fieldName] = 0;
            }
            $shadow = $this->emptyPayload($schema, $shadow) + $shadow;
            $connection->insert($table, $shadow);
            $shadowUid = (int)$connection->lastInsertId();
            $connection->update($table, [$parentField => $shadowUid], ['uid' => $uid]);
            $report->count($table, $orphan ? 'orphanedTranslationShadowsCreated' : 'freeModeShadowsCreated');
            $touched[$shadowUid] = true;
            $touched[$uid] = true;
            $shadows[$shadowUid] = ['uid' => $shadowUid] + $shadow;
            $translations[$shadowUid][$uid] = [$parentField => $shadowUid] + $row;
            foreach ($chains as $languageId => $chain) {
                if (!in_array((int)$row[$languageField], $chain, true)) {
                    continue;
                }
                $marker = $row;
                unset($marker['uid']);
                $marker[$languageField] = $languageId;
                $marker[$parentField] = $shadowUid;
                if ($sourceField !== null) {
                    $marker[$sourceField] = $uid;
                }
                if ($hiddenField !== null) {
                    $marker[$hiddenField] = 1;
                }
                foreach ($this->getFileFieldNames($schema) as $fieldName) {
                    $marker[$fieldName] = 0;
                }
                $connection->insert($table, $marker);
                $markerUid = (int)$connection->lastInsertId();
                $touched[$markerUid] = true;
                $translations[$shadowUid][$markerUid] = ['uid' => $markerUid] + $marker;
                $report->count($table, 'absenceMarkersCreated');
            }
            $source = $sourceField !== null ? (int)$row[$sourceField] : 0;
            if ($source > 0) {
                $report->connectionCandidates[] = $table . ':' . $uid . ' (language ' . $row[$languageField] . ', '
                    . ($orphan ? 'orphaned' : 'Free Mode') . ') was copied from ' . $table . ':' . $source . ', now has its own shadow ' . $table . ':' . $shadowUid;
            }
        }

        // Step 4: structural fields and sorting live on the shadow, translations mirror them
        $structuralFields = $this->getMirroredFieldNames($schema);
        foreach ($shadows as $shadowUid => $shadow) {
            foreach ($translations[$shadowUid] ?? [] as $uid => $row) {
                $update = [];
                foreach ($structuralFields as $fieldName) {
                    if ((string)$row[$fieldName] !== (string)$shadow[$fieldName]) {
                        $update[$fieldName] = $shadow[$fieldName];
                        $report->count($table, 'structuralValuesAligned.' . $fieldName);
                        if ($fieldName === 'fe_group' && (string)$row[$fieldName] !== '' && (string)$row[$fieldName] !== '0') {
                            $report->conflicts[] = $table . ':' . $uid . ' fe_group "' . $row[$fieldName] . '" replaced by the shadow\'s "' . $shadow[$fieldName] . '"';
                        }
                    }
                }
                if ($sortingField !== null && (int)$row[$sortingField] !== (int)$shadow[$sortingField]) {
                    $update[$sortingField] = $shadow[$sortingField];
                    $report->count($table, 'sortingAligned');
                }
                if ($table === 'pages') {
                    $update['l10n_state'] = $this->customState($table, $row['l10n_state'] ?? null);
                }
                if ($update !== []) {
                    $connection->update($table, $update, ['uid' => $uid]);
                    $touched[$uid] = true;
                }
            }
        }
        $report->count($table, 'shadows', count($shadows));
        $report->count($table, 'rowsAfter', count($this->fetchRows($table, $pageIds, $parentField, new MigrationReport())));
        return $touched;
    }

    /**
     * The shadow's payload (shadowContent "empty", plan §3.2): text-like fields that are not
     * structural are emptied, file fields lose their references (moved to the translation).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function emptyPayload(TcaSchema $schema, array $row): array
    {
        $update = [];
        foreach ($schema->getFields() as $field) {
            $fieldName = $field->getName();
            if (!array_key_exists($fieldName, $row) || $field->getTranslationBehaviour() === FieldTranslationBehaviour::Excluded) {
                continue;
            }
            if ($field->isType(TableColumnType::INPUT, TableColumnType::TEXT, TableColumnType::LINK, TableColumnType::EMAIL) && (string)$row[$fieldName] !== '') {
                $update[$fieldName] = '';
            } elseif ($field->isType(TableColumnType::FILE) && (int)$row[$fieldName] !== 0) {
                $update[$fieldName] = 0;
            }
        }
        if ($schema->getName() === 'pages') {
            // PageTranslationVisibility bit 1: never output the structure language
            $update['l18n_cfg'] = (int)$row['l18n_cfg'] | 1;
        }
        return $update;
    }

    private function moveFileReferences(TcaSchema $schema, int $shadowUid, int $translationUid, int $languageId, MigrationReport $report): void
    {
        $fileFields = $this->getFileFieldNames($schema);
        if ($fileFields === []) {
            return;
        }
        $moved = $this->connectionPool->getConnectionForTable('sys_file_reference')->update(
            'sys_file_reference',
            ['uid_foreign' => $translationUid, 'sys_language_uid' => $languageId, 'l10n_parent' => 0],
            ['tablenames' => $schema->getName(), 'uid_foreign' => $shadowUid, 'sys_language_uid' => 0, 'deleted' => 0, 't3ver_wsid' => 0]
        );
        $report->count($schema->getName(), 'fileReferencesMoved', $moved);
    }

    /**
     * @return list<string>
     */
    private function getFileFieldNames(TcaSchema $schema): array
    {
        return array_map(strval(...), array_keys(iterator_to_array($schema->getFields(
            static fn(FieldTypeInterface $field): bool => $field->isType(TableColumnType::FILE)
        ))));
    }

    /**
     * Structural fields (l10n_mode=exclude) are DataHandler-synchronized from the shadow; after the
     * migration they must already match. Visibility stays per language (I5).
     *
     * @return list<string>
     */
    private function getMirroredFieldNames(TcaSchema $schema): array
    {
        $languageCapability = $schema->getCapability(TcaSchemaCapability::Language);
        $notMirrored = array_filter([
            'hidden',
            $languageCapability->getLanguageField()->getName(),
            $languageCapability->getTranslationOriginPointerField()->getName(),
            $languageCapability->getTranslationSourceField()?->getName(),
            $languageCapability->getDiffSourceField()?->getName(),
        ]);
        if ($schema->getName() === 'pages') {
            $notMirrored = [...$notMirrored, 'starttime', 'endtime'];
        }
        $fieldNames = array_map(strval(...), array_keys(iterator_to_array($schema->getFields(
            static fn(FieldTypeInterface $field): bool => $field->getTranslationBehaviour() === FieldTranslationBehaviour::Excluded
                && !$field->isType(TableColumnType::FILE, TableColumnType::INLINE)
        ))));
        return array_values(array_diff($fieldNames, $notMirrored));
    }

    private function customState(string $table, mixed $state): string
    {
        $state = json_decode((string)$state, true);
        return (string)json_encode(array_replace(
            is_array($state) ? $state : [],
            array_fill_keys(State::getFieldNames($table), State::STATE_CUSTOM)
        ));
    }

    /**
     * Live rows of the site; workspace versions are counted and skipped (Phase 6).
     *
     * @param list<int> $pageIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchRows(string $table, array $pageIds, string $parentField, MigrationReport $report): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $pageConstraint = $table === 'pages'
            ? $queryBuilder->expr()->or(
                $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($pageIds, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->in($parentField, $queryBuilder->createNamedParameter($pageIds, Connection::PARAM_INT_ARRAY)),
            )
            : $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($pageIds, Connection::PARAM_INT_ARRAY));
        $rows = $queryBuilder->select('*')->from($table)->where(
            $pageConstraint,
            $queryBuilder->expr()->eq('deleted', 0),
        )->orderBy('uid')->executeQuery()->fetchAllAssociative();
        $live = [];
        foreach ($rows as $row) {
            if ((int)($row['t3ver_wsid'] ?? 0) !== 0) {
                $report->count($table, 'workspaceVersionsSkipped');
                continue;
            }
            $live[(int)$row['uid']] = $row;
        }
        return $live;
    }

    /**
     * @return list<int> not deleted language-0 pages of the site, including the root page
     */
    private function fetchPageTree(int $rootPageId): array
    {
        $pageIds = [$rootPageId];
        $parentIds = [$rootPageId];
        while ($parentIds !== []) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()->removeAll();
            $parentIds = array_map(intval(...), $queryBuilder->select('uid')->from('pages')->where(
                $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($parentIds, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('sys_language_uid', 0),
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->eq('t3ver_wsid', 0),
            )->executeQuery()->fetchFirstColumn());
            $pageIds = [...$pageIds, ...$parentIds];
        }
        return $pageIds;
    }
}
