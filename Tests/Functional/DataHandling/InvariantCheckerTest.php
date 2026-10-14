<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\DataHandling;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use T3thi\StructureLayer\Command\CheckCommand;
use T3thi\StructureLayer\Integrity\InvariantChecker;

/**
 * Phase 2 gate tool: structure-layer:check (plan §3.1, components A and C) reports every violation.
 */
final class InvariantCheckerTest extends AbstractDataHandlingTestCase
{
    #[Test]
    public function fixtureDataIsClean(): void
    {
        $this->assertInvariantsHold();
    }

    #[Test]
    public function everyRecordInvariantViolationIsReported(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Violations.csv');
        $violations = array_map(strval(...), $this->get(InvariantChecker::class)->check());
        self::assertEqualsCanonicalizing([
            '[I2] tt_content:300 translation without shadow (l18n_parent = 0)',
            '[I2] tt_content:301 shadow tt_content:999 does not exist or is deleted',
            '[I2] tt_content:302 shadow tt_content:110 is on page 1, translation on page 10',
            '[I3] tt_content:303 second translation of tt_content:100 in language 1 (first: 101)',
            '[I4] tt_content:310 shadow without translation',
            '[I5] tt_content:320 shadow is hidden',
            '[I1] tt_content:330 shadow carries payload in "header"',
            '[C] tt_content:341 sorting 5999 differs from shadow tt_content:340 (6000)',
        ], $violations);
    }

    #[Test]
    public function siteConfigurationViolationsAreReported(): void
    {
        $this->installSiteConfiguration('structure-site-legacy-fallback-types');
        $violations = array_map(strval(...), $this->get(InvariantChecker::class)->check());
        self::assertEqualsCanonicalizing([
            '[A] site structure-site: language 1 has 0 in fallbacks',
            '[A] site structure-site: language 2 has 0 in fallbacks',
            '[A, notice] site structure-site: language 1 fallbackType "fallback" is ignored',
            '[A, notice] site structure-site: language 2 fallbackType "strict" is ignored',
            '[A, notice] site structure-site: language 3 fallbackType "free" is ignored',
        ], $violations);
    }

    #[Test]
    public function commandFailsOnViolationsAndPassesOnCleanData(): void
    {
        $tester = new CommandTester($this->get(CheckCommand::class));
        self::assertSame(Command::SUCCESS, $tester->execute(['site' => 'structure-site']));

        $this->importCSVDataSet(__DIR__ . '/Fixtures/Violations.csv');
        self::assertSame(Command::FAILURE, $tester->execute(['site' => 'structure-site']));
        self::assertStringContainsString('[I4] tt_content:310 shadow without translation', $tester->getDisplay());
    }
}
