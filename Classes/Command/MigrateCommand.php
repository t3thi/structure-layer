<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use T3thi\StructureLayer\Migration\Migrator;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Plan §4 I. Rollback means a database snapshot; the dry run only reports (and writes a JSON plan).
 */
#[AsCommand('structure-layer:migrate', 'Migrate a site to the structure layer: language 0 becomes the hidden structure, the old default content a regular language.')]
final class MigrateCommand extends Command
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly SiteFinder $siteFinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site', InputArgument::REQUIRED, 'Site identifier');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Run in a rolled-back transaction, write no site configuration');
        $this->addOption('plan', null, InputOption::VALUE_REQUIRED, 'Write the report as JSON to this file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool)$input->getOption('dry-run');
        $report = $this->migrator->migrate($this->siteFinder->getSiteByIdentifier((string)$input->getArgument('site')), $dryRun);

        $plan = (string)$input->getOption('plan');
        if ($plan !== '') {
            file_put_contents($plan, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
        $io->title(($dryRun ? 'Dry run: ' : '') . 'old default language becomes language ' . $report->newLanguageId);
        $io->listing($report->siteChanges);
        $rows = [];
        foreach ($report->counts as $table => $counts) {
            foreach ($counts as $counter => $count) {
                $rows[] = [$table, $counter, $count];
            }
        }
        $io->table(['Table', 'Counter', 'Count'], $rows);
        foreach (['Connection candidates (never connected automatically)' => $report->connectionCandidates, 'Conflicts' => $report->conflicts, 'Violations' => $report->violations] as $title => $messages) {
            if ($messages !== []) {
                $io->section($title);
                $io->listing($messages);
            }
        }
        if ($report->violations !== []) {
            $io->warning('structure-layer:check reports violations');
            return Command::FAILURE;
        }
        $io->success($dryRun ? 'Dry run finished, nothing written' : 'Site migrated');
        return Command::SUCCESS;
    }
}
