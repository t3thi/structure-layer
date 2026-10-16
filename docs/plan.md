# PoC: Default Language as a Hidden Structural Layer

**Status:** Implementation plan, 2026-10-14. Phases 0–5 done (`docs/findings.md`).
**Base:** `typo3/cms-*` `dev-main` (TYPO3 v15 development line). Code
analysis was done against the Core monorepo at `0a835c80f63`.
**Sources** (in the Core monorepo checkout `~/Code/typo3-cms-15`):
`typo3temp/Documentation/MeetingMinutes/` (all weekly minutes
2023-10 – 2026-10-09, all monthlies, `current-state.md` of 2026-10-10) and
`typo3temp/Documentation/Analysis/T3DD26/Architecture-Options-and-Open-Questions.md`,
cross-checked against current Core code.

---

## 1. Goal (what the initiative actually asked for)

The PoC implements **Path 2 of the Structural-Identity comparison** in its
pragmatic "hidden `0`" realization (minutes 2026-05-08, 2026-05-29,
2026-08-14, 2026-09-18):

- `sys_language_uid = 0` stops being an output language. It becomes a hidden,
  contentless **structure layer**. Every record in it is a *structural shadow*.
- Every real language — **including today's default output language** — gets
  a positive language ID and is a regular, *connected* translation
  (`l10n_parent` → shadow). All languages are equal.
- When an editor creates content in only one language, Core creates the
  shadow automatically in the background. The record stays connected, so
  there is no Free or Mixed mode. In the initiative's words, "make the
  current workaround the status quo" (2026-05-29).
- `l10n_parent` = structural identity, `l10n_source` = content origin
  (Jo, 2025-10-24). They must stay distinct.
- **Output is driven by the fallback chain alone** (decided 2026-10-14,
  building on André's proposal of 2026-10-09). On all sites
  `fallbackType` is ignored:
  - no `fallbacks` → only the requested language is rendered;
  - `fallbacks` set → runtime fallback along the chain.
  Visibility is written explicitly onto concrete language records instead
  of being inherited from the Default-Language parent at render time
  (§4 G).

### What the PoC must prove (the initiative's evaluation criteria)

The 2026-08-14 meeting asked the prototype to "make these edge cases visible
before committing to an architecture" and to test **creation, sorting,
deletion, reconnection, permissions and usability**. The 2026-05-29 meeting
expected it to "reveal mistakes that are hard to find in abstract planning".
A decision is realistic for v16, not v15 (2026-09-18). The PoC is evidence
for that decision. It is **not** a mergeable Core change.

### Explicit non-goals

- No replacement of `-1` / Language All. It is a separate work stream
  (2026-06-11: do not couple the two). A `-1` record stays **one record**:
  - it gets no shadow and no copies;
  - the backend shows it in every language column, and editing any of
    those instances edits the original record (§4 H, decided 2026-10-14).
- No BCP 47 identity work. The numeric IDs stay as they are.
- No per-language ordering of shared positions (deferred, 2026-08-21).
- No new stored "absent" or "Disable-All" field. Intentional absence is
  expressed only through an existing, disabled translation (§4 G).
- No removal of `fallbackType` from the site configuration schema. The
  field becomes **read-only** in the Sites module and is ignored at
  runtime (§4 G).
- No Workspaces support beyond characterization (see Phase 6).
- No generic dimensions (`Drafts/dimensions.md`).

### Objections the PoC must answer, not ignore

| Objection | Source | How the PoC addresses it |
|---|---|---|
| Auto-created background records make sorting and structural control opaque; it is a "massive breaking change". | 2024-03-01 | The PoC runs only in its own instance, with the extension and the Core patches installed. The structure column stays visible in early phases. Moves and sorting are routed explicitly through the shadow with an "affects all languages" notice. |
| Ambiguous moves when shadows from other languages sit between two visible elements. | 2026-05-29 | Phase 5 adds a disambiguation step only when the target position is ambiguous (Martin's preferred Option B). |
| Who owns structural authority? | 2026-05-29, 2026-08-14 | Characterize in Phase 6 with a restricted editor. No final permission model. |
| An empty shadow could blank synchronized fields in translations. | Code analysis | Field partition, see §3.3. |
| Free content cannot be matched to shadows automatically. | 2026-08-21 | Migration gives each Free record its own shadow and never guesses matches. |
| Implementation can eat strategy effort. | 2026-05-29 | Strict phase gates. Stop after Phase 3 if the core hypothesis fails. |

---

## 2. Architecture of the PoC

### 2.1 Delivery form

The 2026-05-29 meeting agreed on a hybrid: an **extension first, Core
patches only where no extension point exists**. Concretely:

- A regular third-party extension `t3thi/structure-layer`
  (`EXT:structure_layer`, namespace `T3thi\StructureLayer`) in its own
  repository, `~/Code/ext/structure-layer`. The extension is the Composer
  root package, so dependencies go to `.Build/`. It contains hooks, event
  listeners, a CLI migration command, page TSconfig, template overrides
  and all functional tests. As a normal extension it shows what is
  possible through public extension points without Core-internal access.
- TYPO3 v15 comes from `typo3/cms-*` `dev-main` via Composer, with a DDEV
  instance for manual testing and the demo (`ddev demo`).
- A small set of **Core patches** (§6), kept as patch files in `patches/`
  and applied by `cweagans/composer-patches` on `composer install`
  (`patches/README.md`).
  - They apply **unconditionally**. There are no per-site switches and no
    guards (decided 2026-10-14: guards only add complexity).
  - The model applies to **all sites** of an installation where the
    extension is installed.
  - Core tests that now fail with the patches applied are not "fixed".
    They are collected as evidence of the behavioral break (Phase 6).
- Nothing goes to Gerrit without an explicit decision.

### 2.2 Activation and site configuration

There is no activation flag. With `EXT:structure_layer` installed, **every
site** is treated as a structure-layer site. The site configuration only
has to follow the layout below; the migration command (§4 I) produces it
from an existing site.

```yaml
languages:
  - languageId: 0             # the structure layer
    title: Structure
    enabled: false
    base: /_structure/        # must not share "/" (see 2.3)
    locale: en_US.UTF-8       # irrelevant, but required
    flag: global
  - languageId: 1             # former default content, now a normal language
    title: Deutsch
    base: /
    locale: de_DE.UTF-8
    fallbacks: ''             # no chain: only German is rendered
  - languageId: 2
    title: English
    base: /en/
    fallbacks: '1'            # chain: missing English positions use German; never 0
```

- `fallbackType` is not needed. If present, it is ignored, and
  `structure-layer:check` reports it as ignored.
- PoC-wide settings, currently only `shadowContent: empty|label|copy`, live
  in the extension configuration (`$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['structure_layer']`),
  not in the site configuration.

A small helper `StructureLayer` (in the extension) provides
`getPrimaryLanguage(Site)`: the first enabled language. It replaces
"default language" wherever Core means "the language editors see first".

### 2.3 Findings that shape the design (verified in code)

| Fact | Location | Consequence |
|---|---|---|
| `Site::getDefaultLanguage()` returns the **first language in YAML order**, not ID 0. About 20 callers. | `core/Classes/Site/Entity/Site.php:284` | **Spike in Phase 0** decides between "language 0 first + own base" and "language 1 first". Callers are listed and classified. |
| `SiteMatcher` registers routes for disabled languages, and `BestUrlMatcher` breaks ties by order. | `core/Classes/Routing/SiteMatcher.php:141`, `BestUrlMatcher.php:137` | Language 0 needs a distinct base (`/_structure/`). |
| The fallback chain is configured fallbacks + `pageNotFound`, with **no implicit 0**. | `core/Classes/Context/LanguageAspectFactory.php:30-60` | Good. Validation must reject `0` in `fallbacks`. |
| `fallbackType` maps to overlay types: `free` → `OVERLAYS_OFF`, `fallback` → `OVERLAYS_MIXED`, `strict` → `OVERLAYS_ON_WITH_FLOATING`. | `LanguageAspectFactory.php:30-60` | The PoC derives the effective type from `fallbacks`: empty → `free`, non-empty → `fallback`. `strict` is never used (§4 G). |
| `free` / `OVERLAYS_OFF` selects records of the requested language **directly**, ordered by their own `sorting`, independent of the language-0 row's visibility. | `ContentObjectRenderer.php:4838-4894`; `Typo3DbQueryParser.php:748-837` | This is the "no chain" mode. Shadow visibility no longer matters for content, but **translation `sorting`/`colPos` must mirror the shadow** (component C becomes mandatory). |
| Strict = `OVERLAYS_ON_WITH_FLOATING`: rows are selected **from language 0**, then overlaid, so a hidden parent hides every translation. | `PageRepository.php:350-436, 659-806` | This is exactly the parent-visibility dependency described on 2026-10-09 (Topic 5). It is no longer used. |
| Fallback = `OVERLAYS_MIXED` selects language-0 rows and **keeps the language-0 row** when no overlay exists along the chain. A hidden requested-language translation is skipped by enable fields, so fallback content appears. | `PageRepository.php:397-406, 743-758` | An `AfterRecordLanguageOverlayEvent` listener must (a) drop language-0 results and (b) return nothing when a *disabled* translation exists in the requested language (2026-10-09, Topic 6). Shadows must pass enable fields in this mode, so they are never hidden (I5). |
| A page lookup fetches the language-0 page with enable fields; a hidden shadow page gives a 404 in every language. | `PageInformationFactory.php:154-210` | Shadow pages are never hidden (I5). "Hide page in all languages" writes `hidden` on every page translation instead. |
| `pages.l18n_cfg` bit 1 = "hide default language". | `PageInformationFactory.php:402-412` | It is set on every page shadow. This blocks backend preview of language 0 at no extra cost. |
| `DataHandler::localize()` sets `l10n_parent` = source uid only for language-0 sources. Otherwise it copies the source's parent; `l10n_source` = source. | `DataHandler.php:4886-4893` | "Translate from German into English" already yields parent = shadow, source = German record. This is exactly the target semantics. |
| `LocalizationRepository::getRecordTranslation()` matches `l10n_source=uid OR (l10n_source=0 AND l10n_parent=uid)`. | `backend/.../LocalizationRepository.php:128-212, 394-419` | Translating from a non-shadow source misses existing translations of the same shadow and **creates duplicates**. This is a real Core bug candidate: test-first, separate Forge issue. |
| `GridColumnItem::isDragAndDropAllowed()` requires `l18n_parent = 0`. | `backend/.../Grid/GridColumnItem.php:302-322` | Connected columns cannot drag. Needs a Core patch. |
| `PageLayoutContext::getAllowNewContent()` blocks new content in Connected Mode unless `mod.web_layout.allowInconsistentLanguageHandling = 1`. | `PageLayoutContext.php:231-238` | Set that TSconfig globally (extension page TSconfig). No patch needed. |
| The comparison template builds rows from `languageColumns.0`, the shadow items. | `LanguageColumns.fluid.html:82-176` | Rows stay shadow-driven, which is the right model. Shadow cells are rendered as a slim "structure" cell (template override). |
| New content in a translation column passes `sys_language_uid` + `colPos` and never `l18n_parent`. | `NewContentElementController.php:82-86,170-201` | The DataHandler hook detects "new, language > 0, no parent" and creates the shadow. |
| There are no PSR-14 events for datamap/cmdmap. Hooks: `processDatamap_beforeStart` runs **before** `DataMapProcessor::process()`. | `DataHandler.php:668-1003` | Shadow injection happens in `beforeStart` by adding a `NEW…` shadow to `$dh->datamap`. |
| `DataMapProcessor` pushes parent values to translations: `l10n_mode=exclude` always; `allowLanguageSynchronization` fields default to `parent` when `l10n_state` is missing. | `DataMapProcessor.php:294-457`, `State.php:225-239` | Field partition (§3.3) is mandatory, or page translations lose `shortcut`, `media`, `nav_hide`, ... |
| Page tree reads only language-0 rows. `AfterPageTreeItemsPreparedEvent` exists. | `PageTreeRepository.php`, `TreeController.php:388-460` | Editing-Language titles via the event listener. |
| The Records module language filter restricts to `l10n_parent=0`. | `DatabaseRecordList.php:2607-2616` | Filtering on language 1 hides everything in the PoC. Use `ModifyDatabaseQueryForRecordListingEvent`, or document it as a known gap. |

---

## 3. Data model and invariants

### 3.1 Invariants (all sites)

- **I1:** Every record with `sys_language_uid = 0` is a structural shadow.
  No separate marker column is needed. Lolli: "avoid new DB fields at all
  costs" (2024-02-23).
- **I2:** Every record with `sys_language_uid > 0` has `l10n_parent > 0`,
  pointing to a shadow on the same `pid` (the overlay requires the same pid).
- **I3:** Per shadow and language there is at most one translation (no
  duplicate parents; Forge #110328).
- **I4:** **A shadow without a translation must never exist** (decided
  2026-10-14). Every operation that creates a shadow also creates at least
  one translation in the same DataHandler run. Every operation that removes
  the last translation also soft-deletes the shadow. This covers creation,
  page-tree creation, delete, discard, Recycler restore and migration.
- **I5:** A shadow is **never hidden** and its time window stays open.
  Visibility lives only on concrete language records. "Hide in all
  languages" writes `hidden` on every translation (§4 H), not on the shadow.
- **I6:** `l10n_source` records content origin and may point to any
  language, or be `0` for content created directly in a language.
- **I7:** `-1` records stay single records with no shadow. They live in the
  same per-page `sorting` space as shadows and translations, so their
  position is their own `sorting` value.

The extension ships a CLI `structure-layer:check <site>` that reports
violations of I1–I5. It is used in tests and after migration.

### 3.2 Shadow content

The open question from 2026-05-29 and 2026-08-14 was whether a shadow should
carry content. **Decision (2026-10-14): start with `empty`.** Switch to
`label` or `copy` only if `empty` proves problematic, and record why in the
findings.

- **Structural fields** (§3.3) are stored on the shadow and synchronized down.
- **All payload fields, including the label field, stay empty.**
- Wherever the backend needs a label for a shadow (structure column,
  Records module, page tree, move dialogs), it is resolved at display time
  with the same rule as page-tree titles: preferred editing language first,
  then the first available translation in site-language order. Because of
  I4 a translation always exists, so a label can always be resolved.
- The strategy stays configurable
  (extension setting `shadowContent: empty|label|copy`). Switching to `copy`
  later tests Eric's question (2026-08-14: "would copying let existing sync
  mechanisms be reused?").

**Risks of `empty` to check in Spike S4 (Phase 0):**
- `pages.title` is `required` in TCA (`core/Configuration/TCA/pages.php:130`).
  Find out how DataHandler treats an empty required value on a shadow:
  rejected record, dropped field or error log.
- Possible ways out:
  - make `title` not required for language 0, via
    a TCA override or a FormEngine/DataHandler check;
  - fall back to `label` for pages only.
- `pages.slug` (`uniqueInSite`) of shadows is generated from an empty title.
  Check for collisions and for the `/_structure/` routes.
- Other `required` fields in content-type TCA (e.g. extension CTypes) have
  the same issue. List them in S4.

### 3.3 Field partition: structural vs. payload

| Class | Rule | tt_content | pages |
|---|---|---|---|
| **Structural (enforced)** | Lives on the shadow; existing `l10n_mode=exclude` behavior pushes it down. | `CType`, `colPos`, `sorting`, `starttime`, `endtime` (already `exclude` in Core), `fe_group`* (made `exclude` by the extension), `pid` | `doktype`, `TSconfig`, `layout`, `backend_layout*`, `extendToSubpages`, `mount_pid*`, `is_siteroot`, `l18n_cfg`, `editlock`, `php_tree_stop`, `cache_*`, `module`, `no_index`, `no_follow` |
| **Synchronizable** (`allowLanguageSynchronization`) | On a translation created under a shadow, `l10n_state` is set to `custom` for every such field. The shadow never leads these values unless an editor explicitly selects `source`. | (few; enumerate in Phase 0) | `nav_hide`, `link`, `shortcut*`, `content_from_pid`, `media`, `author*`, `lastUpdated`, `newUntil`, `no_search`, `starttime`, `endtime`, og/twitter images |
| **Payload** | Only on translations. | `header`, `bodytext`, `image`, ... | `title`, `slug`, `nav_title`, `subtitle`, ... |

\* `fe_group` on tt_content: today it is per language, and under `strict`
the parent's value applies to every language as well. In free-style output
only the translation's value is checked, so the extension sets
`l10n_mode=exclude`. The value is then maintained once on the shadow
(editable from any language, §3.3) and written to all translations.

`pages.starttime/endtime` appear in both lists because the page shadow must
stay displayable (I5). The PoC keeps them per-language `custom`, leaves the
shadow's time window open and documents this.

**Editing a structural field from a translation** (2026-08-14: "exclude
fields editable from every language with an 'affects all languages'
indicator"):

- A `processDatamap_beforeStart` hook moves submitted structural values from
  the translation's datamap entry to the shadow's entry.
- FormEngine shows the field as editable with an "affects all languages"
  badge. It uses a FormDataProvider that removes `defaultAsReadonly`.

---

## 4. Components to build

### A. Site validation (extension)
- Check every site: language 0 `enabled: false`, distinct base, no
  `0` in any `fallbacks`. Report violations in the `check` command and in a
  backend flash message.

### B. Shadow creation on new records (extension, `processDatamap_beforeStart`)
- For each `NEW` entry in a localizable table with
  `sys_language_uid > 0` and no `l10n_parent`:
  1. Create `NEWshadow_<id>` in `$dh->datamap` **before** the record. It gets
     language `0`, the same `pid`, structural fields only (no label, §3.2)
     and `l18n_cfg |= 1` for pages.
  2. Set the record's `l10n_parent` and `l10n_source` (`0`, since it is
     original content) to the NEW shadow ID. DataHandler resolves NEW IDs in
     order. Verify that `DataMapProcessor` reads parent values from the
     in-memory datamap (`isSetInDataMap`, `DataMapProcessor.php:425-457`).
  3. Set `l10n_state = custom` for all synchronizable fields (§3.3).
- **Positioning:** if `pid < 0` ("insert after record X"), the shadow gets
  `pid = -(X.l10n_parent)` so it lands directly after X's shadow. The
  translation's own sorting is then aligned to the shadow's (see C).
- **Inline / file-reference children** of the new record stay language-scoped
  to the record. They are not shadowed in v1, but are recorded as a finding
  (relevant to Forge #110497/#110498 and Gerrit 95476).
- **Pages created in the page tree** (language 0 by construction): the page
  tree's new-page request is rewritten in `processDatamap_beforeStart`. A
  translation in the editor's Editing Language (fallback: primary language)
  is added **in the same datamap**, and `title` moves into it. The shadow
  and its first translation are therefore created atomically (I4). If the
  translation fails, the shadow is rolled back or deleted.

### C. Sorting alignment (extension, Core patch if needed) — **mandatory**
- With a chain (`OVERLAYS_MIXED`), output order comes from the shadow and
  is correct by construction.
- Without a chain (`OVERLAYS_OFF`), the frontend, the single-language
  Layout view and Extbase order by the translation's **own** `sorting`
  (`ContentObjectRenderer`, `BackendLayoutRenderer::drawContent`,
  `Typo3DbQueryParser`). Correct output in this mode therefore depends on
  this component.
- A hook keeps translation `sorting` = shadow `sorting` after create, move
  and localize. `colPos` is already synchronized through `l10n_mode=exclude`.
- This is a deliberate denormalization. Measure how often the values drift,
  and add a drift check to `structure-layer:check`.

### D. Moves (extension, `processCmdmap` / `moveRecordClass` hooks)

**Decision (2026-10-14):** a move always moves the element in **all
languages**. There is no per-language ordering override, because content
is connected.

- **Moving a translation** is redirected to a move of its shadow relative to
  the target's shadow. `moveL10nOverlayRecords` then carries all languages.
  The UI says so.
- **Moving a translation to another page:** move the shadow; the
  translations follow via the existing cascade.
- **Core patch:** `GridColumnItem::isDragAndDropAllowed()` allows
  connected translations.
- **Drag & drop payload** (`update: {colPos, sys_language_uid}`): language
  changes are rejected in the hook. colPos goes
  to the shadow.

### E. Deletion lifecycle (extension, `processCmdmap_deleteAction` / `postProcess`)
- Deleting a translation soft-deletes its shadow when it was the last
  translation (I4).
- Deleting a shadow keeps the existing cascade (`deleteL10nOverlayRecords`).
  The UI calls this "Delete in all languages".
- Restoring (Recycler) a translation whose shadow is deleted restores the
  shadow first.
- Restoring a shadow on its own would break I4. Two options, decided in
  Phase 2:
  - also restore the translations that were deleted with it; or
  - block the restore.

  Both behaviors are recorded in tests.

### F. Translate / copy (extension + one Core bugfix)
- **Layout comparison view:** a missing cell offers "Translate from …".
  This is `localize` with source = any existing sibling, which already
  yields parent = shadow and source = sibling.
- `LocalizationController::getSources()`: drop language 0 as a source
  (Core patch or event, if available). The source
  list = languages that have the record.
- **`copyToLanguage`** (Free Mode) is disabled:
  localization modes are restricted to translate. This is the "no editor-
  visible modes" product requirement.
- **Core bugfix (separate, test-first):** `LocalizationRepository`
  duplicate detection must also match by `l10n_parent` of the source for
  non-default sources. A failing functional test comes first. This may be
  upstreamable on its own, with its own Forge issue.

### G. Output policy: chain-driven output (Core patch + event listener)

**Decision (2026-10-14):** for all sites, the strict/free/fallback
distinction disappears. The presence of a fallback chain decides
everything. **Free is the default**: there is no overlaying with language 0
when no chain is configured. The Sites module shows `fallbackType` as
read-only. This is an extension override of the site-language TCA
(`$GLOBALS['SiteConfiguration']['site_language']['columns']['fallbackType']['config']['readOnly'] = true`),
with a description saying it is derived from `fallbacks`. This builds on André's proposal from 2026-10-09 (Topics 5–7),
which Eric and Martin did not yet consider fully understood. The PoC is
meant to make it concrete.

| Site language config | Effective behavior | Implemented as |
|---|---|---|
| `fallbacks` empty | Only the requested language. Concrete records are selected directly, with no dependency on the shadow's visibility. A missing or disabled page translation → 404. | `OVERLAYS_OFF` (today's `free`) |
| `fallbacks` set, e.g. `2,1` | Per position: the requested-language record if it exists and is enabled. If it **exists but is disabled → nothing** (intentional absence, no fallback). If it is **missing → next language in the chain**. Never language 0. | `OVERLAYS_MIXED` (today's `fallback`) + listener |

Rules for chain members:
- A disabled record in a *fallback* language counts as "not available in
  that language", and the chain continues.
- Only the **requested** language's disabled record blocks fallback.
- Deleting the disabled translation re-enables fallback (2026-10-09,
  Topic 6).

This is a PoC choice to demonstrate; record whether it feels right.

**What actually differs between `strict` and `free`** (verified in
`PageRepository::getLanguageOverlay()` 350-436,
`getRecordOverlay()` 659-806 and
`ContentObjectRenderer::getLanguageRestriction()` 4838-4894):

| # | Difference | `strict` (overlay from `0`) | `free` (`OVERLAYS_OFF`) | How the PoC makes `free` equivalent |
|---|---|---|---|---|
| 1 | Visibility | Parent **and** translation must pass `hidden`, `starttime`, `endtime`, `fe_group`. | Only the translation is checked. | `hidden`: explicit per-language toggle plus "disable in all languages" (H). `starttime`/`endtime`: already `l10n_mode=exclude` on tt_content, so they are synchronized to translations. `fe_group`: made `exclude` (§3.3). |
| 2 | Ordering | Parent's `sorting`. | Translation's own `sorting`. | Component C keeps them equal. |
| 3 | Which records | `0`/`-1` rows, overlaid, plus floating records (`l10n_parent = 0`) of the requested language. | Records with language = requested or `-1`. | The sets are identical, because every record is connected (I2). `-1` is included in both. |
| 4 | Record identity | Row keeps the parent `uid`; `_LOCALIZED_UID` = translation. | `uid` = translation `uid`. | No equivalence possible. This is a **finding**: content anchors (`#c<uid>`), cache tags, frontend-editing links and Extbase identities become per-language. Document the visible effects. |
| 5 | Records fetched by a shadow UID | Overlaid to the requested language. | **Not overlaid** for non-page tables: `getLanguageOverlay()` returns the raw row. | **This is the real gap.** Shortcut CType (`records`), `RECORDS`, `t3://record`, category MM relations, Extbase relations and `findByUid()` all store or resolve default (shadow) UIDs and would render the **empty shadow**. The listener (step 2 below) resolves shadow → requested-language translation even when no overlay was attempted. |

Pages are always overlaid, even in `free`. However, page resolution still
fetches the language-0 page with enable fields (`PageInformationFactory`
154-210), so a hidden shadow page would 404 in every language. I5 (shadows
are never hidden) covers this.

So for content the answer is yes: in this model, strict vs. free is about
visibility (hidden, time window, fe_group) and ordering, plus the
identity/reference effects in rows 4 and 5. Row 5 must be solved, and
row 4 must be accepted or documented.

**Implementation:**
1. **Core patch** in `LanguageAspectFactory::createFromSiteLanguage()`, or
   where `SiteLanguage` objects are built:
   - effective `fallbackType` = `free` when `fallbacks` is empty;
   - `fallback` otherwise;
   - configured `fallbackType` ignored.
   Spike S5 picks the least invasive location.
2. An **`AfterRecordLanguageOverlayEvent` listener** (all sites):
   - **no overlay attempted** (free mode) and the row has
     `sys_language_uid = 0` → mark as attempted and replace it with the
     requested-language translation of that shadow, or `null` if there is
     none or it is disabled (difference #5);
   - result row has `sys_language_uid = 0` → `null` (an empty shadow is
     never rendered);
   - requested language ≠ the result's language, and a translation of the
     same shadow exists in the requested language with `hidden = 1` (or a
     closed time window) → `null`. The PoC uses one extra query per
     overlaid record and measures its cost.
   - This covers TypoScript `CONTENT` / PAGEVIEW content areas and Extbase
     single-record overlay.
3. **Pages:** check the same rules for page fallback (`PageInformationFactory`
   `content_fallback` branch, `getPageOverlay`, menus via
   `isPageSuitableForLanguage`). A disabled page translation in the
   requested language must give a 404 (or be omitted in menus) even with a
   chain. If the page path cannot be covered by events, add a Core patch.
4. Language menus already exclude disabled languages (`getLanguages()`).
   Verify hreflang `x-default`.

**Known gaps to measure, not fix:**
- Extbase `Typo3DbQueryParser` (MIXED) computes counts and limits in SQL
  and may include positions that the listener later drops.
- The same applies to pagination in TypoScript `CONTENT` with
  `max`/`begin`.
- Custom queries that read `sys_language_uid` directly bypass the policy.

**Compatibility note** (`current-state.md`: "removing a selector must not
silently activate a previously inactive saved chain"):
- Today `strict` and `free` can carry a saved but inactive `fallbacks`
  list.
- The migration (I) must not turn that list into an active chain. It moves
  such lists to a per-language `inactiveFallbacks` key and reports them.

### H. Layout module UX (extension templates + TSconfig + small Core patches)

**Phase 3a: structure visible**
- Keep the structure column (André, 2026-05-29: keep it visible to verify
  shadow creation).
- Relabel it "Structure" and render shadow cells compactly.
- Remove Mixed/Free badges: with I2 every column is "connected".
- Enable new-content buttons via `allowInconsistentLanguageHandling = 1`.

**Language All (`-1`) records** (decided 2026-10-14)
- A `-1` record is rendered in **every** language column, at its own
  `sorting` position between the shadow-driven rows. In the comparison view
  this is one row spanning, or repeated in, all columns.
- Every instance links to the **original** `-1` record (edit, hide,
  delete). There are no shadows or per-language copies.
- Builds on the WIP approach of Gerrit 95475 (Forge 110510): distinct DOM
  IDs per occurrence, the record counted once.
- This is required, not optional: once the structure column is hidden in
  3b, `-1` records would otherwise disappear from the backend.
- Moving a `-1` record changes only its own `sorting`. Drag & drop must not
  change its language (the 2026-08-21 regression).

**Phase 3b: Editing Language**
- A docheader selector holds a concrete language; "All Languages" is not
  allowed (2026-09-04). It is stored in BE user module data.
- The editing language becomes the primary (left) column. The structure
  column is hidden (template override). Rows are still shadow-driven.
- The single-language view defaults to the editing language.
- A page missing in the editing language shows an empty state "Available in
  other languages" plus a "Translate page" button that opens the existing
  wizard (2026-05-08).

**"Hide in all languages" toggle** (decided 2026-10-14: always available in
the backend for the PoC, so it is easy to demo; no structural permission)

Visibility is written explicitly at edit time (André, 2026-10-09, Topic 7)
instead of being inherited from the parent at render time:

- **"Hide in this language"** is the existing per-record `hidden` toggle on
  the translation.
- **"Hide in all languages"** / **"Show in all languages"**: a datamap that
  sets `hidden` on **every translation** of the shadow in one DataHandler
  run. The shadow itself is never hidden (I5).
  - Implemented as an extension action (Layout module button → AJAX route
    → DataHandler). A DataHandler option (e.g. a datamap flag the
    hook expands) is the API counterpart the meeting
    asked for.
- Every content element row in the Layout module gets both toggles. The
  page gets them in the Layout module header.
- The badge "hidden in all languages" is **derived**: all existing
  translations are hidden. There is no stored Disable-All flag.
- Consequences to show in the demo:
  - a translation created later for an element that is hidden everywhere
    starts out visible;
  - a disabled translation blocks fallback on chain sites (§4 G).

**Page tree**
- An `AfterPageTreeItemsPreparedEvent` listener resolves titles:
  1. the preferred Editing Language;
  2. otherwise the **first available translation** in site-language order,
     marked subdued and with that language's flag.
- A backend-only fallback order may come later; it is not part of the PoC.
- A shadow is never shown with its own (empty) title. A shadow without a
  translation would be an I4 violation, flagged by
  `structure-layer:check`.
- The same resolver is used for every other place where a shadow needs a
  label (§3.2).

### I. Migration command (extension, `structure-layer:migrate <site> [--dry-run]`)

The chosen strategy keeps existing language-0 rows as the shadows, so
**UIDs, `t3://page?uid=` links, MM relations and existing `l10n_parent`
pointers stay valid.**

1. Site config:
   - Add language `N` (next free ID) with the old default's title, locale,
     base `/`, hreflang and flag.
   - Language 0 becomes "Structure", `enabled: false`, base `/_structure/`.
   - Every `fallbacks` value `0` is rewritten to `N`.
   - Languages with `fallbackType: strict` or `free` lose their (inactive)
     `fallbacks` list. It is moved to a per-language `inactiveFallbacks` key and
     reported (§4 G compatibility note).
     *Corrected in Phase 4 (baseline):* strict keeps its chain without `0`
     (records do fall back there, never to the default record); fallback gets
     N appended (it always ended with the default record); only free loses
     its chain. See `docs/findings.md`, Phase 4.
   - `fallbackType` itself is left in place, ignored.
2. For every language-0 record of the site's tables (pages first, then
   content, then other localizable tables): create a language-`N`
   translation via `localize`. Set all payload fields to `custom` and copy
   the payload.
3. **Visibility translation** (I5, explicit visibility):
   - A hidden default record becomes a visible shadow, a hidden language-`N`
     translation, **and `hidden = 1` on every existing translation**. This
     materializes today's strict-mode parent dependency as explicit values.
   - This is the main behavioral change to verify: report the counts.
   - On former `fallback` sites the old output of other languages showed
     the default record. Compare it in the before/after test.
4. Reduce shadows according to `shadowContent`. Set `l18n_cfg |= 1` on page
   shadows. Align translation `sorting` to shadow `sorting` (C).
5. **Free-Mode records** (`language > 0`, `l10n_parent = 0`): create one
   shadow per record at its sorting position. **Never auto-match**
   (2026-08-21). Report candidates via `l10n_source` for manual connection.
6. **`-1` records:** leave untouched and report them.
7. Run `check` and output counts (rows before/after per table, shadows per
   page, translations whose visibility changed) as measurement data.

**Rollback:** the dry run writes a JSON plan. For the PoC, rollback means
a DB snapshot. This is noted as a gap for a real migration.

---

## 5. Phased plan with gates

Each phase starts with failing functional tests (AGENTS.md test-first rule)
and ends with a short findings note in `docs/findings.md`.

### Phase 0: Spikes and fixtures (≈2 days)
- **Spike S1:** language 0 first vs. language 1 first in YAML. Classify all
  `getDefaultLanguage()` callers (link building, hreflang, PageLayoutContext,
  SiteBaseRedirectResolver, PageInformationFactory) as "means structure" or
  "means primary language".
- **Spike S2:** confirm `processDatamap_beforeStart` injection + NEW-ID
  resolution + DataMapProcessor in-memory parent lookup.
- **Spike S3:** enumerate synchronizable fields of `tt_content` and
  `sys_file_metadata` in the current TCA.
- **Spike S4:** empty shadows vs. `required` / `uniqueInSite` fields (§3.2).
- **Spike S5:** where to derive the effective `fallbackType` from
  `fallbacks`:
  - `Site` constructor / `SiteLanguage` creation, or
    `LanguageAspectFactory`;
  - confirm that `AfterRecordLanguageOverlayEvent` can veto fallback when
    a disabled requested-language translation exists;
  - find which page paths need a patch.
- Fixture extension with a site `structure-site`:
  - language 0 = structure;
  - 1 de, no chain;
  - 2 en, chain `1`;
  - 3 en-GB, chain `2,1`.
- **Gate:** S1 and S5 have a workable configuration without Core changes,
  or a minimal list of Core patches.

### Phase 1: Chain-driven frontend output on hand-crafted data (≈3 days)
Tests in `structure_layer/Tests/Functional/Frontend/` modeled after
`LocalizedSiteContentRenderingTest` and `LocalizedPageRendering/Scenario*`:
- **No chain (de):** only German records render, in shadow order (via C).
  Untranslated positions are absent. `fallbackType: strict` in the YAML
  makes no difference.
- **Chain (en→de):**
  - a missing en element falls back to de and never to the shadow;
  - a *disabled* en element renders **nothing** at that position;
  - deleting the disabled en element makes the de fallback reappear.
- en-GB→en→de chain (Johannes' case, 2026-07-31), including a disabled
  record in the middle language (the chain continues).
- "Hide in all languages" → the element is absent in every language, with
  or without a chain.
- Pages:
  - disabled page translation in a no-chain language → 404;
  - disabled page translation in a chain language → 404, no fallback;
  - missing page translation in a chain language → page fallback;
  - menus follow the same rules.
- Records fetched by a shadow UID in a no-chain language (difference #5):
  - a shortcut CType referencing another element;
  - `t3://record` links;
  - category relations;
  - Extbase `findByUid(shadowUid)`.
  All of them must render the requested-language translation, never the
  shadow.
- `-1` records render in every language, in both modes, at their
  `sorting` position.
- `fallbackType: strict` vs. no `fallbackType` in the YAML: identical
  output.
- Language menu, hreflang and `/_structure/` (404 for anonymous users).
- Extbase `TranslatedSiteContentTest`-style queries for both modes.
- **Gate:** no shadow is ever rendered, a disabled requested-language
  record always blocks fallback, and output equals the expected
  per-language content.

### Phase 2: DataHandler behavior (≈4 days)
Tests in `structure_layer/Tests/Functional/DataHandling/` in
`DataScenarios`-style with CSV assertions:
1. Create content in language 2 only → shadow + connected record (B).
2. Insert after an existing translated element → correct shadow position.
3. Translate from language 2 to 1 → parent = shadow, source = language-2
   record. No duplicate when 1 already exists (F bugfix).
4. Edit a structural field (colPos, CType) from a translation → written to
   the shadow, propagated to all.
5. Synchronizable page fields keep their per-language values (§3.3).
6. Move a translation → shadow moved; all languages follow (D).
7. Delete the last translation → shadow soft-deleted. Delete one of two →
   shadow stays (E).
8. Delete the shadow → cascade. Recycler restore in both directions.
9. Copy a page / copy content to another page within the site
   → shadows + translations copied consistently, invariants hold.
10. Inline children (`tt_content.image`) of content created in language 2.
11. "Hide/show in all languages" → `hidden` written on every translation in
    one run, shadow untouched. Per-language hide touches one record only.
12. Translation `sorting` equals shadow `sorting` after create, move,
    localize and copy (C).
- **Gate:** `structure-layer:check` is clean after every scenario.

### Phase 3: Layout module (≈4 days)
- 3a, then 3b, as in H. Functional tests via `BackendLayoutRendererTest` /
  `BackendLayoutRendererLanguageComparisonTest` YAML scenarios. Manual
  verification in the DDEV instance (`ddev demo`).
- Core patches: drag & drop, localization sources.
- A `-1` record appears in every language column. Editing it from any
  column opens the original record, and moving it does not change its
  language.
- The Sites module shows `fallbackType` as read-only.
- **Gate:** the "mostly connected, selectively different" walkthrough works
  end-to-end without any Mixed/Free state appearing:
  - German page with 5 elements, all translated to English.
  - Add 1 English-only element between elements 2 and 3.
  - Hide element 4 in English only.
  - Reorder.
  - Translate the English-only element to German later.

### Phase 4: Page tree + migration (≈3 days)
- Page tree titles and missing-page state (H).
- Migration command (I), tested on:
  - the Core `RegularLocalize` import fixtures;
  - a Free/Mixed fixture;
  - the t3thi `translation-handling` test extension data set.
- Assert that frontend output before and after migration is identical for
  all non-structure languages.

### Phase 5: Ambiguity and permissions (≈3 days)
- Move disambiguation: when shadows of other languages lie between the
  visible neighbors, show a modal with the structural positions
  (2026-05-29, Option B). Moves are always all-language, so the modal only
  decides *where* in the shared structure the element lands, not *which*
  languages are affected.
- A restricted editor (`allowed_languages = 2`) creates, moves and deletes
  content. Record exactly where DataHandler permission checks on language 0
  block shadow writes (`checkLanguageAccess`,
  `addDefaultPermittedLanguageIfNotSet`). Prototype one option: system-
  managed shadow writes for "content operations", plus a separate
  "structure" permission for moves. Characterize only; no final model.

### Phase 6: Characterization of the hard edges (≈2 days, document only)
- **Workspaces:** create-in-language, move, delete, publish and discard on
  the PoC installation. Record what breaks.
- **`-1` records:** Workspaces, copy/paste across pages, and any frontend
  path not covered in Phase 1.
- **Broken Core tests:** apply `patches/` to a Core monorepo checkout
  (`git apply --directory=typo3/sysext/<ext>`) and run the unit and
  functional suites there. List every failing test with its reason (expected behavior
  change vs. PoC bug). This list makes the breaking scope concrete.
- **`l10n_diffsource`** with a non-default `l10n_source` (2026-08-14:
  "expose rather than silently preserve").
- **Measurements:**
  - row counts before/after migration;
  - queries per frontend request (no chain / chain, including the extra
    disabled-translation lookup of §4 G);
  - Reference Index size;
  - write amplification per operation.

  The T3DD26 analysis demands "absolute counts rather than adjectives".

---

## 6. Core patch list (expected)

Each change is one isolated patch file in `patches/`, registered in
`composer.json` (`extra.patches`). The patches apply unconditionally, with
no guard. Separate patches keep it clear which Core extension points are
missing, and that list is itself a result of the PoC. Phase 0 refined this
list (`docs/findings.md`, "Gate result").

1. `GridColumnItem::isDragAndDropAllowed()`: allow connected translations.
2. `LocalizationController::getSources()` / `getModes()`: no language 0
   source, translate-only.
3. `PageLayoutContext` / `BackendLayoutRenderer`: primary column =
   editing language (only if templates and events are not sufficient).
4. Possibly `Site::getDefaultLanguage()` callers, depending on Spike S1.
5. `LocalizationRepository` duplicate detection. This is a genuine bugfix
   candidate, independent of the PoC.
6. Effective `fallbackType` derived from `fallbacks` (`Site`/`SiteLanguage`
   or `LanguageAspectFactory`, decided in S5). Page-path patches if the
   overlay event does not cover disabled page translations.
7. `DatabaseRecordList` language filter (or event listener), only if time
   permits.

---

## 7. Deliverables

- The `t3thi/structure-layer` repository with the extension, the Core
  patches (`patches/`) and functional tests. Run them with `ddev test`.
- `docs/findings.md`, structured along the initiative's
  decision gates:
  - STR-1 field partition;
  - SYN-1 lifecycle;
  - UX-1 / permissions;
  - WS-1;
  - REF-1;
  - SCALE-1 numbers;
  - MIG-1 migration result.
- A short screen recording or `e2e-browser` walkthrough of the Phase 3 gate
  scenario for the initiative meeting and the Product Strategy Group.
- A list of missing Core extension points (from §6).

---

## 8. Decisions (2026-10-14) and follow-up questions

| # | Question | Decision |
|---|---|---|
| 1 | Shadow content strategy | `empty` first; switch to `label`/`copy` only if problematic (§3.2) |
| 2 | Move semantics | A move always moves the element in all languages; no per-language override; content is connected (§4 D) |
| 3 | Hide-everywhere | A backend toggle on every element and page, for easy demos; no permission gate. It writes `hidden` on every translation; the shadow is never hidden (I5, §4 H) |
| 4 | Page-tree title source | Preferred editing language, otherwise the first available translation; a shadow without a translation must never exist (I4, §4 H) |
| 5 | Framing of `0` | **A: `0` stays the structure layer.** No separate structure entity and no tracking of `0` dependencies; keep the PoC small (see below) |
| 6 | Output modes | `fallbackType` is ignored on all sites; no `fallbacks` = only the requested language; `fallbacks` set = runtime fallback; a disabled requested-language translation blocks fallback (§4 G) |

### Framing of `0` (decided 2026-10-14: Option A)

For the PoC, `sys_language_uid = 0` *is* the structure layer. It is not a
stand-in for a later separate structure entity.

- Existing `=== 0` / `> 0` comparisons in Core and extensions are used as
  they are. This is a deliberate benefit of the approach (André,
  2026-08-14).
- The PoC does not inventory dependencies on the literal `0` and does not
  prototype a separate identity table.
- Known trade-off, stated once in the findings and not worked on: the
  language field keeps one non-language value (`0` = structure). That
  conflicts with the long-term requirement that the language value
  identify only a real language (BCP 47). Resolving this is left to the
  initiative's language-identity work stream.

### Follow-up questions (not part of the PoC)

- **Default Language marker** (2026-10-09, Topic 1): a named marker for `0`,
  analogous to the merged `LanguageMarker::ALL_LANGUAGES`
  (`core/Classes/Schema/LanguageMarker.php`). This is a Core follow-up and
  is not needed for the PoC under Option A.
- **Removing `fallbackType` for real:** dropping it from the schema and
  migrating saved inactive chains in a Core upgrade wizard. The PoC only
  makes it read-only and ignores it.
