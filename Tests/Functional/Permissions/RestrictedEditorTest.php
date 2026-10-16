<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Permissions;

use PHPUnit\Framework\Attributes\Test;
use T3thi\StructureLayer\Tests\Functional\DataHandling\AbstractDataHandlingTestCase;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Phase 5 (plan §5, permissions): an editor restricted to English (allowed_languages = 2).
 *
 * Core checks language access per record, and every shadow is language 0. Without help, creating
 * English content fails half-way (the English record is written, its shadow rejected: I2), and
 * every structural change is rejected or only partly applied (Fixtures: "Probe" in findings).
 *
 * Prototype (characterization, no final model):
 * - Content operations are system-managed: shadow writes that follow from editing the editor's
 *   own languages need no access to language 0 (create, translate, delete/restore a translation,
 *   create a page in the page tree).
 * - Structure operations change every language and need the custom permission
 *   "structure_layer:structure": move, copy, structural fields, hide/delete in all languages.
 *   With it, they are applied to all languages, also those the editor cannot edit.
 *
 * Users (Fixtures/RestrictedEditor.csv): 3 "english" (content only), 4 "structure" (+ structure).
 * Data: AbstractDataHandlingTestCase (StructureData.csv): page 1 with 100 (de 101, en 102),
 * 110 (de 111, en 112), 120 (de 121 only); page 10 with 200 (de 201, en 202).
 */
final class RestrictedEditorTest extends AbstractDataHandlingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/RestrictedEditor.csv');
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 31], ['deleted' => 0]);
    }

    #[Test]
    public function contentEditorCreatesContentInTheirLanguage(): void
    {
        $this->actAs(3);

        $dataHandler = $this->processDatamap(['tt_content' => ['NEW1' => ['pid' => -112, 'sys_language_uid' => 2, 'CType' => 'text', 'header' => 'New (en)']]]);

        $record = $this->fetchRecord('tt_content', (int)$dataHandler->substNEWwithIDs['NEW1']);
        $shadow = $this->fetchRecord('tt_content', (int)$record['l18n_parent']);
        self::assertSame(0, (int)$shadow['sys_language_uid']);
        self::assertSame([100, 110, $shadow['uid'], 120, 130], $this->fetchStructureOrder(1));
        $this->assertInvariantsHold();
    }

    #[Test]
    public function contentEditorCreatesAPageInThePageTreeInTheirLanguage(): void
    {
        $this->actAs(3);

        $dataHandler = $this->processDatamap(['pages' => ['NEW1' => ['pid' => 1, 'title' => 'New page', 'hidden' => 0]]]);

        $shadowUid = (int)$dataHandler->substNEWwithIDs['NEW1'];
        $translations = $this->fetchTranslations('pages', $shadowUid);
        self::assertSame([2], array_keys($translations), 'translation in an allowed language, not in the primary one');
        self::assertSame('New page', $translations[2]['title']);
        $this->assertInvariantsHold();
    }

    #[Test]
    public function contentEditorEditsAndHidesInTheirLanguage(): void
    {
        $this->actAs(3);

        $this->processDatamap(['tt_content' => [102 => ['header' => 'Changed (en)', 'hidden' => 1]]]);

        self::assertSame(['Changed (en)', 1], [$this->fetchRecord('tt_content', 102)['header'], (int)$this->fetchRecord('tt_content', 102)['hidden']]);
        self::assertSame(0, (int)$this->fetchRecord('tt_content', 101)['hidden']);
        $this->assertInvariantsHold();
    }

    #[Test]
    public function contentEditorTranslatesFromALanguageTheyCannotEdit(): void
    {
        $this->actAs(3);

        $this->processCmdmap(['tt_content' => [121 => ['localize' => 2]]]);

        self::assertArrayHasKey(2, $this->fetchTranslations('tt_content', 120));
        $this->assertInvariantsHold();
    }

    #[Test]
    public function contentEditorDeletesAndRestoresTheLastTranslation(): void
    {
        $this->actAs(3);
        $uid = (int)$this->processDatamap(['tt_content' => ['NEW1' => ['pid' => 1, 'sys_language_uid' => 2, 'CType' => 'text', 'header' => 'Only en']]])->substNEWwithIDs['NEW1'];
        $shadowUid = (int)$this->fetchRecord('tt_content', $uid)['l18n_parent'];

        $this->processCmdmap(['tt_content' => [$uid => ['delete' => 1]]]);
        self::assertSame(1, (int)$this->fetchRecord('tt_content', $shadowUid)['deleted'], 'I4: shadow deleted with the last translation');
        $this->assertInvariantsHold();

        $this->processCmdmap(['tt_content' => [$uid => ['undelete' => 1]]]);
        self::assertSame(0, (int)$this->fetchRecord('tt_content', $shadowUid)['deleted'], 'shadow restored with its translation');
        $this->assertInvariantsHold();
    }

    #[Test]
    public function contentEditorCannotMove(): void
    {
        $this->actAs(3);

        $dataHandler = $this->processCmdmap(['tt_content' => [112 => ['move' => -102]]], true);

        self::assertStructurePermissionError($dataHandler);
        self::assertSame([100, 110, 120, 130], $this->fetchStructureOrder(1));
        $this->assertInvariantsHold();
    }

    #[Test]
    public function contentEditorCannotChangeStructuralFields(): void
    {
        $this->actAs(3);

        $dataHandler = $this->processDatamap(['tt_content' => [102 => ['colPos' => 1, 'header' => 'Changed (en)']]], true);

        self::assertStructurePermissionError($dataHandler);
        // The payload part of the same request is saved, the structural part nowhere
        self::assertSame(['Changed (en)', 0], [$this->fetchRecord('tt_content', 102)['header'], (int)$this->fetchRecord('tt_content', 102)['colPos']]);
        self::assertSame([0, 0], [(int)$this->fetchRecord('tt_content', 100)['colPos'], (int)$this->fetchRecord('tt_content', 101)['colPos']]);
        $this->assertInvariantsHold();
    }

    #[Test]
    public function contentEditorCannotHideOrDeleteInAllLanguages(): void
    {
        $this->actAs(3);

        self::assertStructurePermissionError($this->processDatamap(['tt_content' => [100 => ['hidden' => 1]]], true));
        self::assertStructurePermissionError($this->processCmdmap(['tt_content' => [110 => ['delete' => 1]]], true));

        self::assertSame([0, 0], [(int)$this->fetchRecord('tt_content', 101)['hidden'], (int)$this->fetchRecord('tt_content', 102)['hidden']]);
        self::assertSame([0, 0], [(int)$this->fetchRecord('tt_content', 110)['deleted'], (int)$this->fetchRecord('tt_content', 111)['deleted']]);
        $this->assertInvariantsHold();
    }

    #[Test]
    public function contentEditorCannotEditLanguagesTheyAreNotAllowedFor(): void
    {
        $this->actAs(3);

        $this->processDatamap(['tt_content' => [101 => ['header' => 'Changed (de)']]], true);

        self::assertSame('Element 1 (de)', $this->fetchRecord('tt_content', 101)['header']);
    }

    #[Test]
    public function structureEditorMovesInAllLanguages(): void
    {
        $this->actAs(4);

        $this->processCmdmap(['tt_content' => [112 => ['move' => 0 - 120]]]);

        self::assertSame([100, 120, 110, 130], $this->fetchStructureOrder(1));
        $this->assertInvariantsHold();
    }

    #[Test]
    public function structureEditorChangesStructuralFieldsForAllLanguages(): void
    {
        $this->actAs(4);

        $this->processDatamap(['tt_content' => [102 => ['colPos' => 1]]]);

        self::assertSame([1, 1, 1], array_map(fn(int $uid): int => (int)$this->fetchRecord('tt_content', $uid)['colPos'], [100, 101, 102]));
        $this->assertInvariantsHold();
    }

    #[Test]
    public function structureEditorHidesAndDeletesInAllLanguages(): void
    {
        $this->actAs(4);

        $this->processDatamap(['tt_content' => [100 => ['hidden' => 1]]]);
        $this->processCmdmap(['tt_content' => [110 => ['delete' => 1]]]);

        self::assertSame([0, 1, 1], array_map(fn(int $uid): int => (int)$this->fetchRecord('tt_content', $uid)['hidden'], [100, 101, 102]));
        self::assertSame([1, 1, 1], array_map(fn(int $uid): int => (int)$this->fetchRecord('tt_content', $uid)['deleted'], [110, 111, 112]));
        $this->assertInvariantsHold();
    }

    #[Test]
    public function structurePermissionDoesNotAllowEditingOtherLanguagesPayload(): void
    {
        $this->actAs(4);

        $this->processDatamap(['tt_content' => [100 => ['hidden' => 1], 101 => ['header' => 'Changed (de)']]], true);

        self::assertSame('Element 1 (de)', $this->fetchRecord('tt_content', 101)['header']);
        self::assertSame(1, (int)$this->fetchRecord('tt_content', 102)['hidden'], 'the structural part is applied');
    }

    #[Test]
    public function languageRestrictionIsRestoredAfterTheRun(): void
    {
        $backendUser = $this->actAs(4);

        $this->processCmdmap(['tt_content' => [112 => ['move' => 0 - 120]]]);

        self::assertFalse($backendUser->checkLanguageAccess(0));
        self::assertFalse($backendUser->checkLanguageAccess(1));
        self::assertTrue($backendUser->checkLanguageAccess(2));
    }

    private function actAs(int $userId): \TYPO3\CMS\Core\Authentication\BackendUserAuthentication
    {
        $backendUser = $this->setUpBackendUser($userId);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        return $backendUser;
    }

    private static function assertStructurePermissionError(DataHandler $dataHandler): void
    {
        self::assertNotSame([], array_filter($dataHandler->errorLog, static fn(string $message): bool => str_contains($message, 'structure permission')), implode("\n", $dataHandler->errorLog));
    }
}
