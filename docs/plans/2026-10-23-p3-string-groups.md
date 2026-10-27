# P3 Location Groups and Page Scanning — Implementation Plan

**Goal:** Find and translate text by its frontend location, scan key pages, and highlight the exact occurrence; clearly identify text that cannot be translated automatically.

**Architecture:** Keep the strings table and the domain + hash(context, source) key; use a separate occurrences table to record multiple locations for the same translation. Option/menu/media/gettext adapters and a narrowly scoped block exception apply translations at the source; terms continue to use `tax_*`. Scanning runs in an authenticated browser session only when explicitly enabled; no URL proxying or full-page HTML translation.

**Tech Stack:** WordPress ≥ 6.2, PHP ≥ 7.4, vanilla JS/CSS, PHPUnit + wp-phpunit, P2 tokens/icons.

**Spec:** `docs/specs/2026-10-22-ux-translation-design.md` §4 P3, §2–3 and §6.

**Status:** Updated after independent review (2026-10-23). Addressed 3 blockers (Text Node Tokenizer for PHP 7.4/WP 6.2, Identity synchronization between Locations & Strings, cumulative Occurrences limit) and 5 technical suggestions. Worktree `lingolane-p3`, branch `feat/p3-string-groups`, base `396515b`. P1 follow-ups remain separate; do not merge that branch as part of this plan.

## Global Constraints

- WP 6.2 or later, PHP 7.4 or later, both classic and block themes; multisite is out of scope.
- GPL-2.0+; credit `@tannguyen`; no CDN, external resources, or additional build steps.
- English UI, wrapped in translation functions; update `.pot` and the `vi` catalog following M1 conventions.
- Every write operation checks capabilities and a nonce. SQL with dynamic values uses `prepare()` or the `$wpdb` API with formats; DDL uses only identifiers derived from the WordPress prefix.
- Plugin Check: 0 errors; phpcs: 0 errors; do not increase the baseline of 26 warnings without an explanation and acceptance.
- PHPUnit must not make real network calls; test PHP 7.4/8.2 locally and retain the CI matrix for 7.4/8.1/8.4.
- Guests receive no scan scripts, markers, source manifests, or nonces; no DB writes on guest page views. CSS stays within the Lingolane scope.
- No full-page HTML translation, no post-content changes through render_block, and no exposure of wp_template_part as independently translatable posts.
- Use `lingolane_translate` for translation; keep settings/keys under `manage_options`. Do not create a translator role in P3.
- P4 inline editing and P5 AI are out of scope. Do not add AI providers or machine-translation statuses in this phase.
- Do not commit/push/deploy or read secrets before acceptance; commit each task after acceptance. Overwriting the test-site installation requires separate approval.

## Review Focus

1. Strings appearing in the same location but with different domains/contexts: share correctly without conflating meanings; Task 1/7.
2. Private data, forms, and malicious URLs: scanning must not collect secrets or trust client-supplied identity claims; Task 6.
3. Language-specific menus: adapters must not overwrite their translations or rewrite URLs twice; Task 3/5.
4. Nested blocks, widgets inside posts, malformed HTML, and DOM changes after scanning: avoid incorrect replacements or highlights; Task 5/6.
5. Repeated upgrades, added languages, string deletion, and stale caches: no lost translations or misleading progress; Task 1/7.

## File Map and Data Contracts

New files in `lingolane/includes/`:
- `class-lingolane-string-locations.php`: occurrences, pagination, group statistics.
- `class-lingolane-string-sources.php`: registry and option/menu/media adapters.
- `class-lingolane-string-blocks.php`: template/widget scope and registered text-node replacement.

New files in `lingolane/admin/`:
- `class-lingolane-string-scan.php`: scan sessions, page lists, manifests, and endpoints.
- `class-lingolane-string-terms.php`: translated-term listing/quick editing; no term writes to the strings table.

New assets: `assets/string-scan.js`, `assets/string-scan.css`, `assets/string-groups.js`. Reuse admin.css and the P2 UI/toast. Bootstrap in `lingolane/lingolane.php`.

**Identity:** array `{domain:string, source:string, context:string}`. Always calculate the hash on the server with `Lingolane_Strings::hash()`; do not use a row ID from one language as the shared key.

**Occurrence:** array `{group:string, subgroup:string, provider:string, object_id:int, field:string, page_path:string, locator:string, supported:bool}`. The locator only helps locate DOM elements; it does not determine permissions or write targets. page_path is an internal path without query/fragment; do not store cookies, form values, or full-page HTML.

**Groups:** `site`, `header`, `menu`, `footer`, `widgets`, `theme`, `plugin`, `terms`, `media`, `other`. The spec combines Terms and Media in item 8; the UI separates them because they use two different data models. Plugin subgroups use domains, with plugin-name labels when identifiable. Menu subgroups use locations; unassigned menus go under `unassigned`.

**Execution:** Run Tasks 1→8 sequentially because they share bootstrap/admin code. Review DB/capability decisions and security. Each task requires red/green tests + review before moving to the next task. Do not implement until the plan has passed independent review and been approved.

## Task 1: Location Store and Migration

**Files:** Create `lingolane/includes/class-lingolane-string-locations.php`, `tests/phpunit/test-string-locations.php`; Modify `lingolane/includes/class-lingolane-install.php`, `lingolane/includes/class-lingolane-strings.php`, `lingolane/lingolane.php`.

**Interfaces:** `Lingolane_String_Locations::record(array $identity,array $occurrence): bool|WP_Error`; `groups(string $language): array`; `page(string $group,string $subgroup,string $language,string $status,string $keyword,int $page): array{items:array,total:int}`; `forget(string $domain,string $hash): void`; `invalidate(): void`. The types described here are contracts; do not use PHP 8 union syntax in PHP 7.4 code.

- [ ] Write `test_identity_shared_across_locations`: the same identity in header/footer → each group has total=1, overall distinct=1; saving one translation → both groups have own=1. `test_domain_and_context_stay_distinct`: changing domain/context → a different identity. `test_upgrade_idempotent`: activating twice preserves existing translations. `test_failed_schema_keeps_old_version`: table-creation failure does not increase the DB version.
- [ ] Run PHPUnit `--filter String_Locations`; FAIL because the schema/interfaces do not exist yet. Record actual assertions; harness configuration errors do not count as red tests.
- [ ] Implement table `{$wpdb->prefix}lingolane_string_locations`, DB_VERSION `0.5.0`: id bigint PK; domain varchar(100); source_hash char(32); occurrence_hash char(64); group_key varchar(20); subgroup varchar(100); provider varchar(30); object_id bigint; field varchar(100); page_path text; locator text; supported tinyint; last_seen datetime. UNIQUE `(domain,source_hash,occurrence_hash)`, index `(group_key,subgroup)`, composite index `(group_key,domain,source_hash)` to optimize COUNT DISTINCT identity queries by group. occurrence_hash is the SHA-256 of canonical location fields, excluding timestamps. Increase the version only after confirming all three tables exist; retain the policy of preserving data on uninstall.
- [ ] Synchronize Identity between Locations and Strings: when a new string is discovered by a scan/adapter (not yet present in `lingolane_strings`), `Lingolane_Strings::save()` and the AJAX endpoint accept the Identity tuple `{domain, source, context, language_code, translation}` and execute `INSERT ... ON DUPLICATE KEY UPDATE` directly into `lingolane_strings`. Do not depend on whether the string already has a row ID.
- [ ] Backfill 200 identities/batch through authorized admin requests, with a dedicated cursor; active theme domain → Theme, all others → Other, without modifying translations. While backfill is incomplete, queries provide fallback groups for identities without occurrences. LEFT JOIN translations by language so that a new language still shows missing entries even without corresponding rows.
- [ ] Count distinct identities: total/own/inherited/missing/unsupported/percent; percent=floor(100*(own+inherited)/total), total=0 → 0. Unsupported entries remain part of total but are shown separately. Process inherited entries in batches; do not use LIMIT 5000 in a way that distorts totals. Cache only on the admin side; invalidate on save/delete/import/scan and language/theme/catalog changes. Test `test_new_language_missing_rows`, `test_empty_group_zero_percent`, `test_counts_above_5000`, `test_save_delete_invalidates_counts`.
- [ ] Run new tests + test-strings.php; PASS. Review, then commit `feat(strings): track locations without duplicating translations`.

## Task 2: Separate Translation Permissions from Settings

**Files:** Modify `lingolane/includes/class-lingolane-install.php`, `lingolane/admin/class-lingolane-admin.php`, `lingolane/admin/class-lingolane-strings-admin.php`; Create `tests/phpunit/test-translation-capabilities.php`.

**Interfaces:** `Lingolane_Admin::can_translate(): bool`; `translation_guard(string $nonce): void`. `tabs()` filters by capability; the default tab for translation-only users is Strings; existing settings guards remain unchanged.

- [ ] Test `test_translator_opens_and_saves_strings`, `test_translator_cannot_write_settings`, `test_subscriber_and_bad_nonce_rejected`, `test_upgrade_grants_cap_once`. Use a user with only `read + lingolane_translate`, rather than relying on simulated editor/admin roles.
- [ ] Run `--filter Translation_Capabilities`; FAIL.
- [ ] Grant the capability to Administrator on activation/upgrade, not on every request. The top-level menu is available to administrators or translators; rendering checks each tab, including direct URLs. String save/import/export uses the translation capability + nonce; deleting an identity requires manage_options because it affects shared sources. Scan and term endpoints use the translation capability. Settings, language installation, API keys, and other administrative handlers retain manage_options; do not expose sensitive settings through localized JS.
- [ ] Run new tests + test-admin.php; PASS for both regular forms and AJAX. Review, then commit `feat(auth): separate translation from site settings`.

## Task 3: Site, Menu, and Media Sources

**Files:** Create `lingolane/includes/class-lingolane-string-sources.php`, `tests/phpunit/test-string-sources.php`; Modify `lingolane/includes/class-lingolane-strings.php`, `lingolane/includes/class-lingolane-switcher.php`, `lingolane/lingolane.php`.

**Interfaces:** `Lingolane_String_Sources::enumerate(string $provider,int $offset,int $limit): array{items:array,next:?int}`; `resolve(string $provider,int $object_id,string $field): array|WP_Error` returns a trusted Identity; `translate(array $identity,string $fallback): string`; `menu_is_language_specific(int $menu_id,string $language): bool`. Provider allowlist: option/menu/media; gettext/block registries are added in Tasks 5/6.

- [ ] Test `test_site_title_tagline_keep_original_options`, `test_menu_labels_and_custom_urls`, `test_language_specific_menu_wins`, `test_logo_alt_and_caption`, `test_unknown_option_rejected`, `test_guest_never_registers_sources`. Reject javascript URLs; rewrite internal URL prefixes only once.
- [ ] Run `--filter String_Sources`; FAIL.
- [ ] Option filters cover only blogname/blogdescription; domain `lingolane-site`, context by field. Do not guess the theme's copyright option: use a verified adapter when available, otherwise block/gettext or Other. Media domain `lingolane-media`, context alt/caption; do not modify the original attachment. Logo alt shares the identity of media alt, with an additional Site occurrence. Objects with the same source/context share translations as specified.
- [ ] Menu domain `lingolane-menu`, context label/custom-url. Replace custom URLs only for custom-type items; the router handles post/tax URLs. When rewriting a custom URL into the target language: strip the current language prefix with `Lingolane_URLs::strip_slug()` before prepending the new language slug to prevent duplicate prefixes (for example, `/fr/en/about`). Language-specific menus take precedence even when invoked directly, not only through locations. Read original sources from DB/meta, not from already-filtered objects. Internal lookups must not be accidentally blocked by gettext allowed_domains; return originals for the source language/admin. No DB writes in frontend filters.
- [ ] Register sources in batches through admin scans; a source change creates a new identity without automatically transferring the old translation. Escape for the output sink; URLs may use only http(s), root-relative paths, or safe fragments. Captions follow WP's HTML policy. Run new tests + existing strings/URLs tests; PASS. Review, then commit `feat(strings): translate site menu and media sources`.

## Task 4: Quick Editing of Term Translations

**Files:** Create `lingolane/admin/class-lingolane-string-terms.php`, `tests/phpunit/test-string-terms.php`; Modify `lingolane/lingolane.php`.

**Interfaces:** `Lingolane_String_Terms::page(string $taxonomy,string $language,string $keyword,int $page): array{items:array,total:int}`; `save(int $source_id,string $taxonomy,string $language,string $name,string $description): int|WP_Error`; `progress(string $language): array`. AJAX `lingolane_save_term_translation`, nonce `lingolane_terms_ajax`, translation capability.

- [ ] Test `test_create_and_reuse_translation_term`, `test_taxonomy_enabled_and_public_only`, `test_source_language_immutable`, `test_failed_insert_has_no_mapping`, `test_duplicate_save_creates_one_term`. Originals remain unchanged, tax/trid mappings are correct, and the strings table contains no synthetic term entries.
- [ ] Run `--filter String_Terms`; FAIL.
- [ ] Use Elements::get_translation/get_trid/set and the term insert/update API. Verify taxonomy/ID/language/relation. Create on save, not on listing. Acquire a short-lived lock by source/tax/language during creation, recheck the mapping after acquiring the lock, and release it even on error; return a retry error on contention. Do not report success when mapping fails; roll back only the term created by the current request, never delete an existing term. Use the translated parent when available; otherwise leave it at 0 and warn. When a parent term is translated, automatically update `parent` for already-translated child terms in the same language if those children have `parent = 0`.
- [ ] Track progress by field: a nonempty source name is one unit; a nonempty source description is one unit; completion requires a value in the counterpart. Run new tests + test-elements.php; PASS. Review, then commit `feat(strings): edit taxonomy translations in grouped browser`.

## Task 5: Scoped Block Exception for Templates/Widgets

**Files:** Create `lingolane/includes/class-lingolane-string-blocks.php`, `tests/phpunit/test-string-blocks.php`; Modify `lingolane/lingolane.php`.

**Interfaces:** `Lingolane_String_Blocks::extract(string $html): array`; `replace(string $html,array $translations): string`; `render(string $content,array $block): string`; `widget(array $instance,array $widget): array`. The registry exposes Identity and source descriptors for Task 6.

- [ ] Test `test_header_footer_template_parts`, `test_nested_blocks_translate_once`, `test_posts_and_navigation_untouched`, `test_widget_block_only_in_widget_area`, `test_unsafe_nodes_and_malformed_html_unchanged`. Do not replace string A within ABC; href/JSON/input/value remain unchanged; parse errors return the original and a scan diagnostic.
- [ ] Run `--filter String_Blocks`; FAIL.
- [ ] Apply only to root core/template-part output with verified header/footer/sidebar areas and block widgets in actual widget areas. Do not expand permissions based on CSS classes or child-block names. Replace nested template parts only once; exclude the core/navigation subtree to avoid overriding separate menus. Handle classic widget title/text through an instance adapter. The scanner reads template parts through a dedicated API; retain skip_types in the post list.
- [ ] Parser solution compatible with PHP 7.4 / WP 6.2 (no dependency on WP_HTML_Text_Processor, available only in WP 6.6+, and no DOMDocument, which can distort Unicode entities): build a pure-PHP state-machine tokenizer in `Lingolane_String_Blocks`. The tokenizer streams through characters, separates HTML tags (`<...>` including attributes, comments, CDATA), and collects text nodes between `>` and `<` with exact byte offsets `(start, length)`. Skip all content within special tags (`script`, `style`, `textarea`, `svg`, `code`, `pre`, `noscript`, `template`). When replacing translations: compare text nodes after safe entity decoding; if they match a translated Identity exactly, 100%, escape the translation for the HTML text context and perform substring replacements from the end of the string toward the beginning (reverse offset order) to preserve the offsets of preceding nodes. Do not reserialize the DOM or use full-page regex.
- [ ] Test `test_guest_has_no_capture_queries_or_markers`: use cached lookups, no per-node queries/writes; untranslated content remains unchanged. Run new tests + URL block rewrite tests; PASS. Review, then commit `feat(strings): translate registered text in supported regions`.

## Task 6: Page Scanning and Highlighting with Authenticated Sessions

**Files:** Create `lingolane/admin/class-lingolane-string-scan.php`, `lingolane/assets/string-scan.js`, `lingolane/assets/string-scan.css`, `tests/phpunit/test-string-scan.php`; Modify `lingolane/lingolane.php` and the registries from Tasks 3/5.

**Interfaces:** `Lingolane_String_Scan::pages(): array`; `start(array $paths): array{session:string,pages:array,expires:int}`; `accept(string $session,string $path,array $items): array{accepted:int,rejected:int}`; `preview_url(int $occurrence_id): string|WP_Error`. AJAX scan_start/scan_accept/scan_preview use the lingolane_ prefix, nonce `lingolane_scan_ajax`, and the translation capability.

- [ ] Test `test_nonce_capability_and_user_bound_session`, `test_external_admin_private_paths_rejected`, `test_forged_supported_identity_rejected`, `test_payload_limits_and_expiry`, `test_guest_has_no_manifest`. Expired session/different user → 403; external URL → 400; more than 200 items or 128KB/request → explicit error, no silent partial writes.
- [ ] Run `--filter String_Scan`; FAIL.
- [ ] pages() returns the homepage, posts page, 3 most recent public posts, 3 most recent public pages, and 2 term archives containing posts; deduplicate, maximum 10. Users select pages and see the scope before starting; no unbounded crawling. Exclude admin/private/draft/search/cart/account pages and URLs with queries. Sessions last 15 minutes, with random user-bound tokens created only through valid POST requests. Canonical same-origin paths, no external redirects; no server-side HTTP fetching.
- [ ] DB protection and scan-session limits: each 15-minute scan session has a cumulative limit of **500 occurrences**, a maximum of 5 `accept` requests per session, and a rate limit requiring at least 1 second between requests. Reject requests exceeding the limit with error code `payload_too_large` instead of silently discarding data.
- [ ] Open tabs through user-initiated clicks (Ruling: use tabs rather than background iframes because `X-Frame-Options` headers and CSP `frame-ancestors` on secured sites block iframe loading; this also avoids browser throttling of DOM/JS). Process each source-language page; load the script only after session verification. Do not capture admin bar/form/input/textarea/password/script/style/hidden/account regions. DOM text without a trusted source becomes only an unsupported candidate of at most 2000 characters, never a replacement rule; label it `Cannot be translated automatically`. Supported identities must match the server manifest from adapters/gettext/blocks and the original source; do not trust client provider/domain/ID. Capture gettext only during scan requests; do not wrap gettext in HTML markers.
- [ ] Identify regions by template area or semantic header/footer/aside; do not treat an article header as the site header. Store selector + exact text + node order + nearby context. Provide stop/retry and progress; do not delete old occurrences during incomplete/failed scans. After successfully scanning a page (`page_path`), update `last_seen`; also remove occurrences for that same `page_path` whose `last_seen` predates the current scan (garbage collection) to prevent stale records accumulating after layout changes. postMessage checks origin/source/session, with no wildcard. If a popup is blocked, show a link to open it manually.
- [ ] The preview server constructs a same-origin URL from the occurrence, with a user-bound token and cache-control private/no-store. Do not store the token in page_path. JS highlights only when the locator/exact source or translation matches uniquely; missing/ambiguous matches display `Text no longer found. Scan this page again.` Do not silently select the first match. The overlay does not modify text and is removed when the mode is disabled; guests must not receive the manifest.
- [ ] Run new tests + `node --check lingolane/assets/string-scan.js`. Classic/block browser fixtures: select 2 pages, stop/retry, duplicate text, changed DOM, blocked popup, and guest access. PASS with evidence. Review, then commit `feat(strings): add authenticated scan and occurrence preview`.

## Task 7: Grouped Strings Tab and Multilingual Table

**Files:** Modify `lingolane/admin/class-lingolane-strings-admin.php`, `lingolane/admin/class-lingolane-stats.php`, `lingolane/admin/class-lingolane-admin.php`, `lingolane/assets/admin.css`, `lingolane/assets/admin.js`; Create `lingolane/assets/string-groups.js`, `tests/phpunit/test-string-groups-ui.php`.

**Interfaces:** Consumes Locations::groups/page, Terms::page/progress/save, Scan::pages/preview_url, Sources::menu_is_language_specific. Retain render_tab_content() and the existing save endpoint; save accepts the Identity tuple `{domain, source, context, language_code, translation}`, without depending on an existing `row_id` in the strings table; return results that allow invalidating the relevant group caches.

- [ ] Test `test_groups_distinct_counts`, `test_source_and_all_target_columns`, `test_shared_identity_updates_all_groups`, `test_filters_pagination_and_no_js_save`, `test_terms_use_term_endpoint`, `test_theme_scan_keeps_page_sources`. Fixture of 31 identities → pagination at 30, without multiplying rows by language/occurrence.
- [ ] Run `--filter String_Groups_UI`; FAIL.
- [ ] Sidebar with 10 groups as contracted, Plugin submenus by domain and Menu submenus by location. Counts/% follow the target language selected for filtering; the table still shows the source and every target language. Retain own/inherited/missing and explain inherited. Empty groups still show 0 and a Scan CTA. Terms use name/description and separate progress; disable saving for unsupported entries and provide a reason.
- [ ] Retain keyword/domain/status/pagination/CSV/Scan theme; GET must not scan/write to the DB. The theme scanner deletes only untranslated identities it owns that have no occurrences from other providers. Separate menus show `Using a separate menu for this language` with an edit-menu link. View on page allows occurrence selection; if not yet observed on a page, disable it with a reason rather than providing a fabricated URL.
- [ ] JS saving preserves focus and shows errors within the cell; switching groups with unsaved drafts requires inline confirmation. No-JS mode retains a POST form. Sidebar keyboard support/aria-current, table scope, aria-live toasts, reduced-motion, and independently scrolling tables on mobile. The dashboard shows overall distinct identities, without adding group totals or mixing terms into the existing string total.
- [ ] Run new tests + UI/stats/strings; node --check admin.js/string-groups.js. Browser tests at 1440/390px, keyboard, 3 languages, a translation-only user, and JS disabled. PASS with evidence. Review, then commit `feat(strings): browse translations by frontend location`.

## Task 8: i18n, Regression Testing, and Acceptance Package

**Files:** Modify `lingolane/languages/lingolane.pot`, `lingolane/languages/lingolane-vi.po`, `lingolane/languages/lingolane-vi.mo`, `lingolane/readme.txt`, `RELEASE-PLAN.md`; Create `docs/reports/2026-10-23-p3-acceptance.md`.

**Interfaces:** No new APIs. The report maps spec requirements → tests/commands/evidence; mark missing environments as NOT RUN.

- [ ] Add catalog tests using existing helpers: new labels have msgid/msgstr vi entries, with placeholders intact; list P3 write paths and their corresponding capability/nonce tests. Run and observe FAIL before updating the catalogs.
- [ ] Update pot/po/mo using repo tools. The readme explains translation permissions, scanning, Other limitations, separate menus, and block/widget scope; do not promise 100% coverage. RELEASE-PLAN records only browser interactions actually tested; do not modify P1 evidence awaiting QA.
- [ ] Run the full PHPUnit suite on PHP 7.4/8.2, phpcs, JS syntax checks, and build. The harness must point to P3, not use scripts hard-coded to P1fix. Record the actual baseline; do not use the 137 tests from the P1fix branch as the P3 baseline.
- [ ] After separate approval for the test site: back up/install the build, Plugin Check 0 errors; QA classic/block, WP 6.2 and the existing local WP environment. Guests receive no scripts/markers/write queries; scan responses stay out of public caches; saving/viewing works correctly for each adapter; incomplete scans do not lose translations. Mark missing environments as NOT RUN and leave the release gate open.
- [ ] Independently review the full diff, fix blocking findings, and retest. Cross-check the spec/evidence and document limitations. Commit `docs(strings): document and verify P3 workflows` only when the content accurately reflects the work performed.
- [ ] Wait for merge/deploy approval under repo permissions; update system-map when status/branches change. Do not delete the worktree without approval. Roll back code with a revert commit after approval; keep the additive table, do not drop it for rollback.

## Draft Self-Review

- Coverage: 10 groups → Tasks 1,3–7; Scan/View → 6; shared identity → 1,7; capability → 2; block exception → 5; i18n/QA → 8.
- Interfaces are used consistently; new functions are proposals, not assumed to exist yet.
- The Task 5 technical gate has a finalized solution: a pure-PHP state-machine tokenizer identifies tag boundaries, records exact byte offsets, and reverses replacement order to preserve offsets and support PHP 7.4 / WP 6.2.
- Decisions made (draft): separate occurrences preserve one translation across multiple locations; 10 UI items because terms/media have different models; maximum 10 pages/run and 500 occurrences/session to prevent DB growth. Use tabs rather than iframes to avoid CSP/X-Frame-Options blocking. Revert by editing the draft before coding; no migrations or site changes have been made.
- Passed independent review (2026-10-23, exit 0, 8 findings). The plan has been finalized for implementation (Draft Ready for Execution). Awaiting approval from the maintainer to begin Task 1.

