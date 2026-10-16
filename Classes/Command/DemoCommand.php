<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use T3thi\StructureLayer\Integrity\InvariantChecker;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Seeds the demo instance (Build/Scripts/setup-demo-instance.sh) with the start state of the
 * Phase 3 gate walkthrough: a German page with five elements, all translated to English.
 *
 * Everything except the root page goes through DataHandler, so the structure-layer hooks
 * create the shadows exactly as in real editing. Expects the fresh instance of `typo3 setup`:
 * root page 1 in language 0 and a site configuration with the structure layer (0) and de (1),
 * en (2), en-GB (3).
 */
#[AsCommand('structure-layer:demo', 'Seed the demo instance with structure-layer content (destroys existing content).')]
final class DemoCommand extends Command
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly InvariantChecker $invariantChecker,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        Bootstrap::initializeBackendAuthentication();

        // Root page 1 from `typo3 setup` becomes the shadow; its title moves to the German translation.
        $this->connectionPool->getConnectionForTable('tt_content')->truncate('tt_content');
        $title = (string)$this->connectionPool->getConnectionForTable('pages')->select(['title'], 'pages', ['uid' => 1])->fetchOne();
        $this->connectionPool->getConnectionForTable('pages')->update('pages', ['title' => '', 'l18n_cfg' => 1], ['uid' => 1]);
        $german = $this->localize('pages', 1, 1);
        $this->process(['pages' => [$german => ['title' => $title === '' ? 'Startseite' : $title, 'hidden' => 0]]]);
        $english = $this->localize('pages', $german, 2);
        $this->process(['pages' => [$english => ['title' => 'Home', 'slug' => '/', 'hidden' => 0]]]);

        // A subpage that exists in German only (empty state in the Layout module, page fallback for en).
        $this->process(['pages' => ['NEWpage' => ['pid' => 1, 'sys_language_uid' => 1, 'title' => 'Nur auf Deutsch', 'hidden' => 0]]]);

        // Gate start state: five German elements, all translated to English.
        $previous = 1;
        for ($number = 1; $number <= 5; $number++) {
            $dataHandler = $this->process(['tt_content' => ['NEW' . $number => [
                'pid' => $previous,
                'sys_language_uid' => 1,
                'colPos' => 0,
                'CType' => 'text',
                'header' => 'Element ' . $number,
                'bodytext' => '<p>Deutscher Inhalt von Element ' . $number . '.</p>',
            ]]]);
            $uid = (int)$dataHandler->substNEWwithIDs['NEW' . $number];
            $translation = $this->localize('tt_content', $uid, 2);
            $this->process(['tt_content' => [$translation => [
                'header' => 'Element ' . $number,
                'bodytext' => '<p>English content of element ' . $number . '.</p>',
                'hidden' => 0,
            ]]]);
            $previous = -$uid;
        }

        // Phase 5, move disambiguation: elements that exist in one language only lie between the visible ones.
        $dataHandler = $this->process(['pages' => ['NEWmoves' => ['pid' => 1, 'sys_language_uid' => 1, 'title' => 'Verschieben', 'hidden' => 0]]]);
        $movesPage = (int)$dataHandler->substNEWwithIDs['NEWmoves'];
        $movesShadow = (int)($this->connectionPool->getConnectionForTable('pages')->select(['l10n_parent'], 'pages', ['uid' => $movesPage])->fetchOne());
        $english = $this->localize('pages', $movesPage, 2);
        $this->process(['pages' => [$english => ['title' => 'Moving', 'hidden' => 0]]]);
        $previous = $movesShadow;
        // header, language it is written in, header of an English translation (null: none)
        $elements = [['A', 1, 'A'], ['B (nur Deutsch)', 1, null], ['C (English only)', 2, null], ['D', 1, 'D']];
        foreach ($elements as [$header, $languageId, $englishHeader]) {
            $dataHandler = $this->process(['tt_content' => ['NEWmove' => [
                'pid' => $previous,
                'sys_language_uid' => $languageId,
                'colPos' => 0,
                'CType' => 'text',
                'header' => $header,
            ]]]);
            $uid = (int)$dataHandler->substNEWwithIDs['NEWmove'];
            if ($englishHeader !== null) {
                $translation = $this->localize('tt_content', $uid, 2);
                $this->process(['tt_content' => [$translation => ['header' => $englishHeader, 'hidden' => 0]]]);
            }
            $previous = -$uid;
        }

        $violations = $this->invariantChecker->check();
        if ($violations !== []) {
            $io->listing(array_map(strval(...), $violations));
            $io->error('Seeded, but structure-layer:check reports violations');
            return Command::FAILURE;
        }
        $io->success('Demo content seeded');
        return Command::SUCCESS;
    }

    private function localize(string $table, int $uid, int $languageId): int
    {
        $dataHandler = $this->process([], [$table => [$uid => ['localize' => $languageId]]]);
        return (int)($dataHandler->copyMappingArray_merged[$table][$uid] ?? 0);
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $datamap
     * @param array<string, array<int, array<string, mixed>>> $cmdmap
     */
    private function process(array $datamap, array $cmdmap = []): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($datamap, $cmdmap);
        $dataHandler->process_datamap();
        $dataHandler->process_cmdmap();
        if ($dataHandler->errorLog !== []) {
            throw new \RuntimeException(implode("\n", $dataHandler->errorLog), 1792050000);
        }
        return $dataHandler;
    }
}
