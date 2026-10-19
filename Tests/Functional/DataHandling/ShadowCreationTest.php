<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\DataHandling;

use PHPUnit\Framework\Attributes\Test;
use T3thi\StructureLayer\Backend\EditingLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Phase 2, scenarios 1 and 2 (component B): every new record ends up as shadow + connected translation.
 */
final class ShadowCreationTest extends AbstractDataHandlingTestCase
{
    #[Test]
    public function contentCreatedInOneLanguageGetsConnectedShadow(): void
    {
        $dataHandler = $this->processDatamap([
            'tt_content' => [
                'NEW1' => ['pid' => 1, 'sys_language_uid' => 2, 'CType' => 'header', 'colPos' => 1, 'header' => 'English only'],
            ],
        ]);
        $translation = $this->fetchRecord('tt_content', $dataHandler->substNEWwithIDs['NEW1']);
        $shadow = $this->fetchRecord('tt_content', (int)$translation['l18n_parent']);

        self::assertSame(0, $shadow['sys_language_uid']);
        self::assertSame(1, $shadow['pid']);
        self::assertSame('', $shadow['header'], 'shadow carries no payload');
        self::assertSame('header', $shadow['CType']);
        self::assertSame(1, $shadow['colPos']);
        self::assertSame(0, $shadow['hidden']);
        self::assertSame(2, $translation['sys_language_uid']);
        self::assertSame(0, $translation['l10n_source'], 'created directly in the language');
        self::assertSame('English only', $translation['header']);
        self::assertSame('header', $translation['CType']);
        self::assertSame(1, $translation['colPos']);
        self::assertSame($shadow['sorting'], $translation['sorting']);
        self::assertSame([2], array_keys($this->fetchTranslations('tt_content', (int)$shadow['uid'])));
        $this->assertInvariantsHold();
    }

    #[Test]
    public function hiddenContentCreatedInOneLanguageKeepsTheShadowVisible(): void
    {
        $dataHandler = $this->processDatamap([
            'tt_content' => [
                'NEW1' => ['pid' => 1, 'sys_language_uid' => 1, 'CType' => 'text', 'header' => 'Hidden', 'hidden' => 1],
            ],
        ]);
        $translation = $this->fetchRecord('tt_content', $dataHandler->substNEWwithIDs['NEW1']);
        self::assertSame(1, $translation['hidden']);
        self::assertSame(0, $this->fetchRecord('tt_content', (int)$translation['l18n_parent'])['hidden']);
        $this->assertInvariantsHold();
    }

    #[Test]
    public function contentInsertedAfterATranslationLandsAfterItsShadowWithoutRenumbering(): void
    {
        $before = $this->fetchSortingSnapshot(1);
        $dataHandler = $this->processDatamap([
            'tt_content' => [
                'NEW1' => ['pid' => -101, 'sys_language_uid' => 1, 'CType' => 'text', 'header' => 'Inserted (de)'],
            ],
        ]);
        $translation = $this->fetchRecord('tt_content', $dataHandler->substNEWwithIDs['NEW1']);
        $shadowUid = (int)$translation['l18n_parent'];

        self::assertSame([100, $shadowUid, 110, 120, 130], $this->fetchStructureOrder(1));
        self::assertSame(1, $translation['pid']);
        // SCALE-1: an insert must not renumber the rest of the page (Phase 0, S2)
        $after = $this->fetchSortingSnapshot(1);
        self::assertSame($before, array_intersect_key($after, $before), 'existing rows keep their sorting');
        $this->assertInvariantsHold();
    }

    #[Test]
    public function contentInsertedAfterATranslationOfAnotherLanguageLandsAfterItsShadow(): void
    {
        $dataHandler = $this->processDatamap([
            'tt_content' => [
                // after "Element 2 (en)", in German
                'NEW1' => ['pid' => -112, 'sys_language_uid' => 1, 'CType' => 'text', 'header' => 'Inserted (de)'],
            ],
        ]);
        $shadowUid = (int)$this->fetchRecord('tt_content', $dataHandler->substNEWwithIDs['NEW1'])['l18n_parent'];
        self::assertSame([100, 110, $shadowUid, 120, 130], $this->fetchStructureOrder(1));
        $this->assertInvariantsHold();
    }

    #[Test]
    public function contentCreatedOnTopOfAPageGetsTheFirstShadowPosition(): void
    {
        $dataHandler = $this->processDatamap([
            'tt_content' => [
                'NEW1' => ['pid' => 1, 'sys_language_uid' => 2, 'CType' => 'text', 'header' => 'Top (en)'],
            ],
        ]);
        $shadowUid = (int)$this->fetchRecord('tt_content', $dataHandler->substNEWwithIDs['NEW1'])['l18n_parent'];
        self::assertSame([$shadowUid, 100, 110, 120, 130], $this->fetchStructureOrder(1));
        $this->assertInvariantsHold();
    }

    #[Test]
    public function contentCreatedInTheStructureLanguageBecomesShadowAndPrimaryLanguageTranslation(): void
    {
        $dataHandler = $this->processDatamap([
            'tt_content' => [
                'NEW1' => ['pid' => -110, 'sys_language_uid' => 0, 'CType' => 'text', 'colPos' => 0, 'header' => 'Created in structure column'],
            ],
        ]);
        $shadow = $this->fetchRecord('tt_content', $dataHandler->substNEWwithIDs['NEW1']);
        self::assertSame(0, $shadow['sys_language_uid']);
        self::assertSame('', $shadow['header']);
        $translations = $this->fetchTranslations('tt_content', (int)$shadow['uid']);
        self::assertSame([1], array_keys($translations), 'primary language = first enabled language');
        self::assertSame('Created in structure column', $translations[1]['header']);
        self::assertSame([100, 110, (int)$shadow['uid'], 120, 130], $this->fetchStructureOrder(1));
        $this->assertInvariantsHold();
    }

    #[Test]
    public function pageCreatedInThePageTreeBecomesShadowAndPrimaryLanguageTranslation(): void
    {
        // The page tree creates pages in language 0 (no language field submitted)
        $dataHandler = $this->processDatamap([
            'pages' => [
                'NEW1' => ['pid' => 1, 'doktype' => 1, 'title' => 'Neue Seite', 'nav_hide' => 1],
            ],
        ]);
        $shadowUid = $dataHandler->substNEWwithIDs['NEW1'];
        $shadow = $this->fetchRecord('pages', $shadowUid);
        self::assertSame(0, $shadow['sys_language_uid']);
        self::assertSame('', $shadow['title']);
        self::assertSame(0, $shadow['nav_hide'], 'synchronizable fields stay on the translation');
        self::assertSame(1, $shadow['l18n_cfg'], 'structure language hidden');
        self::assertSame(0, $shadow['hidden']);
        $translations = $this->fetchTranslations('pages', $shadowUid);
        self::assertSame([1], array_keys($translations));
        self::assertSame('Neue Seite', $translations[1]['title']);
        self::assertSame(1, $translations[1]['nav_hide']);
        self::assertSame(1, $translations[1]['pid']);
        $this->assertInvariantsHold();
    }

    #[Test]
    public function pageCreatedInThePageTreeIsTranslatedInTheEditingLanguage(): void
    {
        $this->setEditingLanguage(2);

        $dataHandler = $this->processDatamap([
            'pages' => [
                'NEW1' => ['pid' => 1, 'doktype' => 1, 'title' => 'English only'],
            ],
        ]);
        $shadowUid = $dataHandler->substNEWwithIDs['NEW1'];
        self::assertSame('', $this->fetchRecord('pages', $shadowUid)['title']);
        $translations = $this->fetchTranslations('pages', $shadowUid);
        self::assertSame([2], array_keys($translations));
        self::assertSame('English only', $translations[2]['title']);
        $this->assertInvariantsHold();
    }

    #[Test]
    public function contentCreatedInTheStructureLanguageIsTranslatedInTheEditingLanguage(): void
    {
        $this->setEditingLanguage(2);

        $dataHandler = $this->processDatamap([
            'tt_content' => [
                'NEW1' => ['pid' => -110, 'sys_language_uid' => 0, 'CType' => 'text', 'colPos' => 0, 'header' => 'Created in structure column'],
            ],
        ]);
        $translations = $this->fetchTranslations('tt_content', $dataHandler->substNEWwithIDs['NEW1']);
        self::assertSame([2], array_keys($translations));
        $this->assertInvariantsHold();
    }

    private function setEditingLanguage(int $languageId): void
    {
        $this->get(EditingLanguage::class)->set(
            $GLOBALS['BE_USER'],
            $this->get(SiteFinder::class)->getSiteByIdentifier(self::SITE_IDENTIFIER),
            $languageId
        );
    }

    #[Test]
    public function pageCreatedInOneLanguageGetsConnectedShadow(): void
    {
        $dataHandler = $this->processDatamap([
            'pages' => [
                'NEW1' => ['pid' => 1, 'sys_language_uid' => 2, 'doktype' => 1, 'title' => 'English page', 'nav_hide' => 1],
            ],
        ]);
        $translation = $this->fetchRecord('pages', $dataHandler->substNEWwithIDs['NEW1']);
        $shadow = $this->fetchRecord('pages', (int)$translation['l10n_parent']);
        self::assertSame(0, $shadow['sys_language_uid']);
        self::assertSame(1, $shadow['l18n_cfg']);
        self::assertSame('', $shadow['title']);
        self::assertSame(1, $translation['nav_hide'], 'not overwritten by the empty shadow');
        self::assertSame($shadow['sorting'], $translation['sorting']);
        $this->assertInvariantsHold();
    }

    #[Test]
    public function allLanguagesRecordGetsNoShadow(): void
    {
        $dataHandler = $this->processDatamap([
            'tt_content' => [
                'NEW1' => ['pid' => 1, 'sys_language_uid' => -1, 'CType' => 'text', 'header' => 'All languages'],
            ],
        ]);
        $record = $this->fetchRecord('tt_content', $dataHandler->substNEWwithIDs['NEW1']);
        self::assertSame(-1, $record['sys_language_uid']);
        self::assertSame(0, $record['l18n_parent']);
        self::assertSame([], $this->fetchTranslations('tt_content', (int)$record['uid']));
        $this->assertInvariantsHold();
    }
}
