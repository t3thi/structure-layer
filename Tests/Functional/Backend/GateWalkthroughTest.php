<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use T3thi\StructureLayer\Integrity\InvariantChecker;
use TYPO3\CMS\Backend\View\PageViewMode;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Phase 3 gate (plan §5): "mostly connected, selectively different", end to end, without any
 * Mixed/Free state. Every step uses the DataHandler request the Layout module sends; after
 * every step the comparison view (Editing Language de, en next to it) and the invariant
 * check are verified.
 */
final class GateWalkthroughTest extends AbstractPageModuleTestCase
{
    private const int PAGE = 30;

    #[Test]
    public function mostlyConnectedSelectivelyDifferent(): void
    {
        // 1. German page with 5 elements, all translated to English
        $previous = self::PAGE;
        foreach ([1, 2, 3, 4, 5] as $number) {
            $dataHandler = $this->process(['tt_content' => ['NEW' . $number => [
                'pid' => $previous, 'sys_language_uid' => 1, 'colPos' => 0, 'CType' => 'text', 'header' => 'Element ' . $number,
            ]]]);
            $previous = -$dataHandler->substNEWwithIDs['NEW' . $number];
        }
        foreach ($this->uidsByHeader(1) as $uid) {
            $this->process([], ['tt_content' => [$uid => ['localize' => 2]]]);
        }
        $this->assertComparisonRows([
            ['Element 1', '[Translate to English:] Element 1'],
            ['Element 2', '[Translate to English:] Element 2'],
            ['Element 3', '[Translate to English:] Element 3'],
            ['Element 4', '[Translate to English:] Element 4'],
            ['Element 5', '[Translate to English:] Element 5'],
        ]);

        // 2. An English-only element between element 2 and 3 ("new content after" in the en column)
        $english = $this->uidsByHeader(2);
        $this->process(['tt_content' => ['NEWx' => [
            'pid' => -$english['[Translate to English:] Element 2'], 'sys_language_uid' => 2, 'colPos' => 0, 'CType' => 'text', 'header' => 'English only',
        ]]]);
        $this->assertComparisonRows([
            ['Element 1', '[Translate to English:] Element 1'],
            ['Element 2', '[Translate to English:] Element 2'],
            ['', 'English only'],
            ['Element 3', '[Translate to English:] Element 3'],
            ['Element 4', '[Translate to English:] Element 4'],
            ['Element 5', '[Translate to English:] Element 5'],
        ]);

        // 3. Hide element 4 in English only (Core's per-language toggle)
        $this->process(['tt_content' => [$english['[Translate to English:] Element 4'] => ['hidden' => 1]]]);
        self::assertSame(1, (int)$this->fetchRow($english['[Translate to English:] Element 4'])['hidden']);
        self::assertSame(0, (int)$this->fetchRow($this->uidsByHeader(1)['Element 4'])['hidden']);

        // 4. Reorder: drag element 5 in the en column behind element 1 (moves all languages)
        $this->process([], ['tt_content' => [$english['[Translate to English:] Element 5'] => ['move' => [
            'action' => 'paste', 'target' => -$english['[Translate to English:] Element 1'], 'update' => ['colPos' => 0, 'sys_language_uid' => 2],
        ]]]]);
        $this->assertComparisonRows([
            ['Element 1', '[Translate to English:] Element 1'],
            ['Element 5', '[Translate to English:] Element 5'],
            ['Element 2', '[Translate to English:] Element 2'],
            ['', 'English only'],
            ['Element 3', '[Translate to English:] Element 3'],
            ['Element 4', '[Translate to English:] Element 4'],
        ]);

        // 5. Later: translate the English-only element to German
        $this->process([], ['tt_content' => [$this->uidsByHeader(2)['English only'] => ['localize' => 1]]]);
        $this->assertComparisonRows([
            ['Element 1', '[Translate to English:] Element 1'],
            ['Element 5', '[Translate to English:] Element 5'],
            ['Element 2', '[Translate to English:] Element 2'],
            ['[Translate to Deutsch:] English only', 'English only'],
            ['Element 3', '[Translate to English:] Element 3'],
            ['Element 4', '[Translate to English:] Element 4'],
        ]);
        $german = $this->fetchRow($this->uidsByHeader(1)['[Translate to Deutsch:] English only']);
        self::assertSame($this->uidsByHeader(2)['English only'], (int)$german['l10n_source']);
    }

    /**
     * @param list<array{0: string, 1: string}> $expected headers per row: [de, en]
     */
    private function assertComparisonRows(array $expected): void
    {
        $html = $this->renderPageModule(self::PAGE, PageViewMode::LanguageComparisonView, ['languages' => [1, 2]]);
        self::assertSame(['1', '2'], $this->headerLanguageIds($html));
        $rows = array_map(
            fn(array $cells): array => array_map(fn(array $uids): string => $uids === [] ? '' : (string)$this->fetchRow($uids[0])['header'], $cells),
            $this->contentRows($html)
        );
        self::assertSame($expected, $rows);
        self::assertDoesNotMatchRegularExpression('#>\s*(Connected|Mixed|Free) Mode\s*<#', $html);
        self::assertStringNotContainsString('t3-page-ce-warning', $html);
        self::assertSame([], $this->get(FlashMessageService::class)->getMessageQueueByIdentifier()->getAllMessages());
        self::assertSame([], array_map(strval(...), $this->get(InvariantChecker::class)->check()));
    }

    /**
     * @param array<string, mixed> $datamap
     * @param array<string, mixed> $cmdmap
     */
    private function process(array $datamap, array $cmdmap = []): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($datamap, $cmdmap);
        $dataHandler->process_datamap();
        $dataHandler->process_cmdmap();
        self::assertSame([], $dataHandler->errorLog, 'DataHandler error log');
        return $dataHandler;
    }

    /**
     * @return array<string, int> uid by header of the not deleted records of a language on the page
     */
    private function uidsByHeader(int $languageId): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder->select('header', 'uid')->from('tt_content')->where(
            $queryBuilder->expr()->eq('pid', self::PAGE),
            $queryBuilder->expr()->eq('sys_language_uid', $languageId),
            $queryBuilder->expr()->eq('deleted', 0),
        )->orderBy('sorting')->executeQuery()->fetchAllKeyValue();
        return array_map(intval(...), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRow(int $uid): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder->select('*')->from('tt_content')->where($queryBuilder->expr()->eq('uid', $uid))->executeQuery()->fetchAssociative();
        self::assertIsArray($row);
        return $row;
    }
}
