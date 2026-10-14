# Repository Guidelines

PoC extension `t3thi/structure-layer` (`EXT:structure_layer`): TYPO3's
`sys_language_uid = 0` becomes a hidden, contentless structure layer; every
real language is a connected translation. Evidence for the Translation
Handling Initiative's v16 decision, **not** production code.

- Plan: `docs/plan.md` (phases, gates, decisions). Findings per phase:
  `docs/findings.md` – update it at the end of every phase.
- Meeting minutes and the T3DD26 analysis live in the Core monorepo
  checkout `~/Code/typo3-cms-15/typo3temp/Documentation/`.

## Setup and commands

The extension is the Composer root; dependencies install into `.Build/`.
TYPO3 is `typo3/cms-*` `dev-main` (v15, PHP 8.5). Use DDEV:

```bash
ddev start && ddev setup     # composer install (applies patches/)
ddev test                    # functional tests, SQLite
ddev test --filter Name      # extra args go to PHPUnit
ddev lint                    # PHPStan level 8 + PHP-CS-Fixer dry-run
ddev cgl-fix                 # apply coding style
ddev demo                    # rebuild https://structure-layer.ddev.site/
```

## Working mode

- Test-first: write a failing functional test, then implement, then prove
  it green. Each phase starts with failing tests (see plan §5).
- Core changes only where no extension point exists, as patch files in
  `patches/` (see `patches/README.md`), one patch per topic. Prefer events,
  hooks, TCA overrides and template overrides in the extension.
- New code consumes `TcaSchemaFactory`, not `$GLOBALS['TCA']`; constructor
  injection, no `GeneralUtility::makeInstance()` for services.
- Coding style: TYPO3 Coding Guidelines via `typo3/coding-standards`
  (`.php-cs-fixer.dist.php`, `.editorconfig` from its templates; JSON uses
  tabs). Same style as Core, so code can move into Core patches unchanged.
- `Tests/Functional/Spike/` holds the throw-away Phase 0 experiments; they
  are excluded from PHPStan and may be deleted once superseded.

## Tests

- Functional tests extend `Tests/Functional/AbstractStructureLayerTestCase`
  (loads the extension, installs a fixture site, renders the frontend via
  `Fixtures/Frontend/JsonRenderer.typoscript`).
- Fixture sites: `Tests/Functional/Fixtures/Sites/` (0 = Structure, 1 de,
  2 en → 1, 3 en-GB → 2,1). Data: CSV, `__DIR__`-relative paths.
- Core's `Tests/` are not shipped in the split packages; copy needed
  fixtures into `Tests/Functional/Fixtures/`.

## Commits

Only commit when asked. Conventional, imperative subjects; no tool or
assistant credits.
