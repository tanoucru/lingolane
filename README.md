# Lingolane

A lightweight multilingual plugin for WordPress, originally built for the Teachme project. Developed by @tannguyen. Released on two channels: a lite edition on WordPress.org and a separate standalone release.

- Every WordPress language that has translations (131 languages on WP 7.1; the catalog comes from the WordPress.org API, with 44 fallback languages when offline; variants such as `de_DE_formal` are skipped). Adding a language only downloads the WordPress/theme/plugin language packs for that locale (`Lingolane_Packs`); later updates follow WordPress's own translation update mechanism for every site language
- Translation groups for posts, pages and terms
- Tree view for the Pages/Posts lists: expand or collapse translation groups, remembered state, progress display and rows for creating missing translations; groups are kept after Quick Edit
- Language subdirectory URLs (`/vi/…`); the default language has no prefix
- Interface string translation (themes, plugins), CSV import/export
- hreflang / x-default, language switcher (`[lingolane_switcher]`, widget, menu item `#lingolane-switcher`)
- Internal link rewriting, translation progress statistics
- Light "SaaS" admin interface: color and size tokens in `assets/admin.css`, inline SVG icons (`Lingolane_UI::icon()`), illustrations for empty screens, toasts for notices; no external fonts, no CDN, respects `prefers-reduced-motion`. Icons: [Lucide](https://lucide.dev) (ISC, `assets/LICENSE-lucide.txt`)

The plugin code lives in `lingolane/`. Requires WP ≥ 6.2 and PHP ≥ 7.4. License: GPL-2.0-or-later.

## Identifiers
Slug and text domain `lingolane`; functions/options/tables/filters `lingolane_`; constants `LINGOLANE_`; classes `Lingolane_*`; shortcode `[lingolane_switcher]`.

## Extension hooks
| Filter | Default | Purpose |
|---|---|---|
| `lingolane_translatable_post_types` | `post`, `page` | Post types that can be translated |
| `lingolane_shared_page_slugs` | `[]` | Page slugs shared by all languages (URL unchanged) |
| `lingolane_shared_post_types` | `[]` | Post types shared by all languages |
| `lingolane_shared_taxonomies` | `[]` | Shared taxonomies |
| `lingolane_gated_post_types` | `[]` | Untranslated items of these types are left out of hreflang/sitemaps |
| `lingolane_language_catalog_source` | WordPress.org list + fallback | Locale catalog ⇒ [native name, English name] for the language picker |
| `lingolane_pre_install_language_pack` | `null` | Return `true`/`WP_Error` to skip the language pack download (tests, sites without file write access) |

Default languages: English (source, no prefix) + Vietnamese; change them under Settings.

## Interface language
Source strings are in English, text domain `lingolane`. `lingolane/languages/` contains `lingolane.pot` and `lingolane-vi.po/.mo`. Regenerate the template: `wp i18n make-pot lingolane lingolane/languages/lingolane.pot --slug=lingolane --domain=lingolane --skip-js --exclude=tests,languages`. Compile `.mo` files: `python3 bin/make-mo.py` (build.sh runs it automatically).

## Build
```bash
bin/build.sh   # checks the version matches in 3 places, lints PHP, writes dist/lingolane-<version>.zip (tests/ excluded)
bin/ui-preview.sh   # builds, installs on the Local site `check`, renders dist/ui-preview/<tab>.html for the 6 admin tabs
python3 -m http.server 8765 --directory dist/ui-preview   # view without logging in
```

## Upgrading from the `tml_` pre-release (Teachme)
On activation the plugin renames the `tml_translations`/`tml_strings` tables and copies the `tml_settings` option to the new name (no data loss). `includes/compat-legacy.php` keeps the `TML_*` aliases, `tml_get_translation_url()`, the `[tml_switcher]` shortcode and the `#tml-switcher`/`{{tml}}` menu items so the Teachme theme and plugins keep working; turn it off with `define( 'LINGOLANE_NO_LEGACY', true )`. **Deactivate the old plugin before activating the new one** (the two cannot run side by side).

Teachme mu-plugin (replaces the lists that used to be hard-coded):
```php
$cpts = array( 'listing','science','language','computer-science','business','business-english','education','music','art-crafts','sport','career-coaching' );
add_filter( 'lingolane_shared_page_slugs', fn() => array( 'create','edit','remove','my-listings','my-favorites','profile','reset-password','change-password','logout','transactions','payment','invoices','my-watchdogs','package-information','listing-slider','form-redirect','report-spam-abuse-or-inappropriate-content' ) );
add_filter( 'lingolane_shared_post_types', fn() => $cpts );
add_filter( 'lingolane_gated_post_types', fn() => $cpts );
add_filter( 'lingolane_shared_taxonomies', fn() => array( 'listing_categories','locations' ) );
add_filter( 'lingolane_translatable_post_types', fn( $t ) => array_unique( array_merge( $t, array( 'listing','science','language','computer-science','faq' ) ) ) );
```
`is_tutor()`/`translated_tutor()` were renamed to `is_gated()`/`translated_gated()`.

## Tests and quality
The PHPUnit suite (80 tests, 400 assertions) lives in `tests/phpunit/`, one file per feature area: translation groups, router, URLs/hreflang, shared records, default language, admin, interface strings, language catalog, icons and UI tokens. Each test runs inside a transaction on the test database and rolls back.

```bash
composer install          # PHPUnit 9.6, WordPress test library, WPCS
composer lint             # phpcs: errors fail the build, warnings are only listed
composer test             # PHPUnit (needs a test database, see below)
```
**With wp-env (requires Docker):**
```bash
npx @wordpress/env start
npx wp-env run tests-cli --env-cwd=wp-content/plugins/lingolane-dev vendor/bin/phpunit
```
**Without Docker:** create a dedicated database (the test library **drops every table** in the database it uses, so never point it at a real site), write a `wp-tests-config.php` that points to that database and a WordPress directory, then set `WP_PHPUNIT__TESTS_CONFIG=/path/to/wp-tests-config.php` before running `composer test`.

CI (`.github/workflows/ci.yml`) runs on pushes to `main` and on every pull request: phpcs, `php -l` on PHP 7.4/8.1/8.4, PHPUnit via wp-env (PHP 7.4 with the minimum WP 6.2, PHP 8.1 and 8.4 with the latest WP; 8.4 is experimental), Plugin Check (`bin/plugin-check-gate.py`: errors fail the build, warnings do not) and zip packaging.

## Technical documentation
[docs/TECHNICAL-OVERVIEW.md](docs/TECHNICAL-OVERVIEW.md) (for code reviewers): flow diagram, hooks, data tables, external services, and the capability and nonce checks of every endpoint.

## Release plan
See [docs/RELEASE-PLAN.md](docs/RELEASE-PLAN.md). Analysis of Polylang/TranslatePress and proposals C1–C14: [docs/COMPETITOR-ANALYSIS.md](docs/COMPETITOR-ANALYSIS.md).
