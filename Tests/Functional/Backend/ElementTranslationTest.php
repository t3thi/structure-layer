<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use T3thi\StructureLayer\Integrity\InvariantChecker;
use TYPO3\CMS\Backend\Controller\Wizard\LocalizationController;
use TYPO3\CMS\Backend\Localization\LocalizationInstructions;
use TYPO3\CMS\Backend\Localization\LocalizationMode;
use TYPO3\CMS\Backend\Localization\ManualLocalizationHandler;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\View\PageViewMode;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * Comparison view: a gap (element missing in a column's language) offers "Translate" for that
 * element. It opens Core's wizard for the shadow: sources are the languages the element exists
 * in, the element is translated from its record in the chosen source language.
 *
 * Core enforces one source language per page translation once it has content
 * (mod.web_layout.localization.enforceSourceLanguage, patch); the extension turns it off by
 * default. Enforced, gaps without an allowed source offer no "Translate".
 *
 * Fixtures/PageModule.csv, page 1 (de, en): en content was translated from de (102 <- 101).
 * Added here: en-GB 122 of element 120 (de 121), element 150 in en-GB only (151).
 */
final class ElementTranslationTest extends AbstractPageModuleTestCase
{
    private const string ENFORCE = 'mod.web_layout.localization.enforceSourceLanguage = 1';

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $rows = [
            [122, 768, 3, 120, 121, 'Element 3 (en-GB)'],
            [150, 1536, 0, 0, 0, ''],
            [151, 1536, 3, 150, 0, 'Element 7 (en-GB only)'],
        ];
        foreach ($rows as [$uid, $sorting, $languageId, $parent, $source, $header]) {
            $connection->insert('tt_content', [
                'uid' => $uid, 'pid' => 1, 'sorting' => $sorting, 'sys_language_uid' => $languageId,
                'l18n_parent' => $parent, 'l10n_source' => $source, 'colPos' => 0, 'CType' => 'text', 'header' => $header,
            ]);
        }
    }

    #[Test]
    public function gapsOfferTranslateForTheElementIntoTheColumnLanguage(): void
    {
        $html = $this->renderComparison();

        self::assertSame(['120:2', '125:1', '150:1', '150:2'], $this->translateButtons($html));
    }

    #[Test]
    public function gapsListTheLanguagesTheElementExistsIn(): void
    {
        $html = $this->renderComparison();

        // Element 120 in the en column: de and en-GB, in site-language order, no single source flag
        $cell = $this->gapCell('id="element-tt_content-121"', 1, $html);
        self::assertStringContainsString('Element 3 (de only)', strip_tags($cell));
        self::assertStringContainsString('Available in other languages:', $cell);
        self::assertSame(['flags-de', 'flags-gb'], $this->flags($cell));
    }

    #[Test]
    public function wizardOffersTheLanguagesTheElementExistsIn(): void
    {
        self::assertSame([1, 3], $this->sourceLanguageIds(120, 2));
        self::assertSame([3], $this->sourceLanguageIds(150, 2));
    }

    #[Test]
    public function enforcedSourceLanguageLimitsSourcesAndGaps(): void
    {
        $this->setPageTsConfig(1, self::ENFORCE);

        // en content comes from de: en-GB is no longer a source for en
        self::assertSame([1], $this->sourceLanguageIds(120, 2));
        self::assertSame([], $this->sourceLanguageIds(150, 2));
        // de content was written directly (no source yet): every language stays a source for de
        self::assertSame(['120:2', '125:1', '150:1'], $this->translateButtons($this->renderComparison()));
    }

    #[Test]
    public function userTsConfigOverridesThePageDefault(): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_users')
            ->update('be_users', ['TSconfig' => 'page.' . self::ENFORCE], ['uid' => 1]);
        $this->backendUser = $this->setUpBackendUser(1);

        self::assertSame([1], $this->sourceLanguageIds(120, 2));
    }

    #[Test]
    public function elementIsTranslatedFromItsRecordInTheSourceLanguage(): void
    {
        $result = $this->get(ManualLocalizationHandler::class)->processLocalization(
            new LocalizationInstructions('tt_content', 150, 3, 2, LocalizationMode::TRANSLATE, [])
        );

        self::assertTrue($result->jsonSerialize()['success'] ?? false, json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR));
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $translation = $queryBuilder->select('*')->from('tt_content')->where(
            $queryBuilder->expr()->eq('l18n_parent', 150),
            $queryBuilder->expr()->eq('sys_language_uid', 2),
        )->executeQuery()->fetchAssociative();
        self::assertIsArray($translation);
        self::assertSame(151, (int)$translation['l10n_source']);
        self::assertStringContainsString('Element 7', (string)$translation['header']);
        self::assertSame([], array_map(strval(...), $this->get(InvariantChecker::class)->check()));
    }

    /**
     * The n-th cell (0-based) of the row containing $needle.
     */
    private function gapCell(string $needle, int $index, string $html): string
    {
        preg_match_all('#<td\b[^>]*>(.*?)</td>#s', $this->tableRowContaining($needle, $html), $cells);
        return $cells[1][$index] ?? '';
    }

    /**
     * @return list<string>
     */
    private function flags(string $markup): array
    {
        preg_match_all('#data-identifier="(flags-[a-z-]+)"#', $markup, $matches);
        return $matches[1];
    }

    private function renderComparison(): string
    {
        return $this->renderPageModule(1, PageViewMode::LanguageComparisonView, ['languages' => [1, 2]]);
    }

    /**
     * "Translate" buttons of the gaps, as "<shadow uid>:<target language>", sorted.
     *
     * @return list<string>
     */
    private function translateButtons(string $html): array
    {
        preg_match_all('#<typo3-backend-localization-button[^>]*record-type="tt_content"[^>]*record-uid="(\d+)"[^>]*target-language="(\d+)"#s', $html, $matches, PREG_SET_ORDER);
        $buttons = array_map(static fn(array $match): string => $match[1] . ':' . $match[2], $matches);
        sort($buttons);
        return $buttons;
    }

    /**
     * @return list<int>
     */
    private function sourceLanguageIds(int $shadowUid, int $targetLanguage): array
    {
        $request = new ServerRequest('https://structure.localhost/typo3/ajax/records/localize')
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/ajax/records/localize', []))
            ->withQueryParams(['recordType' => 'tt_content', 'recordUid' => $shadowUid, 'targetLanguage' => $targetLanguage]);
        $languages = json_decode((string)$this->get(LocalizationController::class)->getSources($request)->getBody(), true, 512, JSON_THROW_ON_ERROR);
        return array_values(array_map(static fn(array $language): int => (int)$language['uid'], $languages));
    }
}
