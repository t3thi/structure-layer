# PoC findings: Default Language as a Hidden Structural Layer

Companion to `plan.md`. One section per phase.

---

## Phase 0: Spikes and fixtures (2026-10-14)

### Deliverables

- The spikes first ran inside the Core monorepo as a system extension.
  They were then moved to this standalone extension (`t3thi/structure-layer`,
  Composer root, `typo3/cms-*` `dev-main`, DDEV), with identical results.
  - v15 requires `extra.typo3/cms.version` and
    `extra.typo3/cms.Package.providesPackages` in `composer.json`
    (Deprecation #108345), or the testing framework fails with
    `InvalidPackageManifestException`.
  - Core's `Tests/` folders are `export-ignore`d in the split packages, so
    `JsonRenderer.typoscript` and `be_users_admin.csv` are copied into
    `Tests/Functional/Fixtures/`.
- Fixture sites `Tests/Functional/Fixtures/Sites/`:
  - `structure-site`: 0 = Structure (disabled, `/_structure/`), 1 de `/`
    (no chain), 2 en `/en/` (chain `1`), 3 en-GB `/en-gb/` (chain `2,1`).
  - `structure-site-primary-first`: the same, with language 0 listed last.
- Fixture data `Tests/Functional/Fixtures/StructureSite.csv`: shadow root
  page 1 with de/en/en-GB translations; shadow page 10 with de/en only;
  content shadows 100/110/120 with de and en translations (en of 110
  hidden; 120 en only) and one `-1` element 130.
- `Tests/Functional/AbstractStructureLayerTestCase.php`: installs a fixture
  site, renders the frontend with the Core `JsonRenderer`.
- Spike tests in `Tests/Functional/Spike/` (throw-away, all green):
  `SiteLanguageOrderSpikeTest` (S1), `ShadowInjectionSpikeTest` +
  `Fixtures/ShadowInjectionSpikeHook` (S2, S4), `OutputBaselineSpikeTest`
  (S5 baseline).

Run: `ddev test` (SQLite). The spike directory is excluded from PHPStan.

### S1: YAML order of language 0 → **Option A (language 0 first)**

Experiment: both orderings route identically. `/`, `/en/`, `/en-gb/`,
`/ueber-uns`, `/en/about-us` resolve to the right language and page;
`/_structure/` gives 404 for anonymous visitors (`SiteBaseRedirectResolver`
rejects disabled languages without BE user). `BestUrlMatcher` picks the
longest base prefix, so order only matters for identical bases.
`getDefaultLanguage()` is `reset($languages)`, i.e. YAML order.

Code reading (~30 callers): Option B (primary first) breaks things that
mean *record language 0* – `PageLayoutContext` (language 1 shown twice, real
0 column lost), `PreviewSimulator:139` (hidden check of the base record),
`TranslationConfigurationProvider:88`, and the third-party convention
`getDefaultLanguage()->getLanguageId() === 0`. Option A breaks a few places
that mean *primary output language*:

| Caller | Effect under A | Patch |
|---|---|---|
| `PageRouter.php:246` (`generateUri()` without `_language`) | links to `/_structure/…` (BE "View", link preview, webhooks, workspaces preview) | use primary language |
| `PageLinkBuilder.php:613, 636, 965` (no request: CLI, mail, scheduler) | structure URLs | use primary language |
| `PolicyProvider.php:153` (CSP reporting URI) | `/_structure/@http-reporting` (reads a never-set `siteLanguage` attribute – separate Core bug candidate) | read `language` attribute, fall back to primary |
| `SiteMatcher.php:106`, workspaces `PreviewUriBuilder.php:79,181` | minor fallbacks to 0 | optional |

Already safe: `SiteBaseRedirectResolver`, `PageContentErrorHandler`,
hreflang (`x-default` = first entry of the language menu, which excludes
disabled languages). The site module always (re)adds language 0 on top
(`TcaSiteLanguage.php:198-211`), which fits A. Not yet verified: whether
saving in the Sites module keeps the YAML order.

**Core patch:** `Site::getPrimaryLanguage()` (+ interface, `NullSite`):
`getDefaultLanguage()` if enabled, else first of `getLanguages()`. Use it in
the three callers above. Keep the planned extension helper
`StructureLayer::getPrimaryLanguage()` only if Core should stay untouched
for this.

### S2: shadow injection in `processDatamap_beforeStart` → **works**

- A NEW shadow added to `$dataHandler->datamap` in front of the record is
  created first; the record's `l18n_parent = <NEW shadow id>` is resolved
  via the remap stack (select field with `foreign_table`).
- **Pitfall:** the NEW id of the shadow must not contain `_`.
  `DataHandler::processRemapStack()` reads `a_NEWb` as table `a` + id
  `NEWb`; `NEWshadow_NEW1` silently resolved to `l18n_parent = 0`. Use
  e.g. `<id>shadow`.
- `DataMapProcessor` synchronizes `l10n_mode=exclude` fields from the
  **in-memory** NEW shadow: `CType`/`colPos` submitted for the translation
  were moved to the shadow by the hook and still arrived on the translation.
- `l10n_source` stays `0` (content created directly in the language), no
  `sys_log` errors, clean result.
- `pid < 0` ("insert after translation X") → hook maps to
  `-(X.l18n_parent)`; the shadow lands between the right shadows.
- **Sorting drift is real and immediate (component C is mandatory):**
  - plain create on top of a page: shadow `128`, translation `64`;
  - insert-after: shadow `1024`, translation `512`.
- **Write amplification (SCALE-1):** when translations mirror the shadow's
  `sorting`, an "insert after shadow S" finds S's own translation as the
  next row (same value). `getSortNumber()` finds no gap and calls
  `increaseSortingOfFollowingRecords()`, which renumbers every following
  row on the page, in all languages. In the spike one insert renumbered
  the page twice (shadow + translation). Component C should compute the
  shadow position ignoring translations, or C needs its own sorting
  routine.

### S3: synchronizable fields (current TCA)

- **tt_content has no `allowLanguageSynchronization` field at all.**
  `State::isApplicable('tt_content')` is false, so `l10n_state` handling
  is irrelevant for content in v1.
  - Structural (`l10n_mode=exclude`): `CType`, `colPos`, `starttime`,
    `endtime`.
  - Not flagged but structural in the PoC: `sorting` (no column),
    `fe_group` and `editlock` (no `l10n_mode`; the plan's `fe_group →
    exclude` override is needed).
- **pages:**
  - `exclude`: `TSconfig`, `tsconfig_includes`, `php_tree_stop`, `layout`,
    `extendToSubpages`, `target`, `cache_timeout`, `cache_tags`,
    `mount_pid`, `mount_pid_ol`, `is_siteroot`, `module`, `l18n_cfg`,
    `backend_layout`, `backend_layout_next_level`, `editlock`, `no_index`,
    `no_follow`.
  - `allowLanguageSynchronization`: `nav_hide`, `lastUpdated`,
    `newUntil`, `no_search`, `shortcut`, `shortcut_mode`,
    `content_from_pid`, `author`, `author_email`, `media`, `link`,
    `starttime`, `endtime`, `og_image`, `twitter_image`,
    `tx_themecamino_logo`.
  - No flags: `doktype`, `fe_group`, `hidden`, `nav_title`.
  - **Correction to §3.3:** `doktype` is not `exclude` in Core. The
    extension must make it `exclude`, or the shadow and the translations
    can diverge.
- **sys_file_metadata:**
  - `exclude`: the technical fields (`width`, `height`, `pages`,
    `duration`, `unit`, `color_space`, `language`, `latitude`,
    `longitude`, dates, `creator*`, `publisher`, `download_name`).
  - Sync: `location_country`, `location_region`, `location_city`.
- **Mechanism:** `DataMapProcessor` acts on exactly `l10n_mode=exclude`
  plus `allowLanguageSynchronization`. Nothing adds either automatically.
  `columnsOverrides` cannot set sync (it is stripped by `TcaMigration`).

### S4: empty shadows vs. `required` / `uniqueInSite` → **no blocker**

- `DataHandler::validateValueForRequired()` drops an empty required value
  without logging. A shadow page with an empty `title` is created without
  any `sys_log` entry, and FormEngine is not involved.
- The shadow slug becomes `/default-<md5>` (SlugHelper fallback). It is
  unique per shadow, so there are no collisions, and it is only reachable
  below `/_structure/`. The translation gets its own slug
  (`/neue-seite`). Cosmetic issue for the migration and the page tree.
- `pages.link` is also `required` (only for doktype link). It is
  synchronizable, so it stays on the translation. Not an issue for empty
  shadows.
- Other `required` fields in Core content TCA: only inside
  `theme_camino` columnsOverrides and inline children. Not shadow-relevant.
- Open for Phase 3: FormEngine editing of a shadow (structure column)
  would complain about the empty required title. The plan already avoids
  editing shadow payloads.

### S5: effective `fallbackType` and overlay events

**Patch location → `SiteLanguage::__construct`** (after the `fallbacks`
parsing, ~line 152):

- for `languageId > 0`, drop `0` and itself from `fallbackLanguageIds`;
- set `fallbackType = fallbackLanguageIds === [] ? 'free' : 'fallback'`.

`LanguageAspectFactory` is not enough. Three consumers read the
`SiteLanguage` value directly:

- `PageLinkBuilder:221`;
- the TS condition `siteLanguage("fallbackType")`;
- getData via `toArray()`.

Stripping `0` also fixes `PageSlugCandidateProvider` and `SlugHelper`
uniqueness. `toArray()` still exposes the raw `fallbacks`, so align it.

**Baseline without patches** (`OutputBaselineSpikeTest`, fixture site):

| URL | Overlay | Rendered |
|---|---|---|
| `/` (de, no chain) | `includeFloating` | Element 1 (de), Element 2 (de), Element 4 (-1) |
| `/en/` (chain 1) | `includeFloating` | Element 1 (en), **Element 2 (de)**, Element 3 (en only), Element 4 |
| `/en-gb/` (chain 2,1) | `includeFloating` | Element 1 (en), **Element 2 (de)**, Element 3 (en only), Element 4 |
| `/en-gb/about-us` (no en-GB page) | – | page fallback to en "About us" |

- A missing `fallbackType` defaults to `strict`
  (`SiteLanguage.php:89`, `ON_WITH_FLOATING`).
- **Even `strict` walks the fallback chain per record:** the hidden
  "Element 2 (en)" is replaced by its German version. This is exactly the
  "disabled translation must block fallback" case for the listener.
- No empty shadow was rendered: under ON/ON_WITH_FLOATING, unoverlaid
  rows are dropped.

**Events** (code reading, to be proven test-first in Phase 1):

- `AfterRecordLanguageOverlayEvent` (`PageRepository:431`)
  - Can replace or null the localized record; null drops it from
    CONTENT, RECORDS and Extbase.
  - It cannot tell "missing" from "exists but disabled", because the
    overlay query uses frontend restrictions. The listener needs its own
    query, with only Deleted/Workspace restrictions plus the preview
    `VisibilityAspect`, and only when the result is the shadow or a chain
    language.
  - Under `OVERLAYS_OFF` it still fires with `overlayingWasAttempted =
    false`, so shadow-UID references (RECORDS, shortcut) can be resolved.
- **Gap:** Extbase list queries under `OVERLAYS_OFF` never call
  `getLanguageOverlay()`, so no event fires. `findByUid` and relations are
  switched to MIXED and do fire.
- **Never return null for `pages`:** `getPage()` is typed `: array`, and
  `RootlineUtility` expects an array, so null causes a TypeError.
- **Pages** use `getPagesOverlay()`, which only has
  `BeforePageLanguageOverlayEvent` (no after-event).
  - A listener can remove page ids whose requested-language translation
    exists but is disabled.
  - PageInformationFactory then walks the chain to `pageNotFound` → 404.
  - Menus and sitemap drop the page, the language menu shows it as not
    available, and hreflang skips it.
  - The listener must scope itself to frontend use: `PageRouter:677`,
    redirects `SlugService` and backend callers also hit it.
- **Page-level chain fallback switches `contentId` for the whole page**
  (`PageInformationFactory:357-367`). Content on a page that fell back to
  `de` is then taken from `de`, not resolved per record from the
  requested language. Accept this for the PoC or patch it.

### Gate result

Workable. Minimal Core patch list (refines plan §6):

1. `SiteLanguage::__construct`: derive the effective `fallbackType` from
   `fallbacks`; strip `0` and self from the chain (§6.6).
2. `Site::getPrimaryLanguage()` plus its use in `PageRouter:246`,
   `PageLinkBuilder:613/636/965` and `PolicyProvider:153` (§6.4).
3. `PageLinkBuilder:221`: decide linkability by "no `_LOCALIZED_UID` and
   no chain", not by `fallbackType === 'strict'`.
4. Possibly `DataHandler::getSortNumber()` or a custom sorting routine
   for C (write amplification, see S2).

Everything else (overlay veto, disabled page → 404) looks reachable via
events.

### Corrections to the plan

- §3.3: `pages.doktype` must be made `l10n_mode=exclude` by the
  extension; it is not `exclude` in Core.
- §3.3: tt_content has no synchronizable fields; the `l10n_state=custom`
  step in B only matters for pages and sys_file_metadata.
- §4 B: the NEW shadow id must not contain `_`.
- §4 C: mirroring `sorting` exactly causes page-wide renumbering on every
  "insert after". Address this in Phase 2.
- §2.3 / Phase 0: "fixture extension" → fixture site YAML + CSV were
  enough. A fixture extension is only needed once the extension needs
  TypoScript or PAGEVIEW templates.

---

## Phase 1: Chain-driven frontend output (2026-10-14)

### Deliverables

- Core patch `patches/cms-core-site-language-effective-fallback-type.patch`
  (`SiteLanguage::__construct`, as decided in S5): for every language
  `> 0`, `0` and the language itself are stripped from `fallbacks`, and
  `fallbackType` becomes `free` (no chain) or `fallback` (chain). The
  configured `fallbackType` is ignored; `toArray()['fallbacks']` is aligned.
- Event listeners (all sites, no switch):
  - `EnforceRecordOutputPolicy` (`AfterRecordLanguageOverlayEvent`, all
    tables except `pages`):
    - MIXED result still in language 0 → `null` (no shadow is ever output);
    - result from a chain language while a translation in the requested
      language exists (deleted/workspace restrictions only) → `null`;
    - no overlay attempted (free mode) and the row is a shadow → resolved
      along `[requested, ...chain]` to the translation row itself (its own
      uid, like every other free-mode row), or `null`.
  - `EnforcePageOutputPolicy` (`BeforePageLanguageOverlayEvent`, only for
    languages with a chain): page ids whose requested-language translation
    exists but is not visible are dropped from the overlay lookup. The
    shadow then stays untranslated → 404, menu item omitted
    (`l18n_cfg` bit 1 on the shadow), language menu entry unavailable.
  - `AddHrefLangXDefault` (`ModifyHrefLangTagsEvent`, after EXT:seo), see
    below.
  - Shared lookup `Classes/Frontend/TranslationLookup`.
- Tests (`Tests/Functional/Frontend/`, data `Fixtures/ChainOutput.csv`):
  `ChainDrivenOutputTest` (content, pages, menus, language menu,
  shortcuts, categories, `t3://record`), `ExtbaseOutputTest` (fixture
  extension `Tests/Functional/Fixtures/Extensions/structure_layer_extbase_test`,
  `tt_content` model, plugin rendered in a real frontend request),
  `HrefLangTest` (EXT:seo). 59 new tests. Without the listeners, 14 fail;
  without the patch, the overlay types are wrong on every language.
- Site fixture `structure-site-legacy-fallback-types`: `fallbackType`
  `fallback`/`strict`/`free` and `0` in `fallbacks`. Every content and page
  test runs on both sites with identical expectations: legacy values make
  no difference.
- New dev dependencies: `typo3/cms-extbase` (was transitive),
  `typo3/cms-seo` (hreflang).

### Results (gate: met)

| Case | de (no chain) | en (chain 1) | en-GB (chain 2,1) |
|---|---|---|---|
| Overlay type | `off` | `mixed` | `mixed` |
| Element translated everywhere | own | own | own |
| en disabled, de exists | de | **nothing** | de (chain continues past disabled en) |
| en only | – | en | en |
| `-1` element | ✓ | ✓ | ✓ |
| en-GB disabled | de | en | **nothing** |
| Hidden in all languages | – | – | – |
| Shortcut (RECORDS) to a shadow | resolved to de | en / nothing if en disabled | en-GB / de |
| Extbase `findAll()` | same as CONTENT | same as CONTENT | same as CONTENT |
| Extbase `findByUid(shadow)` | de translation | en / `null` if disabled | chain |
| Disabled page translation | 404 | 404, no fallback | chain continues |
| Missing page translation | 404 | page fallback | page fallback |
| Menus | follow the same rules | ✓ | ✓ |
| `/_structure/…` | 404 | | |

Deleting the disabled en translation makes the de fallback reappear
(no stored "absent" state, 2026-10-09 Topic 6).

### Findings

- **Hand-crafted data must mirror `l10n_mode=exclude`.** Frontend rootlines
  and page records are overlaid, so `exclude` fields come from the
  *translation*. A `TSconfig` set only on the shadow page is invisible in
  the frontend (here: no `TCEMAIN.linkHandler` → `t3://record` unlinked).
  DataHandler copies these fields; fixtures and the migration (I) must too.
- **No shadow reaches the output**: under MIXED only via the listener
  (Core keeps the language-0 row when the chain has nothing), under OFF
  only because CONTENT never selects language 0, plus the listener for
  uid-based fetches.
- **Gaps (characterized by tests, not fixed):**
  - *Category relations in no-chain languages:* the MM table stores the
    shadow category uid. A language-restricted `CONTENT` query on
    `sys_category` (the common TypoScript pattern, e.g. JsonRenderer)
    finds nothing in free mode. The same query with `languageField = 0`
    fetches the shadow, and the listener resolves it. This is today's
    free-mode limitation, and it now affects the primary language. Either
    integrators drop the language restriction for relation queries, or Core
    resolves relations through the structure layer.
  - *`t3://record` in no-chain languages:* `DatabaseRecordLinkBuilder`
    checks for a translation only when `doOverlays()`. A link to a record
    without a translation in the requested language is still built (dead
    link). `BeforeDatabaseRecordLinkResolvedEvent` can supply a record but
    cannot veto. Patch candidate: also check in free mode.
  - *Extbase `count()` with a chain:* SQL counts language-0/-1 rows (6),
    the listener drops rows afterwards (4 rendered). Limits and pagination
    have the same issue, as do TypoScript `CONTENT` with `max`/`begin`.
    Without a chain, the counts are correct (the requested language is
    selected directly).
- **hreflang `x-default`:** EXT:seo sets it only if the page exists in the
  first site language. That is almost always true with a real default
  language, but not with the structure layer (a page may lack its primary
  language). `AddHrefLangXDefault` falls back to the first available
  language. Upstream candidate, independent of the PoC.
- **Cost (SCALE-1, counted from the code; to be measured in Phase 6):**
  - MIXED: +1 `COUNT` query per record that comes from a chain language
    (none for records in the requested language);
  - free mode: +1–2 queries per shadow fetched by uid;
  - pages with a chain: +1–2 queries per `getPagesOverlay()` call (menus
    call it per level; not runtime-cached yet).
- **Not covered by events:** workspace overlays in the listeners
  (live records only, Phase 6). Page-level fallback still switches
  `contentId` for the whole page (Phase 0 finding, accepted).
- `composer reinstall` does not work for `dev-main` packages (removes the
  package, then fails). `patches/README.md` was corrected.

### Patch list after Phase 1

1. ✅ `SiteLanguage::__construct` (effective `fallbackType`, no `0` in chains).
2. Open, unchanged from Phase 0: `Site::getPrimaryLanguage()` and its
   callers, `PageLinkBuilder:221`, sorting (C).
3. New candidate: `DatabaseRecordLinkBuilder` translation check in free mode.
