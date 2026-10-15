<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use T3thi\StructureLayer\Integrity\InvariantChecker;
use TYPO3\CMS\Backend\View\PageViewMode;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Phase 3b (plan §4 H, decision 3): every element and the page get "hide / show in all
 * languages" next to Core's per-language toggle. The toggle sends "hidden" for the shadow;
 * the DataHandler hook (Phase 2) writes it on every translation. "Hidden in all languages"
 * is derived (all existing translations hidden), there is no stored flag.
 */
final class HideInAllLanguagesToggleTest extends AbstractPageModuleTestCase
{
    #[Test]
    public function translationOffersHideInAllLanguagesForItsShadow(): void
    {
        $html = $this->renderPageModule(1, PageViewMode::LayoutView, ['languages' => [2]]);

        self::assertSame(['tt_content' => [100 => ['hidden' => '1']]], $this->toggleData($this->recordElement(102, $html)));
        self::assertStringNotContainsString('structure-layer-hidden-everywhere', $this->recordElement(102, $html));
    }

    #[Test]
    public function elementHiddenInAllLanguagesIsMarkedAndOffersShowInAllLanguages(): void
    {
        $html = $this->renderPageModule(1, PageViewMode::LayoutView, ['languages' => [2]]);

        $element = $this->recordElement(142, $html);
        self::assertStringContainsString('structure-layer-hidden-everywhere', $element);
        self::assertSame(['tt_content' => [140 => ['hidden' => '0']]], $this->toggleData($element));
    }

    #[Test]
    public function allLanguagesRecordHasNoExtraToggle(): void
    {
        $html = $this->renderPageModule(1, PageViewMode::LayoutView, ['languages' => [2]]);

        self::assertNull($this->toggleData($this->recordElement(130, $html)));
    }

    #[Test]
    public function toggleHidesEveryTranslationAndKeepsTheShadowVisible(): void
    {
        $html = $this->renderPageModule(1, PageViewMode::LayoutView, ['languages' => [2]]);
        $data = $this->toggleData($this->recordElement(102, $html));
        self::assertIsArray($data);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, []);
        $dataHandler->process_datamap();

        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $hidden = $queryBuilder->select('uid', 'hidden')->from('tt_content')
            ->where($queryBuilder->expr()->in('uid', [100, 101, 102]))->orderBy('uid')->executeQuery()->fetchAllKeyValue();
        self::assertSame([100 => 0, 101 => 1, 102 => 1], array_map(intval(...), $hidden));
        self::assertSame([], array_map(strval(...), $this->get(InvariantChecker::class)->check()));

        $html = $this->renderPageModule(1, PageViewMode::LayoutView, ['languages' => [2]]);
        self::assertStringContainsString('structure-layer-hidden-everywhere', $this->recordElement(102, $html));
    }

    #[Test]
    public function pageOffersHideInAllLanguages(): void
    {
        $html = $this->renderPageModule(1, PageViewMode::LayoutView, ['languages' => [2]]);

        $start = strpos($html, 'structure-layer-page-visibility');
        self::assertNotFalse($start);
        self::assertSame(['pages' => [1 => ['hidden' => '1']]], $this->toggleData(substr($html, $start, 3000)));
    }

    /**
     * The datamap of the first "all languages" toggle in the markup.
     *
     * @return array<string, mixed>|null
     */
    private function toggleData(string $markup): ?array
    {
        if (!preg_match('#class="[^"]*structure-layer-toggle-all-languages[^"]*"[^>]*href="([^"]*)"|href="([^"]*)"[^>]*class="[^"]*structure-layer-toggle-all-languages#', $markup, $match)) {
            return null;
        }
        $url = html_entity_decode($match[1] !== '' ? $match[1] : ($match[2] ?? ''));
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        return is_array($query['data'] ?? null) ? $query['data'] : null;
    }
}
