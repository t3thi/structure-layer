<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\DataHandling;

use T3thi\StructureLayer\Backend\EditingLanguage;
use T3thi\StructureLayer\Backend\ShadowLabelResolver;
use T3thi\StructureLayer\StructureLayer;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\DataHandling\Localization\State;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Schema\Capability\LanguageAwareSchemaCapability;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\Field\FieldTranslationBehaviour;
use TYPO3\CMS\Core\Schema\Field\FieldTypeInterface;
use TYPO3\CMS\Core\Schema\LanguageMarker;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\SysLog\Action\Database as SystemLogDatabaseAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * DataHandler side of the structure layer (plan §4 B–E, H): keeps every record connected.
 *
 * Datamap (outermost DataHandler only; nested instances are DataHandler's own copy/localize runs):
 * - B: a new record in a language > 0 without parent gets a NEW shadow in front of it;
 *      a new record in language 0 becomes shadow + translation in the primary language.
 * - §3.3: structural fields (l10n_mode=exclude) submitted for a translation are written to its shadow.
 * - H: "hidden" (pages: also the time window) written on a shadow is written on every translation.
 * - I1: a label written on a shadow (page tree inline edit) is written on the translation the
 *      backend shows for it: the Editing Language, otherwise the first available translation.
 * - §3.3: page translations keep their synchronizable fields ("custom" l10n_state).
 *
 * Cmdmap (outermost only):
 * - D: move/copy of a translation is a move/copy of its shadow, relative to the target's shadow;
 *      a language change in the paste update is dropped.
 * - E: deleting the last translation deletes the shadow; restoring a translation restores its
 *      shadow; restoring a shadow restores the translations deleted together with it.
 *
 * C: after every outermost run, translations get the sorting of their shadow.
 *
 * Phase 5, permissions (StructureAuthority): a language-restricted editor's requests for languages
 * they may not edit are dropped; structure operations (move, copy, structural fields, hide/delete/
 * restore in all languages) need the structure permission. The outermost run may then write the
 * structure layer (content operations) or every language (structure operations).
 */
final class DataHandlerHook
{
    /** @var array<string, array<int, true>> table => pid => true, pending sorting alignment */
    private array $pagesToAlign = [];

    /** @var array<string, int> "table:uid" => tstamp of a deleted shadow before it is restored */
    private array $restoredShadowTimestamps = [];

    /** The outermost run contains a permitted structure operation */
    private bool $structureOperation = false;

    public function __construct(
        private readonly StructureLayer $structureLayer,
        private readonly SiteFinder $siteFinder,
        private readonly ConnectionPool $connectionPool,
        private readonly TcaSchemaFactory $tcaSchemaFactory,
        private readonly EditingLanguage $editingLanguage,
        private readonly ShadowLabelResolver $shadowLabelResolver,
        private readonly StructureAuthority $structureAuthority,
    ) {}

    public function processDatamap_beforeStart(DataHandler $dataHandler): void
    {
        if (!$dataHandler->isOuterMostInstance()) {
            return;
        }
        $this->structureOperation = false;
        $datamap = $dataHandler->datamap;
        foreach (StructureLayer::SHADOWED_TABLES as $table) {
            if (!isset($datamap[$table])) {
                continue;
            }
            $datamap[$table] = $this->rewriteTableDatamap($table, $datamap[$table], $dataHandler);
        }
        $dataHandler->datamap = $datamap;
        $this->structureAuthority->grant($dataHandler->BE_USER, $this->structureOperation);
    }

    /**
     * @param int|string $id
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_afterDatabaseOperations(string $status, string $table, $id, array $fieldArray, DataHandler $dataHandler): void
    {
        if (!in_array($table, StructureLayer::SHADOWED_TABLES, true)) {
            return;
        }
        $uid = MathUtility::canBeInterpretedAsInteger($id) ? (int)$id : (int)($dataHandler->substNEWwithIDs[$id] ?? 0);
        $this->registerForAlignment($table, $uid);
    }

    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        if ($dataHandler->isOuterMostInstance()) {
            $this->alignSorting();
            $this->structureAuthority->withdraw($dataHandler->BE_USER);
        }
    }

    public function processCmdmap_beforeStart(DataHandler $dataHandler): void
    {
        if (!$dataHandler->isOuterMostInstance()) {
            return;
        }
        $this->structureOperation = false;
        $cmdmap = $dataHandler->cmdmap;
        foreach (StructureLayer::SHADOWED_TABLES as $table) {
            if (!isset($cmdmap[$table])) {
                continue;
            }
            $cmdmap[$table] = $this->rewriteTableCmdmap($table, $cmdmap[$table], $dataHandler);
        }
        $dataHandler->cmdmap = $cmdmap;
        $this->structureAuthority->grant($dataHandler->BE_USER, $this->structureOperation);
    }

    /**
     * @param int|string $id
     * @param mixed $value
     * @param array<string, mixed>|false $pasteUpdate
     */
    public function processCmdmap_preProcess(string $command, string $table, $id, $value, DataHandler $dataHandler, $pasteUpdate): void
    {
        if ($command !== 'undelete' || !in_array($table, StructureLayer::SHADOWED_TABLES, true)) {
            return;
        }
        $record = BackendUtility::getRecord($table, (int)$id, '*', '', false);
        if ($record !== null && (int)$record[$this->getLanguageCapability($table)->getLanguageField()->getName()] === 0 && $record['deleted']) {
            $this->restoredShadowTimestamps[$table . ':' . (int)$id] = (int)$record['tstamp'];
        }
    }

    /**
     * @param int|string $id
     * @param mixed $value
     * @param array<string, mixed>|false $pasteUpdate
     * @param array<string, mixed> $pasteDatamap
     */
    public function processCmdmap_postProcess(string $command, string $table, $id, $value, DataHandler $dataHandler, $pasteUpdate, $pasteDatamap): void
    {
        foreach ($dataHandler->copyMappingArray as $copiedTable => $mapping) {
            foreach ($mapping as $newUid) {
                $this->registerForAlignment((string)$copiedTable, (int)$newUid);
            }
        }
        if (!in_array($table, StructureLayer::SHADOWED_TABLES, true)) {
            return;
        }
        $uid = (int)$id;
        $this->registerForAlignment($table, $uid);
        $record = BackendUtility::getRecord($table, $uid, '*', '', false);
        if ($record === null) {
            return;
        }
        $languageCapability = $this->getLanguageCapability($table);
        $languageId = (int)$record[$languageCapability->getLanguageField()->getName()];
        $shadowUid = (int)$record[$languageCapability->getTranslationOriginPointerField()->getName()];
        if ($command === 'copy' && $languageId === 0 && isset($dataHandler->copyMappingArray[$table][$uid])) {
            $this->repairCopiedShadow($table, (int)$dataHandler->copyMappingArray[$table][$uid]);
        }
        if ($command === 'localize' && $table === 'pages' && isset($dataHandler->copyMappingArray[$table][$uid])) {
            $this->markSynchronizableFieldsCustom($table, (int)$dataHandler->copyMappingArray[$table][$uid]);
        }
        if ($command === 'delete' && $languageId > 0 && $shadowUid > 0 && $this->fetchTranslationUids($table, $shadowUid) === []) {
            // I4: no shadow without a translation
            $this->runNestedCmdmap($dataHandler, [$table => [$shadowUid => ['delete' => 1]]]);
        }
        if ($command === 'undelete' && $languageId > 0 && $shadowUid > 0) {
            $shadow = BackendUtility::getRecord($table, $shadowUid, 'deleted', '', false);
            if ($shadow !== null && $shadow['deleted']) {
                $this->runNestedCmdmap($dataHandler, [$table => [$shadowUid => ['undelete' => 1]]]);
            }
        }
        if ($command === 'undelete' && $languageId === 0 && isset($this->restoredShadowTimestamps[$table . ':' . $uid])) {
            $deletedAt = $this->restoredShadowTimestamps[$table . ':' . $uid];
            unset($this->restoredShadowTimestamps[$table . ':' . $uid]);
            $restore = [];
            foreach ($this->fetchTranslationUids($table, $uid, $deletedAt) as $translationUid) {
                $restore[$table][$translationUid] = ['undelete' => 1];
            }
            if ($restore !== []) {
                $this->runNestedCmdmap($dataHandler, $restore);
            }
        }
    }

    public function processCmdmap_afterFinish(DataHandler $dataHandler): void
    {
        if ($dataHandler->isOuterMostInstance()) {
            $this->alignSorting();
            $this->structureAuthority->withdraw($dataHandler->BE_USER);
        }
    }

    /**
     * @param array<int|string, mixed> $records
     * @return array<int|string, mixed>
     */
    private function rewriteTableDatamap(string $table, array $records, DataHandler $dataHandler): array
    {
        $languageCapability = $this->getLanguageCapability($table);
        $languageField = $languageCapability->getLanguageField()->getName();
        $parentField = $languageCapability->getTranslationOriginPointerField()->getName();
        $structuralFields = $this->getStructuralFieldNames($table);

        $rewritten = [];
        foreach ($records as $id => $fields) {
            if (!is_array($fields)) {
                $rewritten[$id] = $fields;
                continue;
            }
            if (MathUtility::canBeInterpretedAsInteger($id)) {
                $this->rewriteUpdate($table, (int)$id, $fields, $rewritten, $dataHandler);
                continue;
            }
            $languageId = (int)($fields[$languageField] ?? 0);
            if ($languageId > 0 && !$this->mayEditLanguage($dataHandler, $table, $id, $languageId)) {
                // Rejected as a whole: its shadow alone would violate I4
                continue;
            }
            if ($languageId === LanguageMarker::ALL_LANGUAGES || ($languageId > 0 && !empty($fields[$parentField]))) {
                $rewritten[$id] = $fields;
                continue;
            }
            // Payload goes to the translation, structural fields to the shadow
            $shadow = ['pid' => $fields['pid'] ?? 0, $languageField => 0];
            $translation = $fields;
            foreach ($structuralFields as $fieldName) {
                if (array_key_exists($fieldName, $translation)) {
                    $shadow[$fieldName] = $translation[$fieldName];
                    unset($translation[$fieldName]);
                }
            }
            $shadow += $this->getShadowDefaults($table);
            if ($languageId > 0) {
                // no "_" in NEW ids: DataHandler::processRemapStack() reads "a_NEWb" as table "a" + id "NEWb"
                $shadowId = $id . 'shadow';
                $translationId = $id;
                $shadow['pid'] = $this->resolveShadowPid($table, $fields['pid'] ?? 0);
            } else {
                $primaryLanguageId = $this->resolveTranslationLanguageId($table, $fields['pid'] ?? 0, $dataHandler);
                if ($primaryLanguageId === null) {
                    $rewritten[$id] = $fields;
                    continue;
                }
                $shadowId = $id;
                $translationId = $id . 'translation';
                $translation[$languageField] = $primaryLanguageId;
            }
            $translation[$parentField] = $shadowId;
            if ($table === 'pages') {
                $translation['l10n_state'] = array_fill_keys(State::getFieldNames($table), State::STATE_CUSTOM);
            }
            $rewritten[$shadowId] = $shadow;
            $rewritten[$translationId] = $translation;
        }
        return $rewritten;
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<int|string, mixed> $rewritten
     */
    private function rewriteUpdate(string $table, int $uid, array $fields, array &$rewritten, DataHandler $dataHandler): void
    {
        $languageCapability = $this->getLanguageCapability($table);
        $languageField = $languageCapability->getLanguageField()->getName();
        $parentField = $languageCapability->getTranslationOriginPointerField()->getName();
        $record = BackendUtility::getRecord($table, $uid, 'pid,' . $languageField . ',' . $parentField);
        if ($record === null) {
            $rewritten[$uid] = $fields;
            return;
        }
        $languageId = (int)$record[$languageField];
        $shadowUid = (int)$record[$parentField];

        if ($languageId > 0 && !$this->mayEditLanguage($dataHandler, $table, $uid, $languageId)) {
            return;
        }
        if ($languageId > 0 && $shadowUid > 0) {
            if ($table === 'pages') {
                // Without l10n_state, DataMapProcessor treats synchronizable fields as "parent" and drops their values
                $this->markSynchronizableFieldsCustom($table, $uid);
            }
            // Structural fields edited from a translation are written to the shadow and synchronized to all languages
            $shadowFields = [];
            foreach ($this->getStructuralFieldNames($table) as $fieldName) {
                if (array_key_exists($fieldName, $fields)) {
                    $shadowFields[$fieldName] = $fields[$fieldName];
                    unset($fields[$fieldName]);
                }
            }
            if ($shadowFields !== [] && $this->mayChangeStructure($dataHandler, $table, $uid, 'change ' . implode(', ', array_keys($shadowFields)) . ' of')) {
                $rewritten[$shadowUid] = array_replace($shadowFields, is_array($rewritten[$shadowUid] ?? null) ? $rewritten[$shadowUid] : []);
                $this->prepareShadowUpdate($table, $shadowUid);
            }
            $rewritten[$uid] = array_replace(is_array($rewritten[$uid] ?? null) ? $rewritten[$uid] : [], $fields);
            return;
        }

        if ($languageId === 0) {
            // A shadow carries no label (I1): it belongs to the translation the backend shows for the shadow
            $labelFields = array_intersect_key($fields, array_flip($this->getLabelFieldNames($table)));
            $labelTranslationUid = $labelFields !== [] ? $this->resolveLabelTranslationUid($table, $uid, $table === 'pages' ? $uid : (int)$record['pid'], $dataHandler) : null;
            if ($labelTranslationUid !== null) {
                $rewritten[$labelTranslationUid] = array_replace($labelFields, is_array($rewritten[$labelTranslationUid] ?? null) ? $rewritten[$labelTranslationUid] : []);
                $fields = array_diff_key($fields, $labelFields);
                if ($table === 'pages') {
                    $this->markSynchronizableFieldsCustom($table, $labelTranslationUid);
                }
            }
            // Visibility on a shadow means "in all languages" (I5: the shadow itself is never hidden)
            $visibilityFields = array_intersect_key($fields, array_flip($this->getAllLanguagesVisibilityFieldNames($table)));
            if ($visibilityFields !== [] && !$this->mayChangeStructure($dataHandler, $table, $uid, 'change the visibility in all languages of')) {
                $fields = array_diff_key($fields, $visibilityFields);
                $visibilityFields = [];
            }
            if ($visibilityFields !== []) {
                foreach ($this->fetchTranslationUids($table, $uid) as $translationUid) {
                    $rewritten[$translationUid] = array_replace($visibilityFields, is_array($rewritten[$translationUid] ?? null) ? $rewritten[$translationUid] : []);
                }
                $fields = array_diff_key($fields, $visibilityFields);
            }
            if ($fields !== [] && $this->mayChangeStructure($dataHandler, $table, $uid, 'change ' . implode(', ', array_keys($fields)) . ' of')) {
                $this->prepareShadowUpdate($table, $uid);
                $rewritten[$uid] = array_replace(is_array($rewritten[$uid] ?? null) ? $rewritten[$uid] : [], $fields);
            }
            return;
        }
        $rewritten[$uid] = $fields;
    }

    /**
     * A shadow is about to be updated. DataMapProcessor then pushes values to its translations:
     * l10n_mode=exclude fields always, synchronizable fields unless l10n_state says "custom" – and
     * a missing l10n_state means "parent". The empty shadow would blank them, so make them custom.
     */
    private function prepareShadowUpdate(string $table, int $shadowUid): void
    {
        if ($table !== 'pages') {
            return;
        }
        foreach ($this->fetchTranslationUids($table, $shadowUid) as $translationUid) {
            $this->markSynchronizableFieldsCustom($table, $translationUid);
        }
    }

    private function markSynchronizableFieldsCustom(string $table, int $uid): void
    {
        $record = BackendUtility::getRecord($table, $uid, 'l10n_state', '', false);
        $state = json_decode((string)($record['l10n_state'] ?? ''), true);
        $state = is_array($state) ? $state : [];
        $customState = array_replace($state, array_fill_keys(State::getFieldNames($table), State::STATE_CUSTOM));
        if ($customState !== $state) {
            $this->connectionPool->getConnectionForTable($table)
                ->update($table, ['l10n_state' => json_encode($customState)], ['uid' => $uid]);
        }
    }

    /**
     * copyRecord() applies "hideAtCopy" and "prependAtCopy" to the first copied record, the shadow.
     * A shadow is never hidden (I5) and carries no payload (I1): hide the copied translations instead.
     * The label prefix ("(copy 1)") is dropped; translations are copied without it.
     */
    private function repairCopiedShadow(string $table, int $shadowUid): void
    {
        $schema = $this->tcaSchemaFactory->get($table);
        $shadow = BackendUtility::getRecord($table, $shadowUid, '*', '', false);
        if ($shadow === null) {
            return;
        }
        $connection = $this->connectionPool->getConnectionForTable($table);
        $labelField = $schema->getCapability(TcaSchemaCapability::Label)->getPrimaryFieldName();
        if ($labelField !== null && (string)$shadow[$labelField] !== '') {
            $connection->update($table, [$labelField => ''], ['uid' => $shadowUid]);
        }
        if (!$schema->hasCapability(TcaSchemaCapability::RestrictionDisabledField)) {
            return;
        }
        $hiddenField = $schema->getCapability(TcaSchemaCapability::RestrictionDisabledField)->getField()->getName();
        if ($shadow[$hiddenField]) {
            $connection->update($table, [$hiddenField => 0], ['uid' => $shadowUid]);
            foreach ($this->fetchTranslationUids($table, $shadowUid) as $translationUid) {
                $connection->update($table, [$hiddenField => 1], ['uid' => $translationUid]);
            }
        }
    }

    /**
     * @param array<int|string, mixed> $commands
     * @return array<int|string, mixed>
     */
    private function rewriteTableCmdmap(string $table, array $commands, DataHandler $dataHandler): array
    {
        $languageCapability = $this->getLanguageCapability($table);
        $languageField = $languageCapability->getLanguageField()->getName();
        $rewritten = [];
        foreach ($commands as $id => $commandArray) {
            if (!is_array($commandArray)) {
                $rewritten[$id] = $commandArray;
                continue;
            }
            $targetId = (int)$id;
            $languageId = (int)(BackendUtility::getRecord($table, (int)$id, $languageField, '', false)[$languageField] ?? 0);
            foreach ($commandArray as $command => $value) {
                if ($command === 'localize' || $command === 'copyToLanguage') {
                    // Reading the source is enough; the target language is checked by Core
                    $rewritten[$id][$command] = $value;
                    continue;
                }
                if ($languageId > 0 && !$this->mayEditLanguage($dataHandler, $table, $id, $languageId)) {
                    continue;
                }
                if ($command !== 'move' && $command !== 'copy') {
                    if ($languageId !== 0 || $this->mayChangeStructure($dataHandler, $table, (int)$id, $command . ' in all languages')) {
                        $rewritten[$id][$command] = $value;
                    }
                    continue;
                }
                if (!$this->mayChangeStructure($dataHandler, $table, (int)$id, $command)) {
                    continue;
                }
                // A move or copy of a translation is a move or copy of its shadow, in all languages
                $targetId = $this->resolveShadowUid($table, (int)$id);
                if (is_array($value) && isset($value['target'])) {
                    $value['target'] = $this->resolveShadowTarget($table, (int)$value['target']);
                    if (is_array($value['update'] ?? null)) {
                        unset($value['update'][$languageField]);
                    }
                } elseif (MathUtility::canBeInterpretedAsInteger($value)) {
                    $value = $this->resolveShadowTarget($table, (int)$value);
                }
                $rewritten[$targetId][$command] = $value;
            }
        }
        return $rewritten;
    }

    /**
     * Shadow of a translation; the uid itself for shadows and "-1" records.
     */
    private function resolveShadowUid(string $table, int $uid): int
    {
        $languageCapability = $this->getLanguageCapability($table);
        $parentField = $languageCapability->getTranslationOriginPointerField()->getName();
        $record = BackendUtility::getRecord($table, $uid, $languageCapability->getLanguageField()->getName() . ',' . $parentField);
        $shadowUid = (int)($record[$parentField] ?? 0);
        return $shadowUid > 0 ? $shadowUid : $uid;
    }

    /**
     * "After record X" (negative) becomes "after X's shadow"; a page id (positive) stays.
     */
    private function resolveShadowTarget(string $table, int $target): int
    {
        return $target < 0 ? -$this->resolveShadowUid($table, abs($target)) : $target;
    }

    private function resolveShadowPid(string $table, mixed $pid): mixed
    {
        if (!MathUtility::canBeInterpretedAsInteger($pid) || (int)$pid >= 0) {
            return $pid;
        }
        return $this->resolveShadowTarget($table, (int)$pid);
    }

    /**
     * A restricted editor's own request for a language they may not edit. Core would reject it, but
     * not after a structure run's grant (StructureAuthority), so it is rejected here.
     */
    private function mayEditLanguage(DataHandler $dataHandler, string $table, int|string $id, int $languageId): bool
    {
        if ($this->structureAuthority->isRestricted($dataHandler->BE_USER) && !$dataHandler->BE_USER->checkLanguageAccess($languageId)) {
            $dataHandler->log($table, (int)$id, SystemLogDatabaseAction::UPDATE, null, SystemLogErrorClassification::USER_ERROR, 'Attempt to modify {table}:{uid} in language {language}, which the user may not edit', null, ['table' => $table, 'uid' => $id, 'language' => $languageId]);
            return false;
        }
        return true;
    }

    /**
     * Structure operations change every language (plan §5, Phase 5).
     */
    private function mayChangeStructure(DataHandler $dataHandler, string $table, int $uid, string $operation): bool
    {
        if ($this->structureAuthority->hasStructurePermission($dataHandler->BE_USER)) {
            $this->structureOperation = true;
            return true;
        }
        $dataHandler->log($table, $uid, SystemLogDatabaseAction::UPDATE, null, SystemLogErrorClassification::USER_ERROR, 'Attempt to {operation} {table}:{uid} without structure permission ("' . StructureAuthority::PERMISSION . '"): it changes every language', null, ['operation' => $operation, 'table' => $table, 'uid' => $uid]);
        return false;
    }

    /**
     * Language of the first translation of a record created in language 0 (page tree, structure
     * column): the Editing Language, otherwise the first language the user may edit.
     */
    private function resolveTranslationLanguageId(string $table, mixed $pid, DataHandler $dataHandler): ?int
    {
        $primaryLanguageId = $this->resolvePrimaryLanguageId($table, $pid);
        if ($primaryLanguageId === null || !$this->structureAuthority->isRestricted($dataHandler->BE_USER)) {
            return $primaryLanguageId;
        }
        $site = $this->siteFinder->getSiteByPageId($this->resolvePageId($table, (int)$pid));
        $candidates = [$this->editingLanguage->get($dataHandler->BE_USER, $site), ...array_keys($site->getAllLanguages())];
        foreach ($candidates as $languageId) {
            if ($languageId !== null && $languageId > 0 && $dataHandler->BE_USER->checkLanguageAccess($languageId)) {
                return $languageId;
            }
        }
        return null;
    }

    private function resolvePageId(string $table, int $pid): int
    {
        return $pid < 0 ? (int)(BackendUtility::getRecord($table, abs($pid), 'pid')['pid'] ?? 0) : $pid;
    }

    private function resolvePrimaryLanguageId(string $table, mixed $pid): ?int
    {
        if (!MathUtility::canBeInterpretedAsInteger($pid)) {
            return null;
        }
        $pageId = (int)$pid;
        if ($pageId < 0) {
            $pageId = (int)(BackendUtility::getRecord($table, abs($pageId), 'pid')['pid'] ?? 0);
        }
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
        } catch (SiteNotFoundException) {
            return null;
        }
        return $this->structureLayer->getPrimaryLanguage($site)?->getLanguageId();
    }

    /**
     * @return array<string, int>
     */
    private function getShadowDefaults(string $table): array
    {
        $defaults = [];
        $schema = $this->tcaSchemaFactory->get($table);
        if ($schema->hasCapability(TcaSchemaCapability::RestrictionDisabledField)) {
            $defaults[$schema->getCapability(TcaSchemaCapability::RestrictionDisabledField)->getField()->getName()] = 0;
        }
        if ($table === 'pages') {
            // PageTranslationVisibility bit 1: never output the structure language
            $defaults['l18n_cfg'] = 1;
        }
        return $defaults;
    }

    /**
     * @return list<string>
     */
    private function getStructuralFieldNames(string $table): array
    {
        $fieldNames = array_keys(iterator_to_array($this->tcaSchemaFactory->get($table)->getFields(
            static fn(FieldTypeInterface $field): bool => $field->getTranslationBehaviour() === FieldTranslationBehaviour::Excluded
        )));
        return array_map(strval(...), $fieldNames);
    }

    /**
     * Label fields of a record; for pages also nav_title (the page tree edits it with showNavTitle).
     *
     * @return list<string>
     */
    private function getLabelFieldNames(string $table): array
    {
        $fieldNames = $this->tcaSchemaFactory->get($table)->getCapability(TcaSchemaCapability::Label)->getAllLabelFieldNames();
        if ($table === 'pages') {
            $fieldNames[] = 'nav_title';
        }
        return array_values(array_unique(array_map(strval(...), $fieldNames)));
    }

    private function resolveLabelTranslationUid(string $table, int $shadowUid, int $pageId, DataHandler $dataHandler): ?int
    {
        $editingLanguageId = null;
        try {
            $editingLanguageId = $this->editingLanguage->get($dataHandler->BE_USER, $this->siteFinder->getSiteByPageId($pageId));
        } catch (SiteNotFoundException) {
        }
        return $this->shadowLabelResolver->resolve($table, $shadowUid, $pageId, $editingLanguageId, (int)$dataHandler->BE_USER->workspace)['uid'] ?? null;
    }

    /**
     * Fields that are never set on a shadow, but on every translation ("hide in all languages").
     *
     * @return list<string>
     */
    private function getAllLanguagesVisibilityFieldNames(string $table): array
    {
        $schema = $this->tcaSchemaFactory->get($table);
        $fieldNames = [];
        if ($schema->hasCapability(TcaSchemaCapability::RestrictionDisabledField)) {
            $fieldNames[] = $schema->getCapability(TcaSchemaCapability::RestrictionDisabledField)->getField()->getName();
        }
        if ($table === 'pages') {
            // the shadow page's time window stays open (I5); on tt_content it is l10n_mode=exclude
            $fieldNames[] = 'starttime';
            $fieldNames[] = 'endtime';
        }
        return $fieldNames;
    }

    /**
     * Live translations of a shadow. With $deletedAt: deleted translations with that tstamp.
     *
     * @return list<int>
     */
    private function fetchTranslationUids(string $table, int $shadowUid, ?int $deletedAt = null): array
    {
        $languageCapability = $this->getLanguageCapability($table);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->select('uid')->from($table)->where(
            $queryBuilder->expr()->eq($languageCapability->getTranslationOriginPointerField()->getName(), $queryBuilder->createNamedParameter($shadowUid, Connection::PARAM_INT)),
            $queryBuilder->expr()->gt($languageCapability->getLanguageField()->getName(), 0),
            $queryBuilder->expr()->eq('t3ver_wsid', 0),
            $queryBuilder->expr()->eq('deleted', $deletedAt === null ? 0 : 1),
        );
        if ($deletedAt !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('tstamp', $queryBuilder->createNamedParameter($deletedAt, Connection::PARAM_INT)));
        }
        return array_map(intval(...), $queryBuilder->executeQuery()->fetchFirstColumn());
    }

    private function registerForAlignment(string $table, int $uid): void
    {
        if ($uid <= 0 || !in_array($table, StructureLayer::SHADOWED_TABLES, true)) {
            return;
        }
        $record = BackendUtility::getRecord($table, $uid, 'pid', '', false);
        if ($record !== null) {
            $this->pagesToAlign[$table][(int)$record['pid']] = true;
        }
    }

    /**
     * Component C: a translation's sorting equals its shadow's sorting. Written directly, like
     * DataHandler's own resorting: it is derived data, not an editor change.
     */
    private function alignSorting(): void
    {
        $pagesToAlign = $this->pagesToAlign;
        $this->pagesToAlign = [];
        foreach ($pagesToAlign as $table => $pageIds) {
            $schema = $this->tcaSchemaFactory->get($table);
            if (!$schema->hasCapability(TcaSchemaCapability::SortByField)) {
                continue;
            }
            $sortingField = $schema->getCapability(TcaSchemaCapability::SortByField)->getFieldName();
            $languageCapability = $this->getLanguageCapability($table);
            $languageField = $languageCapability->getLanguageField()->getName();
            $parentField = $languageCapability->getTranslationOriginPointerField()->getName();
            $connection = $this->connectionPool->getConnectionForTable($table);
            foreach (array_keys($pageIds) as $pageId) {
                $shadows = $connection->select(['uid', $sortingField], $table, [
                    'pid' => $pageId, $languageField => 0, 'deleted' => 0, 't3ver_wsid' => 0,
                ])->fetchAllKeyValue();
                foreach ($shadows as $shadowUid => $sorting) {
                    $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
                    $queryBuilder->update($table)
                        ->set($sortingField, (int)$sorting)
                        ->where(
                            $queryBuilder->expr()->eq($parentField, $queryBuilder->createNamedParameter((int)$shadowUid, Connection::PARAM_INT)),
                            $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)),
                            $queryBuilder->expr()->gt($languageField, 0),
                            $queryBuilder->expr()->neq($sortingField, $queryBuilder->createNamedParameter((int)$sorting, Connection::PARAM_INT)),
                        )
                        ->executeStatement();
                }
            }
        }
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $cmdmap
     */
    private function runNestedCmdmap(DataHandler $parentDataHandler, array $cmdmap): void
    {
        // DataHandler is a prototype, not a service: a new instance per run is how Core does it, too
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmdmap, $parentDataHandler->BE_USER);
        $dataHandler->process_cmdmap();
        $parentDataHandler->errorLog = array_merge($parentDataHandler->errorLog, $dataHandler->errorLog);
    }

    private function getLanguageCapability(string $table): LanguageAwareSchemaCapability
    {
        return $this->tcaSchemaFactory->get($table)->getCapability(TcaSchemaCapability::Language);
    }
}
