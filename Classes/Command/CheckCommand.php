<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use T3thi\StructureLayer\Integrity\InvariantChecker;
use TYPO3\CMS\Core\Site\SiteFinder;

#[AsCommand('structure-layer:check', 'Report violations of the structure-layer invariants (I1–I5), sorting drift and site configuration rules.')]
final class CheckCommand extends Command
{
    public function __construct(
        private readonly InvariantChecker $invariantChecker,
        private readonly SiteFinder $siteFinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site', InputArgument::OPTIONAL, 'Site identifier; all sites and records if omitted');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $siteIdentifier = (string)$input->getArgument('site');
        $site = $siteIdentifier !== '' ? $this->siteFinder->getSiteByIdentifier($siteIdentifier) : null;
        $violations = $this->invariantChecker->check($site);
        $errors = array_filter($violations, static fn($violation): bool => !$violation->isNotice);
        if ($violations !== []) {
            $io->listing(array_map(strval(...), $violations));
        }
        if ($errors !== []) {
            $io->error(count($errors) . ' violation(s)');
            return Command::FAILURE;
        }
        $io->success('No violations');
        return Command::SUCCESS;
    }
}
