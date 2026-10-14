<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Integrity;

use T3thi\StructureLayer\StructureLayer;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Reports violations of the structure-layer invariants (plan §3.1) on live records, the
 * sorting alignment of component C, and the site configuration rules of component A.
 *
 * - I1: a shadow carries no payload (shadowContent "empty": the label field is empty)
 * - I2: every translation points to a not deleted shadow on the same page
 * - I3: at most one translation per shadow and language
 * - I4: no shadow without a translation
 * - I5: a shadow is never hidden; a shadow page's time window stays open
 * - C:  a translation's sorting equals its shadow's sorting
 * - A:  language 0 disabled with its own base, no 0 in fallbacks; fallbackType is ignored (notice)
 */
final readonly class InvariantChecker
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private TcaSchemaFactory $tcaSchemaFactory,
        private SiteFinder $siteFinder,
    ) {}

    /**
     * @return list<Violation>
     */
    public function check(?Site $site = null): array
    {
        $sites = $site !== null ? [$site] : array_values($this->siteFinder->getAllSites(false));
        $violations = [];
        foreach ($sites as $siteToCheck) {
            $violations = [...$violations, ...$this->checkSiteConfiguration($siteToCheck)];
        }
        $pageIds = $site !== null ? $this->fetchPageTree($site->getRootPageId()) : null;
        foreach (StructureLayer::SHADOWED_TABLES as $table) {
            $violations = [...$violations, ...$this->checkTable($this->tcaSchemaFactory->get($table), $pageIds)];
        }
        return $violations;
    }

    /**
     * @return list<Violation>
     */
    private function checkSiteConfiguration(Site $site): array
    {
        $violations = [];
        $prefix = 'site ' . $site->getIdentifier() . ': ';
        $languages = $site->getConfiguration()['languages'] ?? [];
        $bases = [];
        foreach ($languages as $language) {
            $bases[(int)($language['languageId'] ?? 0)] = rtrim((string)$site->getAllLanguages()[(int)($language['languageId'] ?? 0)]->getBase(), '/');
        }
        foreach ($languages as $language) {
            $languageId = (int)($language['languageId'] ?? 0);
            if ($languageId === 0) {
                if (($language['enabled'] ?? true) !== false) {
                    $violations[] = new Violation('A', $prefix . 'language 0 (structure) is enabled');
                }
                $otherBases = array_diff_key($bases, [0 => true]);
                if (in_array($bases[0], $otherBases, true)) {
                    $violations[] = new Violation('A', $prefix . 'language 0 (structure) shares its base "' . $bases[0] . '"');
                }
                continue;
            }
            $fallbacks = array_map(trim(...), explode(',', (string)($language['fallbacks'] ?? '')));
            if (in_array('0', $fallbacks, true)) {
                $violations[] = new Violation('A', $prefix . 'language ' . $languageId . ' has 0 in fallbacks');
            }
            if (($language['fallbackType'] ?? '') !== '') {
                $violations[] = new Violation('A', $prefix . 'language ' . $languageId . ' fallbackType "' . $language['fallbackType'] . '" is ignored', true);
            }
        }
        return $violations;
    }

    /**
     * @param list<int>|null $pageIds restrict to records on these pages (pages: these pages and their translations)
     * @return list<Violation>
     */
    private function checkTable(TcaSchema $schema, ?array $pageIds): array
    {
        $table = $schema->getName();
        $languageCapability = $schema->getCapability(TcaSchemaCapability::Language);
        $languageField = $languageCapability->getLanguageField()->getName();
        $parentField = $languageCapability->getTranslationOriginPointerField()->getName();
        $sortingField = $schema->hasCapability(TcaSchemaCapability::SortByField)
            ? $schema->getCapability(TcaSchemaCapability::SortByField)->getFieldName() : null;
        $hiddenField = $schema->hasCapability(TcaSchemaCapability::RestrictionDisabledField)
            ? $schema->getCapability(TcaSchemaCapability::RestrictionDisabledField)->getField()->getName() : null;
        $labelField = $schema->getCapability(TcaSchemaCapability::Label)->getPrimaryFieldName();

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->select('*')->from($table)->where($queryBuilder->expr()->eq('t3ver_wsid', 0));
        $rows = $queryBuilder->orderBy('uid')->executeQuery()->fetchAllAssociative();

        $shadows = [];
        $translations = [];
        foreach ($rows as $row) {
            $languageId = (int)$row[$languageField];
            if ($pageIds !== null && !$this->isInPageTree($table, $row, $parentField, $pageIds)) {
                continue;
            }
            if ($languageId === 0) {
                $shadows[(int)$row['uid']] = $row;
            } elseif ($languageId > 0 && !$row['deleted']) {
                $translations[] = $row;
            }
        }
        $allRows = array_column($rows, null, 'uid');

        $violations = [];
        $translationsByShadow = [];
        foreach ($translations as $translation) {
            $identifier = $table . ':' . $translation['uid'];
            $shadowUid = (int)$translation[$parentField];
            if ($shadowUid === 0) {
                $violations[] = new Violation('I2', $identifier . ' translation without shadow (' . $parentField . ' = 0)');
                continue;
            }
            $shadow = $allRows[$shadowUid] ?? null;
            if ($shadow === null || $shadow['deleted'] || (int)$shadow[$languageField] !== 0) {
                $violations[] = new Violation('I2', $identifier . ' shadow ' . $table . ':' . $shadowUid . ' does not exist or is deleted');
                continue;
            }
            if ((int)$shadow['pid'] !== (int)$translation['pid']) {
                $violations[] = new Violation('I2', $identifier . ' shadow ' . $table . ':' . $shadowUid . ' is on page ' . $shadow['pid'] . ', translation on page ' . $translation['pid']);
                continue;
            }
            $languageId = (int)$translation[$languageField];
            if (isset($translationsByShadow[$shadowUid][$languageId])) {
                $violations[] = new Violation('I3', $identifier . ' second translation of ' . $table . ':' . $shadowUid . ' in language ' . $languageId . ' (first: ' . $translationsByShadow[$shadowUid][$languageId] . ')');
                continue;
            }
            $translationsByShadow[$shadowUid][$languageId] = (int)$translation['uid'];
            if ($sortingField !== null && (int)$translation[$sortingField] !== (int)$shadow[$sortingField]) {
                $violations[] = new Violation('C', $identifier . ' ' . $sortingField . ' ' . $translation[$sortingField] . ' differs from shadow ' . $table . ':' . $shadowUid . ' (' . $shadow[$sortingField] . ')');
            }
        }

        foreach ($shadows as $shadowUid => $shadow) {
            if ($shadow['deleted']) {
                continue;
            }
            $identifier = $table . ':' . $shadowUid;
            if (!isset($translationsByShadow[$shadowUid])) {
                $violations[] = new Violation('I4', $identifier . ' shadow without translation');
            }
            if ($hiddenField !== null && $shadow[$hiddenField]) {
                $violations[] = new Violation('I5', $identifier . ' shadow is hidden');
            }
            if ($table === 'pages' && ($shadow['starttime'] || $shadow['endtime'])) {
                $violations[] = new Violation('I5', $identifier . ' shadow page has a time window');
            }
            if ($labelField !== null && (string)$shadow[$labelField] !== '') {
                $violations[] = new Violation('I1', $identifier . ' shadow carries payload in "' . $labelField . '"');
            }
        }
        return $violations;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<int> $pageIds
     */
    private function isInPageTree(string $table, array $row, string $parentField, array $pageIds): bool
    {
        if ($table === 'pages') {
            return in_array((int)$row['uid'], $pageIds, true) || in_array((int)$row[$parentField], $pageIds, true);
        }
        return in_array((int)$row['pid'], $pageIds, true);
    }

    /**
     * Uids of the not deleted shadow pages below (and including) the root page.
     *
     * @return list<int>
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
                $queryBuilder->expr()->eq('t3ver_wsid', 0),
            )->executeQuery()->fetchFirstColumn());
            $pageIds = [...$pageIds, ...$parentIds];
        }
        return $pageIds;
    }
}
