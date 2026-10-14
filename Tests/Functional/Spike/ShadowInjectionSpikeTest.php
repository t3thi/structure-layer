<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Spike;

use PHPUnit\Framework\Attributes\Test;
use T3thi\StructureLayer\Tests\Functional\AbstractStructureLayerTestCase;
use T3thi\StructureLayer\Tests\Functional\Spike\Fixtures\ShadowInjectionSpikeHook;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Spike S2 (shadow injection in processDatamap_beforeStart) and S4 (empty shadows vs. required fields).
 */
final class ShadowInjectionSpikeTest extends AbstractStructureLayerTestCase
{
    protected array $configurationToUseInTestInstance = [
        'SC_OPTIONS' => [
            't3lib/class.t3lib_tcemain.php' => [
                'processDatamapClass' => [
                    'structure_layer_spike' => ShadowInjectionSpikeHook::class,
                ],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users_admin.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/StructureSite.csv');
        $this->installSiteConfiguration();
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    #[Test]
    public function newContentInLanguageTwoGetsConnectedShadow(): void
    {
        $dataHandler = $this->process([
            'tt_content' => [
                'NEW1' => ['pid' => 1, 'sys_language_uid' => 2, 'CType' => 'header', 'colPos' => 2, 'header' => 'English only'],
            ],
        ]);
        $translation = BackendUtility::getRecord('tt_content', $dataHandler->substNEWwithIDs['NEW1']);
        $shadowUid = $dataHandler->substNEWwithIDs['NEW1shadow'] ?? 0;
        $shadow = BackendUtility::getRecord('tt_content', $shadowUid);

        self::assertSame(0, $shadow['sys_language_uid']);
        self::assertSame('', $shadow['header']);
        self::assertSame('header', $shadow['CType']);
        self::assertSame(2, $shadow['colPos']);
        self::assertSame($shadowUid, $translation['l18n_parent']);
        self::assertSame(2, $translation['sys_language_uid']);
        self::assertSame('English only', $translation['header']);
        self::assertSame('header', $translation['CType'], 'CType synchronized from in-memory shadow');
        self::assertSame(2, $translation['colPos'], 'colPos synchronized from in-memory shadow');
        self::assertSame(1, $translation['pid']);
        // Record observed values for the findings
        fwrite(STDERR, LF . 'S2 l10n_source=' . $translation['l10n_source'] . ' l18n_diffsource=' . $translation['l18n_diffsource'] . ' sorting shadow/translation=' . $shadow['sorting'] . '/' . $translation['sorting'] . LF);
    }

    #[Test]
    public function newContentInsertedAfterTranslationLandsAfterItsShadow(): void
    {
        // insert after "Element 1 (de)" (uid 101, shadow 100, sorting 256); next shadow 110 has sorting 512
        $dataHandler = $this->process([
            'tt_content' => [
                'NEW1' => ['pid' => -101, 'sys_language_uid' => 1, 'CType' => 'text', 'header' => 'Inserted (de)'],
            ],
        ]);
        $translation = BackendUtility::getRecord('tt_content', $dataHandler->substNEWwithIDs['NEW1']);
        $shadow = BackendUtility::getRecord('tt_content', $dataHandler->substNEWwithIDs['NEW1shadow']);

        $previousShadow = BackendUtility::getRecord('tt_content', 100);
        $nextShadow = BackendUtility::getRecord('tt_content', 110);

        self::assertSame(1, $shadow['pid']);
        self::assertSame(1, $translation['pid']);
        self::assertGreaterThan($previousShadow['sorting'], $shadow['sorting']);
        self::assertLessThan($nextShadow['sorting'], $shadow['sorting']);
        $sortings = $this->getConnectionPool()->getConnectionForTable('tt_content')
            ->select(['uid', 'sorting'], 'tt_content', ['pid' => 1], [], ['sorting' => 'ASC', 'uid' => 'ASC'])->fetchAllKeyValue();
        fwrite(STDERR, LF . 'S2 insert-after sorting shadow/translation=' . $shadow['sorting'] . '/' . $translation['sorting'] . ' page=' . json_encode($sortings) . LF);
    }

    #[Test]
    public function newPageInLanguageOneGetsEmptyShadowDespiteRequiredTitle(): void
    {
        $dataHandler = $this->process([
            'pages' => [
                'NEW1' => ['pid' => 1, 'sys_language_uid' => 1, 'doktype' => 1, 'title' => 'Neue Seite'],
            ],
        ]);
        $translation = BackendUtility::getRecord('pages', $dataHandler->substNEWwithIDs['NEW1']);
        $shadowUid = $dataHandler->substNEWwithIDs['NEW1shadow'] ?? 0;
        $shadow = BackendUtility::getRecord('pages', $shadowUid);

        self::assertIsArray($shadow, 'Shadow page has been created');
        self::assertSame('', $shadow['title']);
        self::assertSame(1, $shadow['l18n_cfg']);
        self::assertSame($shadowUid, $translation['l10n_parent']);
        self::assertSame(1, $translation['pid']);
        self::assertSame('Neue Seite', $translation['title']);
        fwrite(STDERR, LF . 'S4 shadow slug="' . $shadow['slug'] . '" translation slug="' . $translation['slug'] . '"' . LF);
    }

    #[Test]
    public function twoNewPagesInDifferentLanguagesDoNotCollideOnShadowSlug(): void
    {
        $dataHandler = $this->process([
            'pages' => [
                'NEW1' => ['pid' => 1, 'sys_language_uid' => 1, 'title' => 'Seite A'],
                'NEW2' => ['pid' => 1, 'sys_language_uid' => 2, 'title' => 'Page B'],
            ],
        ]);
        $shadowA = BackendUtility::getRecord('pages', $dataHandler->substNEWwithIDs['NEW1shadow']);
        $shadowB = BackendUtility::getRecord('pages', $dataHandler->substNEWwithIDs['NEW2shadow']);
        fwrite(STDERR, LF . 'S4 shadow slugs="' . $shadowA['slug'] . '" / "' . $shadowB['slug'] . '"' . LF);
        self::assertNotSame($shadowA['slug'], $shadowB['slug']);
    }

    private function process(array $datamap): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($datamap, []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog, 'DataHandler error log');
        $this->assertNoLoggedErrors();
        return $dataHandler;
    }

    private function assertNoLoggedErrors(): void
    {
        $rows = $this->getConnectionPool()->getConnectionForTable('sys_log')
            ->select(['details', 'log_data'], 'sys_log', ['error' => 1])->fetchAllAssociative();
        $rows = array_merge($rows, $this->getConnectionPool()->getConnectionForTable('sys_log')
            ->select(['details', 'log_data'], 'sys_log', ['error' => 2])->fetchAllAssociative());
        self::assertSame([], $rows, 'sys_log errors/warnings');
    }
}
