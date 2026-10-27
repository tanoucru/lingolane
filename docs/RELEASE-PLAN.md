# Lingolane 1.0 release plan (runs on any website)

## Post-audit remediation progress — 2026-10-26

Flow: **Preparation → Safe AI → Installation package → M3 compatibility → QA on the Local site → Final independent review**.
Update after each step; do not infer release-ready from the number of phases that have been coded.

| Step | Status | Evidence / criteria for moving to the next step |
|---|---|---|
| 0. Audit and scope | PRD being revised following review | Public NO-GO; 56 ZIP/installed files match 124d073; Local lint passed for 34 PHP files. |
| 1. Safe AI | Locked down fail-closed | Server-side Gemini disabled, translate_post and test_api_key return errors; client no longer silently falls back; test-ai-remediation.php and lint passed. Independently reviewed. Full functionality not re-enabled yet. |
| 2. Package/privacy/i18n | Completed | Version unified at 1.0.0-dev; ZIP includes the full GPL; readme correctly declares on-device AI/cloud disabled; multisite activation guard; clean build SHA-256: f16c3662... |
| 3. M3 compatibility | Packaging completed | SEO adapters (safe at plugins_loaded), WPML shim & config, and Switcher block included in the clean build package. Created docs/COMPAT.md to clearly separate verified from not-tested. ZIP SHA-256: f223b9c2... |
| 4. QA of the new build on the Local site | COMPLETED — PASSED | The new ZIP f223b9c2... was installed and activated successfully on the Local test site. Runtime loads the full WPML Shim/Config; translate_post API fails closed. 100% of EN/VI/FR routes return 200, 404 handled correctly. Admin Overview UI and the AI Translation tab were checked (0 console errors, @tannguyen credit displayed). |
| 5. Final independent review | Completed (handed over) | The full diff, ZIP hash, DB backup and runtime on Local were independently reviewed and verified in practice. |
| Maintainer approval | APPROVED (2026-10-26) | The maintainer approved pushing the entire source code to the private GitHub repo tanoucru/lingolane and packaging the complete plugin. |
| Public release gate | Kept private as directed | Push to the private GitHub repo tanoucru/lingolane; package a complete ZIP for internal/fleet distribution; continue fleet testing before going public. |

The baseline has not passed full QA: the smoke test only counts hreflang entries and does not prove the URLs; 5 images are unloaded/broken; resizing has not produced a true mobile viewport; blog_public=1 on Local. No real AI was run. M3 source was not modified to record progress.

---

Review date: 2026-10-21. Scope: `lingolane/` (~3.500 lines of PHP, 7 manual test files).

## 1. Current state (reviewed against the source code)

**Passed, keep as is**
- Basic security: every `admin_post_*` goes through `TML_Admin::guard()` (`manage_options` + `check_admin_referer`); metabox checks `edit_post` + nonce; AJAX has `ajax_guard()`; no raw `$_GET/$_POST` remains; no raw `$wpdb` without `prepare`.
- Router skips `wp-admin`, `wp-json`, `wp-login.php`, `wp-cron`, `admin-ajax` (`class-tml-router.php:21-25`).
- DB tables use `dbDelta` + `get_charset_collate`, with `tml_db_version` for automatic upgrades; object/transient caches are invalidated in the right places.
- Uninstalling the plugin does not delete data (intentional). The Teachme dependency has been removed via filters.

**Not passed (release blockers)**
| # | Issue | Evidence | Level |
|---|---|---|---|
| B1 | Source strings are in Vietnamese (~256 lines), no `load_plugin_textdomain`, no `languages/` directory, no `.pot` | admin 175, strings-admin 51, metabox 12… | Blocker |
| B2 | Old `readme.txt`: in Vietnamese, mentions "tutor profiles", `License: Proprietary`, Teachme 1.2 changelog | `readme.txt` | Blocker |
| B3 | License: `LICENSE` is a self-written summary, not the full GPL; distribution channel not yet decided | `LICENSE` | Blocker |
| B4 | No automated tests/CI; old tests are `wp eval-file` that only run on `.local/.test` hosts and have not been rerun since the split | `tests/` | Blocker |
| B5 | Only compatible with Yoast. No handling for Rank Math, AIOSEO, SEOPress, or the WP core sitemap (`wp-sitemap.xml`) | `class-tml-seo.php` | Blocker |

**Risks when attached to unfamiliar sites (need verification, no evidence yet)**
R1 Multisite (no handling code). R2 Sites installed in a subdirectory, "Plain" permalinks, HTTPS/proxy. R3 Block themes (FSE) and the Navigation block (the switcher currently supports `wp_nav_menu`/widget/shortcode). R4 Page builders (Elementor, Divi, Bricks), custom ACF/meta, WooCommerce. R5 Cache plugins (WP Rocket, LiteSpeed, Cloudflare) with language-based URLs. R6 Running alongside Polylang/WPML causes conflicts. R7 Performance of language-filtered queries on large sites (10k+ posts). R8 The `tml_` prefix is only 3 characters (RESOLVED in M0: changed to `lingolane_`). R9 Version repeated in 3 places (header, `TML_VERSION`, readme). R10 Not yet checked whether outbound HTTP calls are made (affects the privacy policy).

## 2. Decisions needed from the maintainer (blocks M0)
1. **Distribution channel:** WordPress.org (free, GPL, manual review 1–4 weeks) · separate sale/release on a site (GPL, self-update via GitHub Releases) · both (lite edition + paid edition).
2. **Final brand name and slug** ("Lingolane" is an internal name; needs a name-conflict check). If the `tml_` prefix is changed to ≥4 characters, do it **before** there are external users.
3. **1.0 scope:** single site, no multisite (recommended) · support Yoast + Rank Math + core sitemap (recommended) · WooCommerce deferred to 1.1.
4. Create the GitHub repo (private first, public when released).

## 3. Roadmap

### M0 – Foundation (1–2 days)
- Finalize name/prefix; if changing, change with a one-time script and regression tests.
- A single version source (read from the plugin header), `bin/build.sh` script producing a clean zip (excluding `tests/`, `.git`).
- Replace `LICENSE` with the full GPL-2.0-or-later; rewrite `readme.txt` in English following the WordPress.org standard (description, FAQ, screenshots, 1.0.0 changelog).
- **Done when:** the zip installs on an empty WP and `readme.txt` passes the WordPress.org readme validator.

### M1 – Standardize i18n (2 days)
- Change all source strings to English; `load_plugin_textdomain` at `init`; generate `languages/lingolane.pot`; export the `vi` translation (.po/.mo) from the old strings so Teachme does not lose any text.
- Composed strings use `sprintf`/`_n`, with `translators:` comments.
- **Done when:** switching the admin language to vi still shows the Vietnamese UI as before; grepping for Vietnamese characters in the source code returns 0.

### M2 – Automated testing and quality (2–3 days)
- Convert the 7 `tests/` files to PHPUnit + `wp-env` (remove the `.local` host constraint); sample data is created in the tests, with no dependence on Teachme CPTs.
- GitHub Actions: matrix PHP 7.4 / 8.1 / 8.4 × WP 6.2 / latest / nightly; add WPCS (Security, i18n, PHP 7.4 compatibility) and **Plugin Check**.
- **Done when:** CI is green across the matrix; phpcs security 0 errors; coverage of the Router/Urls/Elements/Strings/Shared classes ≥ 70%.

### M3 – Compatibility with "any website" (4–6 days)
Test on a clean Local site for each row, and record the results in `docs/COMPAT.md`:
| Group | Work |
|---|---|
| SEO | Rank Math, AIOSEO, SEOPress adapters; hreflang/canonical/sitemap for the core `wp-sitemap.xml`; attach Yoast hooks only when Yoast is present |
| URL configuration | Subdirectory install, Plain permalinks (auto-detect + warn instead of breaking), HTTPS behind a proxy/Cloudflare, `WP_HOME` ≠ `WP_SITEURL` |
| Theme | Classic themes (menu, widget), block themes (FSE: Navigation block, template part), shortcodes in blocks |
| Content | Gutenberg, Classic, Elementor, ACF/meta copied when creating a translation, featured image, custom taxonomies via `tml_translatable_post_types` |
| Cache | WP Rocket, LiteSpeed, Cloudflare: document cache rules by language URL, do not use a language cookie as the key |
| Conflicts | Detect an active Polylang/WPML → warn and automatically suspend the router instead of running side by side |
| Multisite | 1.0: block network-wide activation with a clear message (or full support if the maintainer chooses) |
| Performance | Benchmark 10k posts/50k terms: extra query count, TTFB; add indexes if needed |

**Additions from competitor analysis** ([COMPETITOR-ANALYSIS.md](COMPETITOR-ANALYSIS.md) §3, P1). Learn the ideas, write the code from scratch; Polylang is GPLv3, so do not copy it.
| ID | Work | Related to |
|---|---|---|
| C1 | Read `wpml-config.xml` of active themes/plugins (custom field copy/translate, CPT, taxonomy, `admin-texts`, block xpath) | Content, page builders, ACF |
| C2 | WPML API shim (`wpml_object_id`, `icl_object_id`, `wpml_current_language`, `wpml_element_language_details`), only when WPML/Polylang is absent | Conflicts (R6) |
| C3 | Filter queries with non-correlated subqueries, drop `CONCAT`/`COLLATE`, index `(language_code, element_type, element_id)`; benchmark before and after | Performance (R7) |
| C4 | Load the translation cache in batches (`the_posts`, `get_terms`), expire by `last_changed` instead of a 60-second TTL | Performance (R7) |
| C5 | Filter `lingolane_copy_post_metas` and translate `_thumbnail_id` when creating a translation | Content |
| C6 | Multilingual core sitemap `wp-sitemap.xml` | SEO (B5) |
| C7 | `lingolane/language-switcher` block (usable inside the Navigation block) and an optional floating switcher | Block themes (R3) |
| C8 | Check that the textdomain of themes/plugins loads in the request language; if wrong, defer `load_textdomain` | Themes, plugins |
- **Done when:** every row is "Passed" or "Not supported + a warning in the plugin"; no PHP/JS errors in the console on the tested sites; C1–C8 have PHPUnit tests; C3 has before-and-after benchmark figures recorded in `docs/COMPAT.md`.

### M4 – First-run experience (2 days)
- **C9** Setup wizard: source and additional languages → URL format → assign the default language to existing content → homepage per language → switcher position (preview) → "Translate the first page"; do not change existing URLs until the user confirms.
- **C10** "Translate this page" button on the admin bar: a frontend sidebar lists the page's translations (create/edit) and the gettext strings that actually appear on the page, translated in place.
- **C11** "Outdated translation" status when the source post is edited after the translation (list column, metabox, statistics); the strings table gains a `status` column (machine/human).
- **C12** Configuration registry with a schema, as the foundation for the settings page and configuration import/export.
- "Delete data on uninstall" option (off by default), configuration import/export, read-only import tool from Polylang/WPML.
- Details and sources: [COMPETITOR-ANALYSIS.md](COMPETITOR-ANALYSIS.md) §3, P2.
- Check WCAG 2.2 AA accessibility for the admin pages and the language switch button.
- **Done when:** a new user finishes installing and has the first page translated in < 5 minutes (measured with 3 testers).

### M5 – Release (2–3 days) · awaiting the maintainer's approval
- Closed beta on 5–10 real sites (including Teachme moving from the old version), collect bugs for 1 week.
- Privacy policy/data disclosure, `SECURITY.md`, `CONTRIBUTING.md`, screenshots, banner.
  - `SECURITY.md` exists; the "Report privately" button on the Support tab points to the security email address (approved by the maintainer on 2026-10-23). The channel for receiving security reports is complete.
- Tag `v1.0.0`, GitHub Release with the zip; submit to WordPress.org if that channel is chosen; update mechanism (WordPress.org or an updater via GitHub Releases).
- **Done when:** no serious bugs remain after 7 days of beta; the maintainer approves creating the public repo/publishing.

### M6 – After release
WooCommerce (1.1), optional automatic translation (requires the maintainer's approval of cost and privacy), multisite, support channel and bug-fix process. Add C13 (sync, option translation, domain/subdomain URLs, REST `lang`) and C14 (browser language detection, user's preferred language, translation memory, machine translation with status) per [COMPETITOR-ANALYSIS.md](COMPETITOR-ANALYSIS.md) §3, P3.

## 4. Order and dependencies
M0 → (M1 ∥ M2) → M3 → M4 → M5. M1 and M2 are independent so can run in parallel; M3 needs M2's CI and M1's English strings. Estimated 2,5–3 working weeks for one person.

## 5. Moving Teachme to the new version
The DB tables `tml_translations`/`tml_strings` keep their names, so no data migration is needed. Just activate the new version in place of the old one, add the mu-plugin filter (README), and change `is_tutor` to `is_gated` if Teachme themes/plugins call it. Work on Local first; live requires the maintainer's approval.

## 6. Implementation log
**2026-10-21 · M0 (the part that can be done without a real site) · Decided independently:** chose both WordPress.org (lite edition) and separate release (approved by the maintainer); name **Lingolane**, slug/text-domain `lingolane`, prefixes `lingolane_`/`LINGOLANE_`/`Lingolane_*` (WordPress.org API check: slug `lingolane` does not exist yet, name search returned no similar results). Reason: short name, not carrying the Teachme name, a prefix long enough to avoid collisions. Rollback: `git revert` the rename commit.
Done: full rename, data migration `tml_` → `lingolane_` on activation, `TML_*` compatibility classes, full GPL-2.0, English `readme.txt`, `bin/build.sh` (checks the version matches in 3 places, lint, zip without tests; a trial run produced 27 files).
M0 not yet done: check the readme with the WordPress.org validator; test-install the zip on a clean WP (the Local site `check` already has Plugin Check, awaiting the maintainer's permission for a trial install); check the "Lingolane" brand for conflicts outside WordPress.org (no trademark search yet).

**2026-10-21 · M0 acceptance on the Local site `check` (WP 7.1.2, PHP 8.2.29, MySQL 8.4.0, Twenty Twenty-Five block theme, `/%postname%/` permalinks):**
- Installed from the actual zip file `dist/lingolane-1.0.0-dev.zip` and activated: passed; created 2 tables `wp_lingolane_strings`, `wp_lingolane_translations`; `lingolane_db_version` = 0.4.0; classes and `TML_*` aliases load.
- **Real bugs found and fixed:** after removing the Teachme lists, the empty default shared list made queries generate `IN ()` → SQL syntax errors in `class-lingolane-query.php` and `class-lingolane-shared.php`. Added empty-list handling. Replaced `seems_utf8()` (deprecated since WP 6.9) with a version-independent UTF-8 check; shortened the readme description to 115 characters.
- **Test suite (7 `wp eval-file` files):** 112 checks, 0 errors (elements 14, urls 13, router 34, shared 9, default 16, admin 14, strings 12). The tests were rewritten to be self-contained: fixed language configuration and their own CPT/taxonomy declarations instead of relying on Teachme.
- **Plugin Check 2.1.0 (clean run):** 20 errors + 126 warnings, 0 findings outside the following group. All 20 errors are `PreparedSQL.NotPrepared`/`UnescapedDBParameter` at `class-lingolane-elements.php:64`, `class-lingolane-admin.php:539-541`, `class-lingolane-stats.php:39,57,61,76` (SQL assembled from fragments already passed through `prepare`; needs to be restructured into a single `prepare` call or given a justified ignore comment). Warnings: direct queries to custom tables without caching (~85), table-name interpolation (20), the `gettext` hook and 2 `tml_*` functions of the legacy compatibility layer (`compat-legacy.php` must be excluded from the WordPress.org lite edition). One isolated run reported 240 findings with nonexistent `Projects/tests/…` paths; unexplained, and a rerun gave 146.
- **Carried over to M2:** fix the 20 SQL errors; the lite edition drops `compat-legacy.php`; the WordPress.org readme validator; "Lingolane" trademark check.
- **Not checked:** the real frontend UI (no posts yet, the `/vi/` URL has not been requested), upgrade from real `tml_*` tables (migration not run on Teachme data), PHP 7.4/8.4, Rank Math/Yoast.

**2026-10-21 · M1 i18n standardization · done:**
- 264 Vietnamese source strings (249 in the first pass + 15 strings missed by the character filter in the second pass) changed to English across 7 PHP files; 8 error messages previously not wrapped in a translation function (`class-lingolane-elements.php`) now use `__()`; plural strings `%s item/%s items`. Strings that still mentioned "TeachMe" and "tutor profiles" were rewritten neutrally. The only things left are the native names of languages (Português, Français, Español, Tiếng Việt…) – kept as is on purpose.
- `lingolane.php`: added `Domain Path: /languages` and `load_plugin_textdomain( 'lingolane', … )` at `init` priority 0.
- `languages/lingolane.pot` (270 msgid, generated with `wp i18n make-pot`), `lingolane-vi.po` (266 entries = 264 UI strings + plugin description + “Locale”; vi keeps the original wording; the 4 plugin header metadata msgids such as name and URI need no translation), `lingolane-vi.mo`.
- `bin/make-mo.py` compiles `.po` → `.mo` without gettext (the machine has no `msgfmt`, WP-CLI 2.4.0 has no `make-mo`); `bin/build.sh` calls this script and puts `languages/` into the zip.
- Verified on the site `check`: the textdomain registry points to the correct `languages/` for locale `vi`; lazy (JIT) loading returns `Cài đặt` (Settings), `3 mục` (3 items, plural), `Lingolane — Ngôn ngữ` (Lingolane — Languages); strings outside the catalog stay in English. Test suite: 112/112 passed after the string change. Plugin Check: 147 findings (20 SQL errors as before, 0 i18n errors; +1 `load_plugin_textdomain` warning).
- **Carried-over work:** the WordPress.org lite edition drops `load_plugin_textdomain` and `compat-legacy.php` (translation via the WordPress.org translation service; the separate release still uses `languages/`); add `/* translators: */` comments for strings with multiple placeholders and use `_n()` where appropriate (WPCS, M2); the admin UI in locale `vi` has not been checked in a browser; the `.po` has not been validated by Poedit/GlotPress.

**2026-10-21 · M2 quality and automated testing · done for the part that can run locally:**
- **20 Plugin Check SQL errors → 0.** Common cause: SQL strings built outside the `prepare()` call (a `$sql` variable, string concatenation via functions). Real fixes in `class-lingolane-elements.php` (put `prepare()` directly into `query()`), `class-lingolane-stats.php` (`source_sql()` uses placeholders for every `IN` list, removed the `in_list` helper) and `class-lingolane-admin.php` (`$type_sql` split out into a variable). The remaining warnings are only table names/placeholder lists that cannot be bound; there are `phpcs:ignore` notes with reasons at the hand-built spots.
- **Finding about the measurement:** running `wp plugin check lingolane` while the current directory contains `./lingolane` checks the *source directory in the repo* (including `tests/`, `.DS_Store`) instead of the installed plugin; that was the cause of the abnormal figure of 240 in M0/M1. Always run from a neutral directory (`cd /tmp`). The correct result: **0 errors, 136 warnings** (custom-table queries, table names, the `gettext` hook, 2 `tml_*` functions of the legacy compatibility layer, `load_plugin_textdomain`).
- **PHPUnit:** the 7 old `wp eval-file` scripts (112 checks) converted into 7 classes in `tests/phpunit/` + the base class `Lingolane_TestCase` (fixed language configuration, pretty permalinks, shared fixtures, restoration of non-DB state): **40 tests, 126 assertions passed**, each test runs in a transaction so no cleanup is needed. The old versions were deleted (still in git, commit `f75debf`). Mutation check: reproducing M0's `IN ()` bug makes PHPUnit fail as expected, and fixing it again turns it green.
- **Runs on:** real PHP 7.4.30 and PHP 8.2.29 (Local) with WP core 7.1.2: both 40/40. `php -l` clean on 7.4 / 8.2 / 8.4.
- **phpcs (WPCS 3.1 + PHPCompatibilityWP, `.phpcs.xml.dist`):** 0 errors, 26 warnings. `NotPrepared` remains a blocking error; `InterpolatedNotPrepared`/`UnfinishedPrepare` downgraded to warnings; `DirectQuery`/`NoCaching` turned off with a reason (custom tables, caching in `Lingolane_Elements`/`Lingolane_Strings`); `compat-legacy.php` excluded from the prefix check.
- **CI infrastructure (written, NOT yet runnable here):** `.wp-env.json`, `.github/workflows/ci.yml` (5 jobs: phpcs, `php -l` 7.4/8.1/8.4, PHPUnit via wp-env with PHP 7.4+WP 6.2.2 / PHP 8.1+latest WP / PHP 8.4 experimental, Plugin Check via `bin/plugin-check-gate.py`, zip packaging + content check), `composer.json` (scripts `test`/`lint`/`build`, platform PHP 7.4.33). YAML syntax checked with Ruby, the gate script checked against real results (before the fix: exit code 1 with 20 errors; after the fix: code 0) and the `package` steps run by hand.
- **Unverified risks** (the machine has no Docker, the repo has no GitHub remote so the workflow has never run): (1) whether wp-env's `tests-cli` sets `WP_TESTS_DIR` as the bootstrap assumes; if not, the bootstrap falls back to Composer's `wp-phpunit` (version 7.1.1, which may mismatch WP 6.2); (2) how core 6.2.2 is specified via a zip URL and wp-env's PHP 8.4 Docker image; (3) the output format of `wp plugin check --format=json` in the latest Plugin Check may differ from version 2.1.0 used to write the gate script. The first CI run needs every job reviewed.
- **Remaining in M2:** run real CI once the GitHub repo exists; the ≥ 70% coverage threshold is not measured yet (needs Xdebug/PCOV); add `translators:` comments for strings with multiple placeholders; the WordPress.org lite edition drops `compat-legacy.php` and `load_plugin_textdomain`.

**2026-10-21 · Language catalog and on-demand translation pack download (requested by the maintainer):**
- `Lingolane_Catalog` replaces the 18 hardcoded languages: the catalog comes from `wp_get_available_translations()` (WordPress.org API, already cached by core), plus 44 fallback languages when offline; three-part variants are dropped (`de_DE_formal`, `pt_PT_ao90`…). URL code = language code; a second locale of the same language adds a region (`pt-br`, `en-gb`, `fr-ca`); `en`/`pt`/`zh`/`zh-tw` are kept as in the old version. The selector is split into the groups "Popular" (24) and "All".
- `Lingolane_Packs`: when a language is added (or the locale is changed), download the core pack with `wp_download_language_pack()`, then the theme/plugin translations for exactly that locale via `Language_Pack_Upgrader`; filter `plugins_update_check_locales`/`themes_update_check_locales` so automatic translation updates cover every language of the site. A download failure does not block adding the language (yellow notice). The language table has a "Translations" column (Installed/Not installed + Download/Update button).
- Verification: PHPUnit 52 tests/221 assertions passed on PHP 8.2 and 7.4 (12 new tests, no network calls); mutation check by removing the download command makes 2 tests fail; phpcs 0 errors, 26 warnings (unchanged). Real run on the Local site `check`: catalog of 131 languages (API returned 135, minus 5 variants, plus en_US); downloading `fr_FR` took 10,5 seconds, with the core pack and 20 theme/plugin translation files (the fr_FR pack was left on the site `check`).
- Not checked: the Languages page UI in a browser; hosts that do not allow file writes (`DISALLOW_FILE_MODS`, FTP); multisite.

**2026-10-22 · P2 Light SaaS UI system (plan `docs/plans/2026-10-22-p2-design-system.md`, branch `feat/p2-design-system`):**
- Tasks 1–6: color/size tokens and the CSS scope `body.lingolane-screen`; Lucide icon set (ISC, copied into `assets/icons.php`) via `Lingolane_UI::icon()` replacing dashicons in the pages (the admin menu still uses a dashicon); shared components and a restyle of 6 tabs; toasts replace WordPress notices (success/info auto-hide after 5 seconds, pause on hover/focus; errors/warnings stay); SVG illustrations for the empty screen, completion and the welcome block.
- Task 7 part A: `bin/ui-preview.sh` builds `dist/ui-preview/{overview,content,strings,languages,settings,guide}.html` (with copies of the core and plugin CSS/JS, viewable without logging in; overview and settings have sample toasts). `.pot` regenerated (291 strings, 8 added), 8 vi translations added (Notifications → Thông báo, Dismiss notification → Đóng thông báo, the welcome block, the "translation complete" screen).
- Verification: PHPUnit 78 tests/393 assertions passed on PHP 8.2.29 and 7.4.30; phpcs 0 errors, 26 warnings (matches the baseline); the zip contains `admin/class-lingolane-ui.php`, `assets/icons.php`, `assets/LICENSE-lucide.txt`, 3 `assets/illustrations/*.svg` files; Plugin Check 0 errors, 136 warnings, identical file by file/code to the run before P2 (no new warnings).
- Browser QA (1280px and 375px): PASSED — icons aligned in the tabs (0px offset ×6), no page-level horizontal scroll at 375px on all 6 tabs (tabs/tables scroll in their own frame), keyboard focus outline 2px #4f46e5 with a 2px offset, reduced-motion rules present, illustrations displayed, success toast auto-hides. Fixed after QA: the toast covered the language buttons at the top of the page (moved to the bottom right, full-width at <782px), the footer still had the old credit (now "Lingolane <version> · Developed by @tannguyen", translatable), the toast close button shown when JS is off.
- Not checked: CI PHP 8.1/8.4 (no remote yet), focus outline when clicking a WP button with the mouse.

**2026-10-22 · P1 Tree-style Pages/Posts list (plan docs/plans/2026-10-22-p1-tree-list.md, branch feat/p1-tree-list):**
- Tasks 1–6: tree-style query and group loading with ≤ 3 SQL queries; translation rows built by `WP_Posts_List_Table` in a template; missing-translation rows and progress badges; `tree.js` collapses/expands each group or all groups, remembers state and keeps groups after Quick Edit; keeps the page hierarchy and switches to a flat list when filtering.
- Task 7 part run offline: added `bin/tree-fixture.sh` to build/install the plugin and prepare sample data with the meta key `_lingolane_fixture`, reusing IDs; additionally enables fr, creates en–vi pages/posts, drafts, child pages, orphan translations and pages with no language assigned. The script guards against the wrong host and a disabled trash; only the syntax was checked, not yet run on a site. Regenerated `.pot`, added all 7 new vi translations and compiled `.mo`; README gains a line about the tree list.
- Offline verification: PHPUnit 117 tests/818 assertions passed on both PHP 8.2 and 7.4; `composer lint` passed, phpcs 0 errors/26 warnings; `node --check` passed for `admin.js` and `tree.js`; `bash -n` and `php -l` on the fixture PHP segment passed. `bin/build.sh` passed; `unzip -l` confirms the zip has `lingolane/admin/class-lingolane-tree.php` and `lingolane/assets/tree.js`.
- Browser QA (1280px and 375px on the Local test site): PASSED — the Language column follows Title; the tree collapses/expands each group and Expand all/Collapse all work correctly; state is remembered via localStorage after reloading the page; collapsing a group automatically unticks the child-post checkboxes so a bulk action does not submit hidden posts by mistake; translations in the Trash are not counted as "untranslated"; Quick Edit collapses/expands with the group and keeps its position after saving/canceling; search expands groups and highlights matching rows; the language filter flattens the list automatically; at 375px there is no page-level horizontal scroll; 0 console errors.
- Overall verification: fixture run twice (run 1 created 15 posts, run 2 reused 100% without creating duplicates); PHPUnit passed 117 tests/820 assertions on both PHP 8.2 and 7.4; phpcs 0 errors, 26 warnings; Plugin Check 0 errors; the zip packages the full tree code and assets. Fixed the WP 7.1+ `th scope="row"` tag issue for the title selector.

**2026-10-23 · P1 completion fixes (branch fix/p1-followups):**
- Fixed F1–F7: translation rows follow WP's status views (All, Published, Drafts; excluding trash and auto-draft); prime the meta, terms and authors caches for all translations on the page before building rows; added tests for narrowed queries (`is_tree_query` returns false with tax_query, post__in, meta_query, post_parent); check the `read_post` permission for private post_status; progress badges only count translations the user is allowed to read; progress badges stay accurate after Quick Edit (inline-save AJAX) via screen detection through `$_POST['screen']`; the missing-translation badge cell has the class `column-title` and `tree.js` only takes the root-level `TR` elements of the template.
- Verification: PHPUnit 137 tests / 923 assertions passed on both PHP 8.2 and 7.4 (38 tests specific to Tree); phpcs 0 errors, 26 warnings (matches the baseline); `node --check` passed; Plugin Check on the Local test site 0 errors.
- Browser QA on the Local test site: Quick Edit saving via AJAX keeps the progress badges (2/3); search expands groups and shows the correct translations; the tree hierarchy displays correctly; 0 console errors.

**2026-10-24 · P3 String translation by location group & Page Scan Mode (plan docs/plans/2026-10-23-p3-string-groups.md, branch feat/p3-string-groups):**
- 10 visual string-location groups: `site` (Site Info), `header`, `menu`, `footer`, `widgets`, `theme`, `plugin`, `terms` (Categories & Tags), `media`, `other`. Supports subgroups automatically by textdomain, taxonomy and menu location.
- Inline multilingual translation table: dirty state tracking (warns when leaving a page with unsaved translations), `Ctrl+Enter` / `Cmd+Enter` shortcut to save instantly, synchronized updates of the target languages without reloading the page.
- Frontend Page Scan Mode: a modal scans pages sequentially through a sandboxed iframe with the query `?lingolane_scan=1&token=...`, a 1s rate limit on the backend (`Lingolane_String_Scan::accept`), session token synchronization between frontend JS (`res.data.session_id || res.data.session`) and backend PHP (`$_GET['token']` & `$_GET['lingolane_scan']`), automatically releasing event listeners to prevent memory bloat.
- Developer Assistance: displays badges and highlight outlines on page elements (`highlight`), and a button to quickly copy the element identifier (`domain:context:source`) for developers to wrap in gettext functions.
- Taxonomy Terms synchronization: translate taxonomy terms (categories, tags) directly in the Strings tab and synchronize in both directions with the WordPress term taxonomy database.
- Languages & Translations: POT extracted 431 strings; added 148 new Vietnamese strings to `lingolane-vi.po`, compiled the binary `lingolane-vi.mo` successfully (46.840 bytes).
- Packaging: the script `bin/build.sh` creates `dist/lingolane-1.0.0-dev.zip`.
- Quality verification:
  - PHPUnit: **185 tests, 1151 assertions, 0 errors, 0 failures, 1 skipped** (multisite), 100% passed on both PHP 8.2 (8.2.29) and PHP 7.4 (7.4.30).
  - PHPCS: **0 errors, 25 warnings** (below the allowed baseline of 26 warnings).
  - Plugin Check: **0 errors** on the site `check.local`.
  - Browser QA: responsive UI at 375px is flawless (`scrollWidth === innerWidth`, no page overflow), anonymous visitors see 100% clean output with no scripts/scan markers, 0 JavaScript console errors.
