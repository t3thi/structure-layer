# Core patches

The PoC needs a few changes in TYPO3 Core where no extension point exists
(see `docs/plan.md` §6 and `docs/findings.md`). They are applied to the
`typo3/cms-*` packages by `cweagans/composer-patches` on `composer install`.

- One patch file per change, named `<package>-<topic>.patch`, e.g.
  `cms-core-site-language-effective-fallback-type.patch`.
- Paths in a patch are relative to the **package root** (the split
  repository), not to the monorepo: `Classes/Site/Entity/SiteLanguage.php`,
  not `typo3/sysext/core/Classes/...`.
- From a monorepo checkout:
  `git diff --relative=typo3/sysext/core > patches/cms-core-<topic>.patch`
- Register it in `composer.json`:

  ```json
  "extra": {
      "patches": {
          "typo3/cms-core": {
              "Effective fallbackType from fallbacks": "patches/cms-core-site-language-effective-fallback-type.patch"
          }
      }
  }
  ```

- Re-apply after editing: remove `.Build/vendor/typo3/cms-<package>` and run
  `ddev setup`. `composer reinstall` does not work for `dev-main` packages: it
  removes the package and then fails with "Package is not installed".
- `composer.lock` is not versioned; `dev-main` moves, so a patch may stop
  applying. `composer-exit-on-patch-failure` makes that loud.
