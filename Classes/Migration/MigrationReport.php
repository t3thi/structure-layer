<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Migration;

/**
 * Result of a structure-layer migration (plan §4 I, step 7): absolute counts for MIG-1/SCALE-1
 * and everything an editor has to look at afterwards.
 */
final class MigrationReport
{
    public bool $dryRun = false;

    /** @var array<string, mixed> */
    public array $siteConfiguration = [];

    public int $newLanguageId = 0;

    /** @var array<string, array<string, int>> table => counter => count */
    public array $counts = [];

    /** @var list<string> site configuration changes, e.g. moved inactive fallbacks */
    public array $siteChanges = [];

    /** @var list<string> Free-Mode records whose l10n_source suggests a match (never connected automatically) */
    public array $connectionCandidates = [];

    /** @var list<string> values that cannot be kept, e.g. a per-language fe_group of a now structural field */
    public array $conflicts = [];

    /** @var list<string> violations reported by structure-layer:check after the migration */
    public array $violations = [];

    public function count(string $table, string $counter, int $increment = 1): void
    {
        $this->counts[$table][$counter] = ($this->counts[$table][$counter] ?? 0) + $increment;
    }

    public function get(string $table, string $counter): int
    {
        return $this->counts[$table][$counter] ?? 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dryRun' => $this->dryRun,
            'newLanguageId' => $this->newLanguageId,
            'counts' => $this->counts,
            'siteChanges' => $this->siteChanges,
            'connectionCandidates' => $this->connectionCandidates,
            'conflicts' => $this->conflicts,
            'violations' => $this->violations,
            'siteConfiguration' => $this->siteConfiguration,
        ];
    }
}
