# Competitor Analysis: Polylang and TranslatePress

Date: 2026-10-21. Purpose: identify what Lingolane should learn, including architecture and data handling from Polylang and user experience from TranslatePress.

**Sources reviewed (actual code, not recalled from memory):**
- Polylang `4.0.0-dev`, branch `master` of `github.com/polylang/polylang`: `src/translatable-object.php`, `src/translated-object.php`, `src/Model/Languages.php`, `src/mo.php`, `src/query.php`, `src/olt-manager.php`, `src/translate-option.php`, `src/modules/wpml/wpml-config.php`, `src/modules/sync/sync-post-metas.php`, `src/frontend/choose-lang.php`, and the `src/` directory tree.
- TranslatePress `3.3.8`, `plugins.svn.wordpress.org/translatepress-multilingual/trunk`: `includes/queries/class-query.php`, `class-translation-render.php`, `class-translation-manager.php`, `class-translation-memory.php`, `class-onboarding.php`, `class-language-switcher-v2.php`, `class-preferred-user-language.php`, and the "Translation Editor" documentation on the TranslatePress website.
- Lingolane: `lingolane/includes/*`, `lingolane/admin/class-lingolane-metabox.php` at commit `fca2b0a`.

**Licensing:** Polylang uses GPL-3.0-or-later, while Lingolane uses GPL-2.0-or-later. Copying Polylang code would require distributing Lingolane under GPLv3. TranslatePress uses GPL-2.0+, but an independent implementation is still preferable. **Learn from the ideas and write original code**, without copying.

## 1. Polylang: Architecture and Data

| Aspect | Polylang | Lingolane (`fca2b0a`) |
|---|---|---|
| Post/term language | Assigned through the `language` and `term_language` taxonomies (the core `term_relationships` table). Locale, rtl, and flag are serialized in the term's `description` (`Model/Languages.php::build_metas`). The language list is cached in the `pll_languages_list` transient | Dedicated `lingolane_translations` table (`element_type`, `element_id`, `trid`, `language_code`, `source_language_code`), using the same model as WPML's `icl_translations` |
| Translation groups | Each group is a term in `post_translations`/`term_translations`, with `description` = `{lang: id}` (`translated-object.php::save_translations`) | Grouped by `trid` and **stores the source language**; Polylang does not store this information |
| Query filtering | `tax_query` by `term_taxonomy_id` (`query.php::set_language`), producing an indexed INNER JOIN. All content must have a language | Correlated `EXISTS` with `CONCAT('post_', post_type) COLLATE …`, plus `OR NOT EXISTS` because unassigned content is treated as source-language content (`class-lingolane-query.php::condition`) |
| Cache | Primes multiple objects at once (`update_object_term_cache` → `wp_cache_add_multiple`), invalidated through `wp_cache_set_last_changed` | One query per element, `wp_cache_set(..., 60)` (`class-lingolane-elements.php::row`), prone to N+1 queries on archives, hreflang, and the switcher |
| String translation | Term meta `_pll_strings_translations` on language terms (`mo.php`, since 3.4) | `lingolane_strings` table, `gettext` filter, loads the entire table for one language into cache. Comparable |
| Creating translations | Separates **copy** (at creation) from **sync** (keeps taxonomies, featured images, templates, order, and parents synchronized). Filter `pll_copy_post_metas`; `pll_translate_post_meta` maps `_thumbnail_id` to the translated image | `duplicate_post()` copies all meta once (except `_edit_lock`, `_edit_last`, `_wp_old_slug`) and maps terms to their translations. No filters or sync yet |
| URL formats | Strategy classes `links-default`/`directory`/`subdomain`/`domain` | Only directories such as `/vi/` |
| Configuration | Typed registry (`src/Options/*`: Boolean/List/Map with built-in sanitization), exposed through REST `V1/Settings` | `lingolane_settings` array, handled in `class-lingolane-admin.php` (1.064 lines) |
| Ecosystem | Reads theme/plugin `wpml-config.xml`; provides a WPML API compatibility layer (`wpml-api.php`, `wpml-legacy-api.php`) | None |
| Language detection | `Accept-Language` header plus `pll_language` cookie; does not set cookies through JS when a caching plugin is present (`choose-lang.php`) | None |
| Textdomain | `olt-manager.php` defers `load_textdomain` until the request language is known | Only filters `locale`. Early-loaded `.mo` files may use the wrong language (not yet verified) |
| Other modules | Multilingual core sitemaps, switcher block (including within Navigation), REST `lang`, Site Health, cache compatibility, wizard | Yoast sitemap/canonical; switcher via shortcode, `#lingolane-switcher` menu item, and widget |

## 2. TranslatePress: User Experience

**Architecture (not recommended to follow):** TranslatePress translates HTML after rendering. `ob_start` → string extraction → `trp_dictionary_{source}_{target}` table (`original`, `translated`, `status`, `block_type`, `original_id`), plus `trp_original_strings`, `trp_original_meta`, and gettext tables. Status constants in `class-query.php`: 0 untranslated, 1 machine-translated, 2 reviewed by the maintainer, 3 similar, 4 loaded from a language file. This approach consumes resources on every request. Translations are tied to the wording, so changing the source loses them. Lingolane treats each translation as a separate post, a better approach for SEO and performance.

**UX ideas to adopt:**
1. **Visual translation editor.** The "Translate Site/Page" button in the admin bar (`add_shortcut_to_translation_editor`) opens a split view: a sidebar and a preview iframe (`?trp-edit-translation=preview`). Hovering over text reveals a pencil icon for editing. Strings are grouped (regular, gettext, meta, images). "View as" supports roles or logged-out visitors, previous/next string navigation is available, and multiple strings can be combined into a "translation block".
2. **Status for each translation** (machine-translated or reviewed by the maintainer).
3. **Translation memory** (`class-translation-memory.php`): suggests similar sentences using `MATCH … AGAINST`.
4. **Setup wizard** (`class-onboarding.php`): welcome → ai-api-key → languages → switcher → autotranslation → addons → finish.
5. **Floating language switcher** (`render_floater` at `wp_footer`, with configurable position, flags, names, and a preview). Works with any theme without configuration.
6. **User's preferred language** (user meta `trp_language`, "always use this language", WooCommerce emails in the recipient's language).
7. **REST response translation** based on route configuration for `wp/v2/*` (`trp_rest_api_translation_config`).
8. **Machine translation with quotas and logs** (`class-machine-translator*.php`).

## 3. Recommendations for Lingolane

Recommendation IDs (C1…C14) are referenced in `RELEASE-PLAN.md`.

### P1, Include in M3 (Compatibility with Any Website)
| ID | Recommendation | Inspired by | Rationale |
|---|---|---|---|
| C1 | Read `wpml-config.xml` from active themes/plugins: custom field copying/translation, CPTs, taxonomies, `admin-texts`, block xpath | Polylang | Thousands of plugins (Elementor, WooCommerce, ACF, Yoast…) already include this file. Lingolane's schema resembles WPML's, keeping implementation costs low |
| C2 | WPML API shim (`wpml_object_id`, `icl_object_id`, `wpml_current_language`, `wpml_element_language_details`), loaded only when WPML/Polylang is absent | Polylang | Themes/plugins already calling these functions work immediately. Pair with conflict detection (R6) |
| C3 | Rewrite query filtering: uncorrelated subquery `ID IN (SELECT element_id … WHERE language_code=%s AND element_type IN (…))`, remove `CONCAT`/`COLLATE`, add index `(language_code, element_type, element_id)`. Benchmark 10k posts before and after | Polylang (indexed JOIN) | R7. Currently, each row requires a subquery, with an `OR NOT EXISTS` branch |
| C4 | Batch-prime the cache at `the_posts`/`get_terms`; invalidate it through `last_changed` instead of a 60-second TTL | Polylang | Avoid N+1 queries on archives, hreflang, and the switcher |
| C5 | Filter `lingolane_copy_post_metas` (exclusion or allowlist) and map `_thumbnail_id` to the translated image when creating a translation | Polylang | Page builder, SEO, and ACF meta need to be controllable |
| C6 | Multilingual core `wp-sitemap.xml` sitemap | Polylang | B5 / M3 SEO |
| C7 | `lingolane/language-switcher` block (usable within the Navigation block) and an optional floating switcher at `wp_footer` | Polylang + TranslatePress | R3, block themes |
| C8 | Check textdomain loading order against the request language. If incorrect, defer `load_textdomain` as in `olt-manager` | Polylang | Theme/plugin strings may appear in the wrong language |

### P2, Include in M4 (First-Time Experience)
| ID | Recommendation | Inspired by |
|---|---|---|
| C9 | Wizard combining both approaches: language → URL format → **assign the default language to existing content** → home page per language → switcher position (with preview) → "Translate your first page" | Polylang (untranslated contents and home page steps) + TranslatePress (switcher, finish) |
| C10 | "Translate this page" button in the admin bar: a frontend sidebar lists page translations (create/edit) and **the gettext strings actually appearing on the page**, captured when editing mode is enabled, for in-place translation | TranslatePress (visual editor, adapted to the post-based model) |
| C11 | **"Outdated translation"** status when the source post is edited after its translation, displayed in list columns, the metabox, and statistics. Strings gain a `status` column (machine/human) | TranslatePress (status). Neither Polylang nor TranslatePress has an outdated status |
| C12 | Schema-based configuration registry for the settings page, configuration import/export, and later REST | Polylang (`src/Options`) |

### P3, Version 1.1 Onward
| ID | Recommendation | Inspired by |
|---|---|---|
| C13 | Sync module (taxonomies, featured images, templates, order, parents); option translation (site name, tagline, theme mods; retain translations when the source changes); domain/subdomain URL formats through strategy classes; REST `lang` | Polylang |
| C14 | Browser language detection (only at `/`, cache-safe); user's preferred language; translation memory; machine translation with machine/human status (requires the maintainer's approval for costs and privacy) | Polylang + TranslatePress |

## 4. Lingolane's Existing Advantages (Preserve These)
- Stores the source language (`source_language_code`), the foundation for C11.
- "Shared" content (shared post type, taxonomy, page slug), which Polylang does not support out of the box.
- One compact table using the same model as WPML. Makes C1, C2, and the WPML import tool (M4) easier to implement.
- Writes use `GET_LOCK` and `ON DUPLICATE KEY`, with a constraint allowing one element per language in each group.

## 5. Not Yet Verified
- Actual MySQL query plans for the current filtering approach and C3 (not yet benchmarked).
- `locale`/textdomain loading order on a real site (C8).
- The `lingolane_translations` table is not included in core WXR exports. This needs consideration when building import/export tools.
