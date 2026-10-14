<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Spike\Fixtures;

use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Spike S2: throw-away version of component B. Injects a NEW shadow in front of every NEW
 * record that is created directly in a language > 0, and moves structural values to it.
 */
final class ShadowInjectionSpikeHook
{
    private const STRUCTURAL_FIELDS = [
        'tt_content' => ['CType', 'colPos'],
        'pages' => ['doktype'],
    ];

    public function processDatamap_beforeStart(DataHandler $dataHandler): void
    {
        $datamap = [];
        foreach ($dataHandler->datamap as $table => $records) {
            $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
            $languageField = $ctrl['languageField'] ?? '';
            $parentField = $ctrl['transOrigPointerField'] ?? '';
            foreach ($records as $id => $fields) {
                if ($languageField === '' || $parentField === ''
                    || MathUtility::canBeInterpretedAsInteger($id)
                    || (int)($fields[$languageField] ?? 0) <= 0
                    || !empty($fields[$parentField])
                ) {
                    $datamap[$table][$id] = $fields;
                    continue;
                }
                // no underscore: DataHandler::processRemapStack() reads "a_NEWb" as table "a" + id "NEWb"
                $shadowId = $id . 'shadow';
                $shadow = ['pid' => $this->resolveShadowPid($table, $fields['pid'] ?? 0, $parentField), $languageField => 0];
                foreach (self::STRUCTURAL_FIELDS[$table] ?? [] as $structuralField) {
                    if (array_key_exists($structuralField, $fields)) {
                        $shadow[$structuralField] = $fields[$structuralField];
                        unset($fields[$structuralField]);
                    }
                }
                if ($table === 'pages') {
                    $shadow['l18n_cfg'] = 1;
                }
                $datamap[$table][$shadowId] = $shadow;
                $fields[$parentField] = $shadowId;
                $datamap[$table][$id] = $fields;
            }
        }
        $dataHandler->datamap = $datamap;
    }

    private function resolveShadowPid(string $table, int|string $pid, string $parentField): int|string
    {
        if (!MathUtility::canBeInterpretedAsInteger($pid) || (int)$pid >= 0) {
            return $pid;
        }
        $record = \TYPO3\CMS\Backend\Utility\BackendUtility::getRecord($table, abs((int)$pid), $parentField);
        $parentUid = (int)($record[$parentField] ?? 0);
        return $parentUid > 0 ? -$parentUid : $pid;
    }
}
