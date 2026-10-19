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

---

## Phase 2: DataHandler behavior (2026-10-14)

### Deliverables

- `Classes/DataHandling/DataHandlerHook` (datamap + cmdmap hook, registered in
  `ext_localconf.php`). It acts only in the **outermost** DataHandler: nested
  instances are DataHandler's own copy/localize runs, which already carry
  complete rows.
  - **B, creation.** A NEW record in a language `> 0` without a parent gets
    a NEW shadow (`<id>shadow`) in front of it. Structural fields move to
    the shadow, and pages get `l18n_cfg = 1` and a "custom" `l10n_state`.
    A NEW record in **language 0** (page tree, structure column) becomes a
    shadow (keeps the NEW id) plus a translation in the primary language
    (`<id>translation`).
  - **§3.3, structural edits.** Structural fields (= `l10n_mode=exclude`)
    submitted for a translation are written to the shadow;
    DataMapProcessor pushes them to all languages.
  - **H, hide in all languages.** `hidden` written on a *shadow* means "in
    all languages": it is written on every translation, and the shadow
    stays visible. For pages, `starttime`/`endtime` are handled the same
    way. This is the DataHandler API the meeting asked for; the Layout
    module button (Phase 3) only has to send `hidden` for the shadow uid.
  - **D, move and copy.** A move or copy of a translation becomes a move or
    copy of its shadow, relative to the target's shadow. A
    `sys_language_uid` in the paste `update` is dropped (also for `-1`
    records).
  - **E, delete and restore.**
    - Deleting the last translation deletes the shadow (I4).
    - Restoring a translation restores its shadow.
    - Restoring a shadow restores the translations deleted *together with
      it* (same `tstamp`); translations deleted earlier stay deleted.
      Decision for E: restore, not block.
  - **C, sorting alignment.** After every outermost run, the translations on
    the touched pages get the `sorting` of their shadow (direct `UPDATE`,
    like DataHandler's own resorting).
  - **Copy repair.** `copyRecord()` applies `hideAtCopy` and `prependAtCopy`
    to the first copied record, i.e. the shadow. The hook moves "hidden" to
    the copied translations and drops the label prefix.
- TCA overrides: `tt_content.fe_group`, `pages.doktype` and `pages.fe_group`
  get `l10n_mode = exclude`.
- `structure-layer:check [site]` (`Classes/Integrity/InvariantChecker`,
  `Classes/Command/CheckCommand`). It checks I1 (shadow label empty), I2, I3,
  I4, I5 (hidden; time window for pages), C (sorting drift), and A (language
  0 disabled with its own base, no `0` in `fallbacks`; `fallbackType` as a
  notice).
- Core patches (both test-first, both red before):
  - `cms-core-datahandler-localize-from-translation.patch`:
    - duplicate detection by the source's `l10n_parent` (I3);
    - page localize from a translation keeps `l10n_parent`.
  - `cms-core-datahandler-sort-number-ties.patch`: `getSortNumber()` looks
    for the next *greater* sorting value.
- Tests: `Tests/Functional/DataHandling/` (42 tests, data
  `Fixtures/StructureData.csv`). Every scenario ends with the invariant
  check. Plain PHP assertions instead of CSV result files: the scenarios
  assert relations (order, parents, languages), not absolute `sorting`
  values.

### Scenario results (gate: met, check clean after every scenario)

| # | Scenario | Result |
|---|---|---|
| 1 | Create in language 2 only (content, page) | shadow + connected record; `l10n_source = 0`; shadow visible even if the translation is hidden |
| 1b | Create in language 0 (page tree, structure column) | shadow + translation in the primary language (de) |
| 2 | Insert after a translation (same or other language), top of page | shadow at the right position; **no other row renumbered** |
| 3 | Translate en → de / en → en-GB / page en → en-GB | parent = shadow, source = the translation; duplicate rejected (logged) |
| 4 | Edit `colPos`, `CType`, `fe_group`, `doktype` from a translation | written to the shadow, synchronized to all languages; payload untouched |
| 5 | Synchronizable page fields | per language; survive structural edits and translate |
| 6 | Move translation (same page, other language target, other page, drag & drop with language change, `-1`) | always all languages; language never changes |
| 7 | Delete last / one of two translations | shadow deleted / kept |
| 8 | Delete shadow; restore in both directions | cascade; restore as described above |
| 9 | Copy shadow, copy translation, copy page | all languages copied, invariants hold |
| 10 | Inline file reference of content created in language 2 | language 2, no shadow, attached to the translation (characterization) |
| 11 | Hide/show in all languages; hide one language | every translation / only one; shadow never hidden |
| 12 | Sorting equals shadow sorting | after create, insert, move, localize, copy (check rule C) |

### Findings

- **Write amplification (SCALE-1).** Phase 0 measured a page-wide renumbering
  on every "insert after". With the tie patch, an insert changes no existing
  row. Alignment C costs one `SELECT` per touched page and one `UPDATE` per
  shadow on it (most match 0 rows). This is O(shadows per page) statements
  per operation, to be measured in Phase 6.
- **Core bug candidates, independent of the PoC (Forge):**
  - Translating from a non-default translation into a language that
    already has the element creates a duplicate.
    `LocalizationRepository::getRecordTranslation()` matches
    `l10n_source = uid OR (l10n_source = 0 AND l10n_parent = uid)`, so a
    record with another source is missed.
  - Localizing a *page* from a page translation creates an **unconnected**
    page (`l10n_parent = 0`): `localizePage()` builds the page from
    `$overrideValues` only, and for non-default sources those lack the
    parent.
- **Page translate copies little.** `localizePage()` copies only
  `prefixLangTitle` fields (title with "[Translate to …:]"), not e.g.
  `nav_hide`. Translating a page from German therefore does not take over
  German page properties. With an empty shadow there is no other source.
  To decide in Phase 3 (translation wizard) whether that is acceptable.
- **`l10n_state` is mandatory for page translations.** Without it,
  DataMapProcessor treats synchronizable fields as "parent":
  - an update of the shadow blanks them on every translation;
  - an update of the translation itself silently drops the submitted value.
  The hook marks all synchronizable fields "custom" on create, translate,
  and before any shadow or translation update. The migration (Phase 4)
  must do it for all existing page translations.
- **`tt_content.starttime/endtime` are `exclude` in Core.** They live on
  the shadow and are shared by all languages. Plan I5 ("time window stays
  open") therefore holds only for pages; for content a shared time window
  is consistent. The check enforces I5's time window for pages only.
- **I4 for pages is drastic.** Deleting the last page translation deletes
  the shadow page, and with it the whole subtree (subject to recursive
  delete permissions; untested with subpages). Open for Phase 5
  (permissions).
- **Restore heuristic.** "Deleted together" is identified by an equal
  `tstamp`. A real implementation should use the history correlation id.
- **Copy semantics.** Copying a translation copies the element in all
  languages, consistent with move (decision 2). The "(copy 1)" label prefix
  is lost: Core applies it to the first copied record only, and that is now
  the empty shadow.
- **Primary language for language-0 creation** = first enabled site
  language, until the Editing Language exists (Phase 3b). For a new root
  page (no site yet) the record stays as created → I4 violation, reported
  by the check.
- **Test pitfall:** `Connection::select()` applies the default restrictions
  (hidden, time window). DataHandler tests must read with
  `removeAll()` restrictions, or hidden rows look deleted.

### Patch list after Phase 2

1. ✅ `SiteLanguage::__construct` (Phase 1).
2. ✅ `DataHandler::localize()` from translations (duplicates, page parent).
   Upstream candidate.
3. ✅ `DataHandler::getSortNumber()` ties. Upstream candidate (ties are rare
   in Core, but the change only avoids unnecessary resorting).
4. Open: `Site::getPrimaryLanguage()` + callers, `PageLinkBuilder:221`,
   `DatabaseRecordLinkBuilder` free-mode check.

---

## Phase 3: Layout module (2026-10-15)

### Deliverables

- **Page TSconfig** (`Configuration/page.tsconfig`, all pages):
  - `mod.web_layout.allowInconsistentLanguageHandling = 1`: new-content buttons in every
    language column, no "inconsistent" warnings;
  - `mod.web_layout.localization.enableCopy = 0`: "copy" creates unconnected records (I2);
  - `mod.web_layout.structureLayer.showStructureColumn = 0|1`: structure column hidden (3b,
    default) or visible (3a); a backend user's choice in the "View" menu ("Show structure
    layer", comparison view only, stored in `uc`) overrides it
    (`Backend/StructureColumnVisibility`, `EventListener/AddStructureColumnToggle`);
  - backend template overrides (`Resources/Private/TemplateOverrides/Backend`).
- **Template overrides** (EXT:backend, via `templates.typo3/cms-backend`):
  - `Partials/PageLayout/LanguageColumns`: rows always driven by the shadows; no
    free/mixed branch and no language mode badge; a `-1` record is one row spanning all
    visible columns; without the structure column, empty cells show a placeholder with the
    resolved label of the element.
  - `Templates/PageLayout/PageLayout`: single-language view of a page missing in the Editing
    Language → empty state "not available in …", links to the existing languages, "Translate
    page" (Core wizard).
  - `Partials/PageLayout/RecordDefault/Header`: "hide / show in all languages" next to Core's
    per-language toggle, derived badge "Hidden in all languages".
- **Editing Language** (3b): `Backend/EditingLanguage` (BE user `uc`, per site; default =
  primary language) and the middleware `Backend/Middleware/EditingLanguageInitialization`
  (after Core's `page-context`, `web_layout` only). It rewrites the `PageContext`:
  - language 0 is never selected and removed from the docheader language selector;
  - an explicit selection sets the Editing Language (Core's single-select selector *is* the
    Editing Language selector);
  - single-language view = the Editing Language, also on pages without it (→ empty state);
  - comparison view = Editing Language first, then the other selected languages;
  - a changed Editing Language reloads the page tree, whose titles follow it
    (`EventListener/RefreshPageTreeOnEditingLanguageChange`, `typo3:pagetree:refresh`).
- **Preview of shadow pages** (`EventListener/ResolveShadowPagePreviewLanguage`,
  `BeforePagePreviewUriGeneratedEvent`): "View" on a page-tree item (= the shadow) would open the
  disabled `/_structure/`; it previews the Editing Language instead, or the first translation in
  site-language order (the language the tree title comes from). An explicit language is kept.
- **Shadow labels**: `Backend/ShadowLabelResolver` (preferred language, then site-language
  order), used by the `PageContentPreviewRenderingEvent` listener `RenderShadowPreview`
  (compact structure cell: flag + label of a translation). Phase 4 reuses it for the page tree.
- **Hide / show in all languages**: `Backend/AllLanguagesVisibility`, ViewHelper
  `sl:allLanguagesVisibility`, page toggle via `ModifyPageLayoutContentEvent`
  (`AddPageVisibilityToggle`). The toggle sends `hidden` for the shadow; the Phase 2 hook
  writes it on every translation.
- **Sites module**: `fallbackType` read-only with an explanation; `fallbacks` no longer offers
  the structure layer (`Backend/SiteConfiguration/FallbackLanguageItems`).
- **Core patches** (`cms-backend`, test-first, all red before):
  - `cms-backend-drag-and-drop-translations.patch`: `GridColumnItem::isDragAndDropAllowed()`
    allows connected translations (a move always moves the shadow, Phase 2).
  - `cms-backend-localization-repository-structural-identity.patch` (upstream candidates):
    - `createTranslationParentConstraint()`: a record with `l10n_parent = X` is a translation of
      X whatever its `l10n_source` (Core required `l10n_source = 0`);
    - `getRecordsToCopyDatabaseResult()`: the wizard does not offer source records whose parent
      already has a record in the target language (content written directly has
      `l10n_source = 0` and was offered again).
  - `cms-backend-localization-wizard-sources.patch`:
    - `LocalizationController::getSources()`: language 0 is never a source;
    - `ManualLocalizationHandler`: a page is translated from the *source language's* page
      translation (Core always localized the default-language page, i.e. the empty shadow).
      Upstream candidate on its own: the chosen source language was ignored for pages.
- **Demo**: `ddev demo` writes the structure-layer site configuration and runs
  `structure-layer:demo` (DataHandler seeding: gate start state, plus a German-only subpage).
- Tests: `Tests/Functional/Backend/` (25 tests, data `Fixtures/PageModule.csv`). The module is
  rendered through `PageLayoutController::mainAction()` behind the extension middleware, like a
  real request. 148 tests in total, PHPStan level 8 and CGL clean.

### Gate result: met

`GateWalkthroughTest` runs the walkthrough with the DataHandler requests the module sends
and checks the comparison view, the flash queue and `structure-layer:check` after each step:

| Step | Result |
|---|---|
| German page, 5 elements, all translated to English | 5 rows, de \| en |
| English-only element after element 2 (en column, "new content after") | own row between 2 and 3, de cell shows a placeholder |
| Hide element 4 in English only | en record hidden, de untouched |
| Reorder: drag en element 5 behind element 1 | all languages moved; order 1, 5, 2, (en only), 3, 4 |
| Translate the English-only element to German | de record in the same row, `l10n_source` = en record |

No Mixed/Free badge, no inconsistency warning and no flash message appear in any step.
Checked manually in the DDEV instance (Playwright): comparison view, both toggles
(round trip), the missing-page empty state and the translation wizard (page translated
from German, check clean). **Not checked in the browser:** the drag & drop JavaScript; its
DataHandler request is covered by the Phase 2 move tests and the walkthrough.

### Findings

- **Rows were already shadow-driven.** Core's comparison view builds rows from
  `languageColumns.0`, so the template only had to drop the free/mixed branches, the badges
  and (3b) the structure cells. Everything else is Core rendering. Core's language mode
  ("connected") is now correct by construction.
- **Extension points that were enough:** page TSconfig, backend template overrides,
  `PageContentPreviewRenderingEvent`, `ModifyPageLayoutContentEvent`, a PSR-15 middleware,
  site-configuration TCA overrides. **Missing:** an event in `PageContextFactory` to define the
  initial/default language. The middleware rebuilds the whole `PageContext` instead; it works
  because `PageContext` and `PageLanguageInformation` are public value objects.
- **"Default language" is baked into the module in many places.** Things the middleware cannot
  reach and that remain visible:
  - the selector label counts the structure layer ("Languages (3)" for two selected languages,
    "Languages (1)" for one) – `LanguageSelectorBuilder` assumes 0 is always shown;
  - docheader "Edit page properties" and the page title on a page missing in the Editing
    Language point to the shadow (empty title);
  - breadcrumb and page tree show the shadow's empty title ("[No title]", Phase 4);
  - `-1` records: Core puts them into language 0 in comparison mode and into the selected
    language in single-language mode. Both work, but only because the structure grid is
    still built while hidden.
- **Two lookups in `LocalizationRepository` ignored structural identity.** With translations
  of translations (`l10n_source` ≠ parent) Core did not see existing translations: the wizard
  offered already translated elements and the Phase 2 duplicate check missed them. These are
  real Core bugs for any installation that translates from a non-default language.
- **The translation wizard skips steps.** With one possible source and one mode, it jumps
  straight to the confirmation. On the structure layer this is the common case.
- **Runtime-cache pitfall in tests.** `ContentFetcher` and page TSconfig are cached per
  request in `cache.runtime`; several module renders in one test must flush it, or the second
  render reuses the first one's records.
- **UX gaps (not fixed):** the long "Hidden in all languages" badge widens the record header
  and pushes the second column out of the viewport; there is no per-element "translate"
  action in the comparison view (Core only has the page-level wizard).
- **Cost (SCALE-1, counted from the code):** per rendered shadow, 1 query for the label; per
  rendered translation, 1 query for the all-languages state.

### Patch list after Phase 3

1. ✅ `SiteLanguage::__construct` (Phase 1).
2. ✅ `DataHandler::localize()` from translations (Phase 2).
3. ✅ `DataHandler::getSortNumber()` ties (Phase 2).
4. ✅ `GridColumnItem::isDragAndDropAllowed()`.
5. ✅ `LocalizationRepository` structural identity. Upstream candidate.
6. ✅ Translation wizard: no language 0 source, pages from the source language.
   The page part is an upstream candidate.
7. Open: `Site::getPrimaryLanguage()` + callers, `PageLinkBuilder:221`,
   `DatabaseRecordLinkBuilder` free-mode check; candidate: an event in `PageContextFactory`.

---

## Phase 4: Page tree and migration (2026-10-16)

### Deliverables

- **Page tree titles** (`Backend/EventListener/ResolvePageTreeTitles`, `AfterPageTreeItemsPreparedEvent`):
  a shadow page shows the title of its translation in the Editing Language, otherwise the first
  available translation in site-language order (the Phase 3 `ShadowLabelResolver`). A title from
  another language gets that language's flag as status information ("Not available in English,
  title from Deutsch") and the same text in the tooltip. The page tree search already finds
  shadows by their translated titles (Core's `searchInTranslatedPages`). Icon and overlay
  (hidden, start/end time, nav_hide, …) come from that same translation (2026-10-19): the shadow is
  never hidden (I5), so the tree showed every page as visible.
- **Page tree context menu "Enable" / "Disable"** (`Backend/ContextMenu/ShadowPageVisibilityItemProvider`,
  `context-menu-actions.js`, 2026-10-19): Core's `PageProvider` reads the shadow, so it only ever
  offered "Disable", and that sent `hidden` for the shadow = "hide in all languages". The items now
  toggle the translation the tree shows ("Disable (Deutsch)"); "hide / show in all languages" stays
  in the Layout module.
- **Inline renaming in the page tree** sends the new title for the item's uid, i.e. the shadow.
  The DataHandler hook writes label fields (`label`, `label_alt`, for pages also `nav_title`)
  submitted for a shadow to the translation the backend shows for it (I1).
- **`structure-layer:migrate <site> [--dry-run] [--plan=file.json]`** (`Migration/Migrator`,
  `Migration/MigrationReport`, `Command/MigrateCommand`), plan §4 I:
  1. Site configuration: language 0 becomes "Structure" (disabled, `/_structure/`), the old default
     language becomes language N = highest id + 1, listed right after 0 (primary language), with
     the old title, base, locale and flag. Fallback chains: see the table below.
  2. Every language-0 record of `pages` and `tt_content` gets a language-N translation by **row copy**,
     not `DataHandler::localize()`: for pages, `localize()` copies only `prefixLangTitle` fields
     (Phase 2 finding) and generates a new slug; for content it prefixes labels. The copy keeps
     everything, including the slug, so the old URLs stay. File references move to the copy.
     The shadow keeps its uid: links, MM relations and existing `l10n_parent` pointers stay valid.
  3. Visibility: see the table below.
  4. Shadows lose their payload (text-like non-structural fields emptied, file fields 0), page shadows
     get `l18n_cfg` bit 1. Structural (`exclude`) fields and `sorting` are mirrored to every
     translation, page translations get a "custom" `l10n_state`.
  5. Free-Mode and orphaned translations get a shadow each, at their own sorting. Never matched;
     a record with `l10n_source` is reported as a connection candidate.
  6. `-1` records are left alone and counted; workspace versions are skipped and counted.
  7. All writes in one transaction; the dry run rolls it back and writes no site configuration.
     Then the reference index for the written records, the site configuration and `check`.
- **Before/after evidence** (`Tests/Functional/Migration/`):
  - `ddev baseline` installs a second Composer root, `Build/baseline/`, with the **same Core
    commits** as `.Build/` but without the patches and without the extension. It renders every data
    set there and writes `Fixtures/Baseline/*.json`. With the patches installed, classic output
    cannot be rendered.
  - Data sets: Core `RegularLocalize` and `RegularFreeMode` import fixtures (Core `0a835c80f63`),
    each with a strict, a fallback and a free site; `Visibility` (hidden default records and
    translations, an expired default page, Free Mode); `TranslationHandling`, generated by the
    baseline recorder with DataHandler like the t3thi `translation_handling` extension
    (`Generator::create()` for fallback, strict and free: 7 languages, free/connected/mixed pages;
    without files) and exported to CSV.
  - `MigrationOutputTest` migrates each data set and compares the output of every page in every
    classic language (status, page, content headers in order, menu) with the baseline.
    `MigrationTest` covers the record level, the dry run and the command.
- Tests: 28 new (176 in total), PHPStan level 8 and CGL clean. Checked manually in the DDEV instance: page tree
  titles and the language marker after switching the Editing Language.

### How classic output modes map to chains

The baseline corrected the plan's assumption. Strict also uses the chain for records, and "fallback"
always falls back to the default record in the end:

| Classic `fallbackType` | Records before | Pages before | Chain after migration |
|---|---|---|---|
| `fallback` | chain, then **always** the default record (even with an empty chain or without `0`) | chain | chain with `0` → N, **N appended** |
| `strict` | chain, **never** the default record (`0` in the chain only affects pages) | chain incl. `0` | chain without `0`; `0` → `inactiveFallbacks` |
| `free` | own language only | chain | none; the chain → `inactiveFallbacks` |

### Results (gate: met with three inherent differences)

Frontend output after the migration equals the baseline for all 10 data sets (3 × 3 Core and
Visibility sites, 3 TranslationHandling sites, 7 languages), apart from the differences below. Each
one is pinned in `MigrationOutputTest::EXPECTED_DIFFERENCES` with its reason. All of them follow
from the model, none from the migration:

| Difference | Where | Why |
|---|---|---|
| **Page fallback to the old default is lost**: untranslated page → 404, its menu entry disappears | strict and free languages (all DA/DE requests of the Core default-only data set) | A chain now also selects records. For strict it never contained the default records, for free no records at all. The plan's compatibility note (`inactiveFallbacks`) cannot keep the page part. |
| **A disabled translation blocks the fallback** (content and pages, also in menus) | every language with a hidden translation | decided 2026-10-14 (§4 G); before, the fallback (or the default page) showed |
| **Per-language order** is replaced by the structure order | free languages where a translation's `sorting` differed | component C; per-language ordering is a non-goal |

Two migration bugs were found only through the baseline and are fixed:

- **Default-record visibility applies per output mode.** A hidden default record hid its overlays in
  strict/fallback languages, but **not** in free languages (OVERLAYS_OFF selects translations
  directly). Content translations are therefore hidden only for strict/fallback languages, pages for
  all of them (the page lookup always reads the default page).
- **Free-Mode records become positions of their own.** Before, a floating record was shown only in its
  own language. With its own shadow it became a fallback for every language that has its language in
  the chain (TranslationHandling: `[Purple] 7` next to `[Indigo] 7` in Indigo). The migration writes a
  **disabled translation** ("absence marker", a copy of the record, `l10n_source` = the record) in
  each such language. This is the model's only way to express absence (§4 G, no stored "absent" flag).

### Counts (MIG-1, SCALE-1)

| Data set | pages before → after | tt_content before → after | Notes |
|---|---|---|---|
| TranslationHandling (3 sites, 7 languages) | 105 → 120 | 300 → **514** (+71 %) | 57 copies in N; 105 Free-Mode shadows; 52 absence markers; 138 sortings aligned; `l18n_cfg` aligned on all 105 page translations |
| Visibility | 13 → 18 | 12 → 20 | 2 + 2 translations hidden by their parent; 2 time windows intersected; 1 connection candidate |
| RegularFreeMode (fallback) | 8 → 12 | 10 → 18 | 2 Free-Mode shadows, 1 absence marker |

Every data set: `structure-layer:check` clean after the migration (fallbackType notices only).

### Findings

- **The page tree needed no Core change.** `AfterPageTreeItemsPreparedEvent` carries the page row,
  and `statusInformation` can show a flag. Cost: 1 query per tree item (translation lookup), not
  cached. Still showing the shadow's empty title: breadcrumb and docheader of the Layout module
  (Phase 3 finding), the record title in "Edit page properties".
- **Free Mode is the expensive part of the migration.** On the TranslationHandling data, free and
  mixed pages add a shadow per floating record plus an absence marker per chain language. Absence
  markers are hidden records with content in another language; editors see them as hidden
  translations. Alternative for the initiative: accept the new fallback positions (fewer rows, but
  content appears in languages that never showed it). Both are decisions about Free Mode, not
  about the migration.
- **"strict ignores the chain" is wrong for records.** Strict overlays along the chain and only skips
  the default record. Under chain-driven output, strict with `fallbacks: 0` (the common
  configuration) is the same as "no chain" for records, and loses its page fallback.
- **`fallback` always ends with the default record**, even with empty `fallbacks`. The migration
  makes this explicit (N appended). With the chain alone deciding output, an empty chain must mean
  "requested language only", so the two cannot be the same.
- **Payload reduction of shadows is partial.** Text-like fields are emptied, file references move.
  Select, check and number payload (e.g. `header_layout`, `imageorient`) stays on the shadow. It is
  never rendered, but migrated shadows are not identical to DataHandler-created ones. Other inline
  relations (IRRE) are not migrated.
- **File references of other languages** keep `l10n_parent` pointing at the moved default reference,
  which is now in language N. Inline children are not part of the structure layer in v1 (Phase 2),
  so this is left as is and is harmless for rendering.
- **`?id=` URLs** are used for before/after (shadow uids are stable), so slugs are not compared. The
  copy keeps the old slug, so `/` and the default-language paths stay.
- **Rollback** = database snapshot. The dry run uses a rolled-back transaction, so its counts are
  exactly those of the real run (tested).
- **Baseline drift:** `dev-main` moves. `ddev baseline` pins the baseline install to the commits in
  `.Build/vendor/composer/installed.json`; re-run it after `composer update`.

### Patch list after Phase 4

Unchanged (Phase 3, 6 patches). Phase 4 needed no Core patch.

---

## Phase 5: Ambiguity and permissions (2026-10-16)

### Deliverables

- **Permissions** (characterization plus one prototype, no final model):
  - Test-only users (`Tests/Functional/Permissions/Fixtures/RestrictedEditor.csv`): "english" with
    `allowed_languages = 2`, and "structure" with additionally the custom permission
    `structure_layer:structure` (a custom permission option registered in `ext_localconf.php`).
  - `DataHandling/StructureAuthority` plus rules in the DataHandler hook:
    - **content operations are system-managed**: for the outermost run, language 0 is added to the
      editor's allowed languages, so the shadow writes that follow from editing their own languages
      pass Core's checks;
    - **structure operations need the permission**: move, copy, structural fields, "hide/delete/
      restore in all languages", other fields on a shadow. Without it they are rejected with an
      explicit message; the payload part of the same request is saved. With it, the run may write
      every language;
    - the editor's own requests for languages they may not edit are dropped beforehand, so the
      structure grant only covers what follows from the structure operation;
    - a record created in language 0 (page tree) gets its first translation in the Editing Language,
      or else in the first language the editor may edit (not in the primary language). Fixed
      2026-10-19: this applied to restricted editors only; admins got the primary language, so a
      page created while editing English became a German translation (`ShadowCreationTest`).
  - `RestrictedEditorTest` (14 tests).
- **Move disambiguation** (Option B, 2026-05-29):
  - `Backend/HiddenPositions` + listener `AddMoveDisambiguation` (`ModifyPageLayoutContentEvent`)
    render a JSON map per language column and colPos: visible element (0 = top of the column) =>
    the shadows between it and the next visible element, with label and language;
  - `Resources/Public/JavaScript/move-disambiguation.js` (import map `Configuration/JavaScriptModules.php`)
    intercepts a drop only if its anchor has hidden positions. A modal offers "directly after …"
    and "after … (language)" for each hidden position, then sends the move. Unambiguous drops stay
    Core's;
  - `MoveDisambiguationTest` (4 tests). Checked in the DDEV instance (Playwright, simulated HTML5
    drag & drop): demo page "Moving" (A de+en, B de only, C en only, D de+en). Dropping D behind A in
    English asks "Directly after A" / "After B (nur Deutsch)"; choosing B gives A, D, C in English and
    A, B, D in German.
- Core patch `cms-core-datahandler-page-translation-new-parent.patch` (test-first, red before).
- `structure-layer:demo` seeds the "Moving" page. 194 tests in total, PHPStan level 8 and CGL clean.

### Where Core's language checks block shadow writes (editor with `allowed_languages = 2`, no prototype)

| Operation | Result | Check |
|---|---|---|
| Create content in English | **shadow rejected, English record written unconnected (I2 violation)** | `checkRecordEditAccess()` on the NEW shadow ("Language was not allowed") |
| Create a page (English or page tree) | rejected completely | same, plus the page translation is checked against its NEW parent (see patch below) |
| Edit English payload, hide in English | works | – |
| Translate de → en (source not editable) | works | `localize()` only needs read access to the source |
| Edit `colPos` from the English record | rejected for the shadow **and** for the German translation (DataMapProcessor sync) | `checkRecordEditAccess()` |
| Hide in all languages | shadow ok, German translation rejected: **partly applied** | same |
| Move | rejected | `moveRecord()` on the shadow |
| Delete the shadow directly | rejected | delete permission check on language 0 |
| Delete / restore an English translation | works, incl. the nested shadow delete/restore | – |

With the prototype, every row above has an explicit outcome: content operations work, structure
operations are rejected as a whole (without the permission) or applied to all languages (with it).

### Findings

- **The structure layer turns language permissions into structure permissions.** With only
  language checks, a language-restricted editor cannot create content at all. The system has to
  write the shadow on their behalf, which is a new concept for Core: an operation-level authority,
  not a per-record one. The PoC grants it by widening `allowed_languages` for one DataHandler run.
  This works for a prototype; Core would need a real "system write" or a check that knows about
  shadows (e.g. `checkRecordEditAccess()` treating language 0 as structure). `bypassAccessCheckForRecords`
  no longer exists in v15 (the translation_handling extension still sets it, without effect).
- **Partial writes are the real danger.** Without the prototype, Core applies the parts of a request
  it allows: an unconnected record (I2), a "hide in all languages" that hides only some languages, a
  structural edit that reaches the shadow but not all translations. A structure-aware permission
  check must decide per *operation*, before DataHandler starts.
- **Who owns structure** stays open (2026-05-29, 2026-08-14). The prototype shows one workable split:
  - content editors see their language, create and delete there and never touch other languages;
  - structure editors move/copy for everyone.
  Consequences to discuss:
  - a content editor cannot reorder even in their own language (no per-language order, decision 2);
  - "hide in all languages" becomes a structure right (decision 3 had no permission gate);
  - deleting the last translation still deletes the shadow and, for pages, the subtree (Phase 2
    finding). The prototype treats that as a content operation; it is not restricted further.
- **Core bug: page translation with its default page in the same datamap.** DataHandler checks a page
  translation's permissions against its `l10n_parent` before resolving the NEW id: `'NEW1' > 0` is
  true for strings, `getRecord('pages', 'NEW1')` is empty, so every non-admin is rejected. Admins pass
  because the page check is skipped for them. Patch: resolve the NEW id via `substNEWwithIDs`.
  Upstream candidate, independent of the PoC.
- **Ambiguity is a per-column property and needs no structure column.** The map is computed from the
  shadows (1 query per page plus 1 label query per hidden position); the decision is made in the
  browser, before the request. Only drops whose anchor is followed by invisible positions ask; in the
  demo that is one of the four positions in each language.
- **Extension points that were enough:** `ModifyPageLayoutContentEvent` (footer content and
  `PageRenderer` for the JS module), the import map, a capture listener on `window` that runs before
  Core's capture listener on `document`. **Missing:** an event or hook in Core's drag & drop
  (`drag-drop.js`) to veto or redirect a drop; the PoC relies on event order.
- **Not covered:** the "new content after" buttons and paste/cut have the same ambiguity (they insert
  after a visible element); keyboard-based moves; the comparison view uses the same map per column
  but was only checked in single-language mode. The modal labels hidden positions with the first
  available translation (its language in brackets).

### Patch list after Phase 5

1. ✅ `SiteLanguage::__construct` (Phase 1).
2. ✅ `DataHandler::localize()` from translations (Phase 2).
3. ✅ `DataHandler::getSortNumber()` ties (Phase 2).
4. ✅ `GridColumnItem::isDragAndDropAllowed()` (Phase 3).
5. ✅ `LocalizationRepository` structural identity (Phase 3).
6. ✅ Translation wizard sources (Phase 3).
7. ✅ **New:** page translation with a NEW parent page: permission check. Upstream candidate.
8. Open: `Site::getPrimaryLanguage()` + callers, `PageLinkBuilder:221`, `DatabaseRecordLinkBuilder`
   free-mode check; candidates: an event in `PageContextFactory`, a drop event in `drag-drop.js`,
   a structure-aware language check in `checkRecordEditAccess()`.

---

## Phase 6: Characterization of the hard edges (2026-10-16)

Document-only phase: no new behavior. Everything below is pinned by characterization tests, so a
later change shows up as a red test.

### Deliverables

- `Tests/Functional/Workspaces/WorkspaceCharacterizationTest` (5 tests, WS-1), with `typo3/cms-workspaces`
  as a dev dependency, pinned to the split commit matching the installed Core (`e06b34f`).
- `Tests/Functional/DataHandling/AllLanguagesRecordTest` (3 tests) and one more frontend test in
  `ChainDrivenOutputTest`: `-1` records.
- `Tests/Functional/DataHandling/DiffSourceCharacterizationTest`: `l10n_diffsource`.
- `Tests/Functional/Measurements/` (SCALE-1): `QueryCounter`, a Doctrine driver middleware
  registered only in the test instance, and `MeasurementTest` (16 measurements, written to
  `var/measurements/*.json`).
- Core suites with `patches/` applied: a scratch clone of the monorepo (not the working checkout),
  at `ccd5d954ccd`, the monorepo commit of the installed split packages. All 7 patches apply with
  `git apply --directory=typo3/sysext/<ext>`.
- 220 tests in total, PHPStan level 8 and CGL clean.

### Workspaces (WS-1)

| Operation in workspace 1 | Result |
|---|---|
| Create in a language | shadow and record created as new versions; publishing makes both live, check clean |
| Move a translation | move versions of the shadow and every translation; publish: all languages moved |
| Hide in all languages | versions of every translation with `hidden = 1`, shadow untouched |
| Delete the last translation | **breaks**: only the translation gets a delete version. The hook looks for *live* translations; the live record still exists, so the shadow is not "last". After publishing: I4 violation |
| Discard a new translation | **breaks**: the new shadow version stays; publishing the workspace makes an empty shadow live (I4) |
| Sorting alignment (C) | **drifts in the workspace**: C only updates live rows. A no-chain language orders by the translation's own sorting, so the preview can show another order than the published result. Publishing realigns (the hook runs on the publish command) |

The structure layer itself is workspace-compatible: shadows are versioned records like any
other. What is missing is workspace-awareness in the hook (translation lookups, discard,
alignment). That is implementation work, not a model problem. Not tested: workspace preview
in the frontend, staging, "publish only this language" (would leave the shadow behind or
publish it alone).

### `-1` records

- Copy to another page: one record, language `-1`, no shadow, check clean.
- Cut/paste into a language column: Core's paste sends that column's `sys_language_uid`; the
  hook drops it, the record stays `-1` (the 2026-08-21 regression does not occur).
- Workspace edit: one version, no copies per language; publish fine.
- Frontend: a shortcut (RECORDS) to a `-1` record renders it in every language, with and without
  a chain.
- Already covered before: rendering (Phase 1), moves (Phase 2), one row spanning all columns in
  the Layout module (Phase 3), untouched by the migration (Phase 4).

### `l10n_diffsource` (2026-08-14: "expose rather than silently preserve")

DataHandler fills `l10n_diffsource` with the values of the `l10n_parent` record whenever a
translation is saved. FormEngine compares that snapshot with the current parent to show "the
default language changed". With the structure layer the parent is the empty shadow: the snapshot
is always empty, and a change of the record actually translated from (`l10n_source`) is **never
shown**. Classic Core has the same blind spot for translations of translations: the snapshot is
taken from the default record, not from `l10n_source`. The structure layer makes it the normal
case. A fix would snapshot `l10n_source` instead (one place in `DataHandler::fillInFieldArray()`
and `DatabaseLanguageRows`), plus a decision on what to show when the source is deleted.

### Measurements (SCALE-1)

SQL statements per frontend request (`ChainOutput.csv`, warm caches, second request). "Extension" =
statements issued while extension code was on the stack (listeners, `TranslationLookup`):

| Request | Statements | of which extension |
|---|---|---|
| de, no chain, page with 6 elements | 53 | 5 |
| en, chain `1`, same page | 95 | 37 |
| en-GB, chain `2,1`, same page | 101 | 39 |
| de, no chain, subpage | 38 | 3 |
| en, chain `1`, subpage | 57 | 18 |

Without a chain the cost is negligible: the requested language is selected directly. With a chain,
the overlay listener's "does a disabled translation exist in the requested language" lookup and the
page policy (menus, rootline) add **32–39 % of all statements**. Nothing is cached per request yet.
A runtime cache per (table, shadow, language) or a batched lookup per result set would remove most
of it.

DataHandler, per operation (`StructureData.csv`, admin; statements including sys_log/sys_history/refindex):

| Operation | Statements | of which extension | Writes | of which extension |
|---|---|---|---|---|
| Create in en (shadow + record) | 52 | 7 | 13 | 4 |
| Insert after a translation | 46 | 8 | 13 | 4 |
| Edit payload of a translation | 39 | 6 | 7 | 3 |
| Edit `colPos` from a translation | 88 | 8 | 14 | 3 |
| Hide in all languages | 72 | 8 | 11 | 3 |
| Move a translation (all languages) | 41 | 9 | 12 | 3 |
| Translate de → en | 46 | 9 | 7 | 3 |
| Delete one of two translations | 35 | 8 | 6 | 3 |
| Delete the last translation (+ shadow) | 49 | 24 | 8 | 5 |
| Copy a translation (all languages) | 116 | 22 | 21 | 8 |

The extension's own share is small (6–9 statements, mostly the sorting alignment and lookups),
except when it starts a nested DataHandler run (delete of the shadow, copy repair). The larger cost
is structural: a "content" change writes two records instead of one (create), and an "all languages"
change touches every translation (colPos, hide, move: DataMapProcessor and the move cascade).

Migration (TranslationHandling data set, 3 sites, 7 languages, 300 → 514 content rows): 3,913
statements (950 writes) for the whole data set. Reference index: **228 → 457 rows** (+100 %); a full
`updateIndex()` afterwards finds nothing to add.

### Core tests with the patches applied (breaking scope)

Suites: unit (all, 12,237 tests) and functional `core/DataScenarios`, `core/DataHandling`, `core/Site`,
`frontend`, `backend`, `extbase/Persistence` (7,340 tests), SQLite. **257 test methods fail** (267
with data sets) in 52 classes. Without the patches, all 52 classes (1,717 tests) pass on the same
commit, so every failure is caused by a patch. List: `docs/core-test-failures.md`.

| Methods | Cause | Verdict |
|---|---|---|
| 156 | `cms-core-site-language-effective-fallback-type`: `0` is no longer a fallback, `fallbackType` is derived from `fallbacks`. Untranslated pages give 404, overlay types and fallback chains differ (LocalizedSiteContentRendering, SlugSiteRequest, LocalizedPageRendering scenarios, Extbase TranslatedSiteContent, DataScenarios frontend assertions, backend SiteUrlResolver) | intended: the behavioral break of chain-driven output |
| 76 | `cms-core-datahandler-sort-number-ties`: only `sorting` values differ. Core's expectations encode the renumbering the patch avoids | intended; expectations would be rewritten if upstreamed |
| 21 | Workspace preview crash, exposed by the fallback patch: `PreviewSimulator` → `PageRepository::checkIfPageIsHidden()` passes `false` to `getWorkspaceVersionOfRecord()` when the page does not exist in the requested language | **latent Core bug** (missing null check), upstream candidate |
| 2 + 2 | Translation wizard sources (no language 0) and the `SiteLanguage` unit test (expects `0` in chains) | intended |

Verified by leaving out single patches: without the sort-ties patch, a sorting-only class passes. Without
the fallback patch, LocalizedSiteContentRendering, a DataScenarios workspace class (preview crash) and
Extbase TranslatedSiteContent pass. The other four patches (localize from a translation, page translation
with a NEW parent, drag & drop, LocalizationRepository structural identity) **break no Core test** in
these suites. That supports upstreaming them on their own.

Not run: the remaining functional suites (install, form, …) and acceptance tests. The patches touch
DataHandler, `SiteLanguage` and the backend wizard, so those suites are the most exposed ones;
the full run is left for a later decision.

### Findings

- **The breaking scope is concentrated.** 98.7 % of the Core tests run are unaffected (257 of 19,577 fail). The break is the
  output policy itself: every test that expects "fall back to the default language" or
  `fallbackType` semantics. That is the decision the initiative takes, not a side effect.
- **Workspaces need work in the extension, not in the model.** The two I4 breaks come from
  live-only lookups in the hook. A real implementation must resolve translations, alignment and
  discard per workspace. Core's own DataScenarios workspace tests fail only through frontend
  expectations and the preview crash.
- **Query cost is the main runtime cost**, and it only applies to languages with a chain: about a third
  of the statements of such a request come from the output policy. It is uncached by design in the PoC.
- **Write amplification is moderate per operation** (one extra record on create, the existing
  cascades elsewhere). The reference index doubles with the migration, because every former
  default record now exists twice (shadow + translation).
- **`l10n_diffsource` needs a decision**: compare against `l10n_source`, or drop the diff view for
  translations of translations. Today it silently compares against an empty record.
