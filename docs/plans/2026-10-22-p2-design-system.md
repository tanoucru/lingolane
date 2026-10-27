# P2 Light SaaS Design System – Implementation Plan

**Goal:** Bring every Lingolane admin screen into a consistent "light, modern SaaS" design system: color and sizing tokens, uniform-stroke SVG icons replacing dashicons, empty-state illustrations, toast notifications, and subtle effects that respect reduced-motion preferences.

**Architecture:** CSS remains a single plain file, `lingolane/assets/admin.css`, reorganized into three layers in order: tokens, components, screens. Scope all selectors to the existing `.lingolane-*` roots, plus `body.lingolane-screen` for the page background. Add a PHP class, `Lingolane_UI` (`lingolane/admin/class-lingolane-ui.php`), to generate SVG icons and illustrations. Replace every dashicon output with `Lingolane_UI::icon()`. Display flash messages as toasts, with `admin.js` handling automatic dismissal. No additional build step or JS library.

**Tech Stack:** PHP 7.4+ (WordPress ≥ 6.2), plain CSS with custom properties, vanilla JS (currently depends on `wp-a11y`), PHPUnit 9.6 + wp-phpunit, Lucide icons (ISC) copied manually into the plugin.

**Spec:** `docs/specs/2026-10-22-ux-translation-design.md` (P2 and sections 2–3).

## Global Constraints

- WP ≥ 6.2, PHP ≥ 7.4. CI runs PHP 7.4 / 8.1 / 8.4.
- GPL-2.0-or-later. Copy Lucide icons (ISC) into the plugin with their license file; **do not load them from a CDN**.
- **No external fonts or external server requests.** Use system fonts: `-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif`.
- CSS/JS must not alter other WordPress screens: scope every selector to the roots listed in Task 1.
- WCAG 2.2 AA contrast (≥ 4.5:1 for normal text), keyboard accessibility, clearly visible focus outlines, and no horizontal page overflow at a width of 375px.
- Disable all effects when `prefers-reduced-motion: reduce`.
- Write UI strings in English, wrapped in `__()`/`esc_html__()` with the `lingolane` text-domain; update `languages/lingolane.pot` and `lingolane-vi.po/.mo`.
- Plugin Check: 0 errors; phpcs (`composer lint`): 0 errors, with no new warnings beyond the baseline of 26.
- Local test command: `bash /private/tmp/lingolane-run/phpunit.sh` (dedicated DB `lingolane_tests`; see the README section "Running without Docker"). Current baseline: 52 passing tests.
- Display credit (if included in the Guide footer): "Developed by @tannguyen".

## Review Focus

1. **Misspelled or unavailable icon names:** return an empty string with `_doing_it_wrong`, without breaking the page. Test in Task 2.
2. **Mobile width (375px):** the tab bar and language table must scroll horizontally within their own containers, without causing page-level horizontal overflow. Check in Task 7 (screenshot QA step).
3. **Other WordPress screens (Posts, General Settings):** their backgrounds and fonts must remain unchanged. Test selector scoping in Task 1, and verify that `body_class` adds the class only on Lingolane pages.
4. **Error messages disappearing before users can read them:** errors and warnings must not auto-hide; only success and information messages hide after 5 seconds, pausing on hover or focus. Test markup in Task 5.
5. **Browsers with JS disabled:** toasts remain fully visible (without auto-hide); icons and illustrations are inline SVG and do not depend on JS. Test in Task 5 (content is present in the markup and does not require JS rendering).

---

### Task 1: Design Tokens, CSS Scoping, and Page Background Class

**Files:**
- Modify: `lingolane/assets/admin.css` (replace the opening token block, lines 1–25; change the opening comment from "TeachMe Multilingual" to "Lingolane")
- Modify: `lingolane/admin/class-lingolane-admin.php` (add a hook in `init()`; add `body_class()`)
- Test: `tests/phpunit/test-ui-tokens.php`

**Interfaces:**
- Produces:
  - CSS variables declared on `.lingolane-wrap, .lingolane-box, .lingolane-col, .lingolane-toasts`: `--lingolane-bg`, `--lingolane-card`, `--lingolane-line`, `--lingolane-ink`, `--lingolane-muted`, `--lingolane-accent`, `--lingolane-accent-2`, `--lingolane-accent-ink`, `--lingolane-accent-soft`, `--lingolane-ok`, `--lingolane-ok-bg`, `--lingolane-warn`, `--lingolane-warn-bg`, `--lingolane-danger`, `--lingolane-danger-bg`, `--lingolane-info`, `--lingolane-info-bg`, `--lingolane-radius` (12px), `--lingolane-radius-sm` (8px), `--lingolane-shadow-sm`, `--lingolane-shadow-md`, `--lingolane-space-1…6` (4/8/12/16/24/32px), `--lingolane-ease` (`cubic-bezier(.2,.8,.2,1)`), `--lingolane-dur` (160ms), `--lingolane-font`.
  - `Lingolane_Admin::body_class( string $classes ): string`. Append ` lingolane-screen` when `get_current_screen()->id === 'toplevel_page_lingolane'`; otherwise return `$classes` unchanged.

Color values (hex; do not use `var()` for color tokens so tests can read them): bg `#f7f8fb`, card `#ffffff`, line `#e6e8ef`, ink `#1f2433`, muted `#5b6275`, accent `#4f46e5`, accent-2 `#7c3aed`, accent-ink `#4338ca`, accent-soft `#eef0ff`, ok `#15803d`/`#ecfdf3`, warn `#b45309`/`#fffbeb`, danger `#b91c1c`/`#fef2f2`, info `#1d4ed8`/`#eff6ff`. Keep old tokens still used elsewhere (`--lingolane-soft`, `--lingolane-brand`, `--lingolane-shadow`) as aliases pointing to the new tokens until Task 4 removes them all.

- [ ] **Step 1: Write failing tests** in `tests/phpunit/test-ui-tokens.php`, class `Test_Lingolane_UI_Tokens extends Lingolane_TestCase`:
  - Helper `tokens(): array` reads `LINGOLANE_DIR . 'assets/admin.css'` and collects `--lingolane-([a-z0-9-]+):\s*(#[0-9a-fA-F]{3,6})` matches into `name => hex`.
  - Helper `contrast( string $fg, string $bg ): float` calculates the WCAG 2.x contrast ratio (sRGB relative luminance).
  - `test_text_pairs_meet_wcag_aa()`: for each pair `[ink,card] [ink,bg] [muted,card] [muted,bg] [accent-ink,accent-soft] [ok,ok-bg] [warn,warn-bg] [danger,danger-bg] [info,info-bg]`, `assertGreaterThanOrEqual( 4.5, contrast(...) )`; also check `contrast('#ffffff', tokens['accent']) >= 4.5`.
  - `test_every_selector_is_scoped()`: remove comments and `@keyframes` bodies, then extract selectors before each `{` (skip lines starting with `@`). After splitting on commas and trimming, every selector must match `/^(\.lingolane|body\.lingolane-screen|\.column-lingolane_language|\.wp-list-table \.column-lingolane_language|:root)/`. Include the offending selector in the error message.
  - `test_body_class_only_on_lingolane_screen()`: after `set_current_screen( 'toplevel_page_lingolane' )`, `body_class('a')` contains `lingolane-screen`; after `set_current_screen( 'edit-post' )`, it returns exactly `'a'`.
- [ ] **Step 2: Run** `bash /private/tmp/lingolane-run/phpunit.sh --filter Test_Lingolane_UI_Tokens`. Expected: FAIL (missing `accent-soft`…, missing `body_class`).
- [ ] **Step 3: Implement** the token block using the values above. Add `add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) )` in `init()`. Background CSS: `body.lingolane-screen #wpcontent, body.lingolane-screen #wpbody-content { background: var(--lingolane-bg) }`. Since the body is outside the token roots, use the literal color `#f7f8fb` here with a comment. Set `font-family: var(--lingolane-font)` on the roots. Add an `@media (prefers-reduced-motion: reduce)` block setting `--lingolane-dur: 0ms` and `animation: none` on the roots.
- [ ] **Step 4: Rerun** the task tests and the full suite. Expected: PASS, 55 tests in total.
- [ ] **Step 5: Commit** `feat(ui): light SaaS UI tokens, CSS scoping and page background layer`.

### Task 2: SVG Icon Set and `Lingolane_UI::icon()`

**Files:**
- Create: `lingolane/admin/class-lingolane-ui.php`
- Create: `lingolane/assets/icons.php` (returns an `array` mapping names ⇒ inner SVG elements, taken from Lucide `lucide-static@0.460.0`, viewBox 24)
- Create: `lingolane/assets/LICENSE-lucide.txt` (the full, unmodified Lucide ISC license)
- Modify: `lingolane/lingolane.php` (require `admin/class-lingolane-ui.php` inside the `is_admin()` block, before `class-lingolane-admin.php`)
- Modify: `lingolane/assets/admin.css` (`.lingolane-icon` component)
- Test: `tests/phpunit/test-ui.php`

**Interfaces:**
- Produces: `Lingolane_UI::icon( string $name, int $size = 20, string $label = '' ): string`. Returns `<svg class="lingolane-icon lingolane-icon--{name}" width="{size}" height="{size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" …>{inner}</svg>`:
  - When `$label === ''`: add `aria-hidden="true" focusable="false"`.
  - When a label is provided: add `role="img"` and an escaped `<title>`.
  - For names not in the set: return `''` and call `_doing_it_wrong( 'Lingolane_UI::icon', …, '1.0.0' )`.
  - Clamp `$size` to 12–48.
- Produces: `Lingolane_UI::icons(): array` (list of names; read `assets/icons.php` once and cache statically).
- Required names (22): `layout-dashboard, files, type, languages, settings, life-buoy, circle-check, eye, triangle-alert, upload, download, search, plus, info, users, external-link, chevron-down, chevron-right, x, sparkles, globe, loader`.

- [ ] **Step 1: Write failing tests** in `tests/phpunit/test-ui.php`, class `Test_Lingolane_UI extends Lingolane_TestCase` (require the UI file in `wpSetUpBeforeClass`, as in `test-admin.php`):
  - `test_every_required_icon_exists()`: each name in the list of 22 is present in `Lingolane_UI::icons()`.
  - `test_decorative_icon_is_hidden_from_screen_readers()`: `icon('globe')` contains `aria-hidden="true"`, `width="20"`, `class="lingolane-icon lingolane-icon--globe"`, and no `<title>`.
  - `test_labelled_icon_has_escaped_title()`: `icon('info', 16, 'A <b>')` contains `role="img"`, `<title>A &lt;b&gt;</title>`, `width="16"`.
  - `test_unknown_icon_returns_empty_string()`: `setExpectedIncorrectUsage( 'Lingolane_UI::icon' )`; `assertSame( '', icon('nope') )`.
  - `test_size_is_clamped()`: `icon('x', 500)` contains `width="48"`; `icon('x', 1)` contains `width="12"`.
- [ ] **Step 2: Run** `--filter Test_Lingolane_UI`. Expected: FAIL (the class does not exist yet).
- [ ] **Step 3: Implement** the class, icon file, and license file. CSS: `.lingolane-icon { flex: none; display: inline-block; vertical-align: -0.2em; }`. When an icon is inside a button or tab, align it using `display:inline-flex; align-items:center; gap: var(--lingolane-space-2)` on the parent component, not ad hoc margins (the cause of "misaligned icons").
- [ ] **Step 4: Rerun** the task tests and the full suite. Expected: PASS.
- [ ] **Step 5: Commit** `feat(ui): Lucide SVG icon set and Lingolane_UI::icon`.

### Task 3: Replace All Dashicons and Remove the Dashicons Dependency

**Files:**
- Modify: `lingolane/admin/class-lingolane-admin.php` (`tabs()` lines 26–35: change the 2nd element to the icon name; tab markup around line ~403; header around line ~383; 18 occurrences of `dashicons`; in `enqueue()` at line 140, remove `'dashicons'` from the dependencies)
- Modify: `lingolane/admin/class-lingolane-strings-admin.php` (4 occurrences: 307, 370, 424, 435)
- Modify: `lingolane/admin/class-lingolane-metabox.php` (1 dashicons occurrence; remove the `dashicons` dependency from `wp_enqueue_style` at line 16)
- Modify: `lingolane/admin/class-lingolane-columns.php` (1 dashicons occurrence; remove the `dashicons` dependency from `enqueue()` at line 15)
- Test: `tests/phpunit/test-ui.php` (add tests)

**Interfaces:**
- Consumes: `Lingolane_UI::icon()` (Task 2).
- Produces: `Lingolane_Admin::tabs()` returns `key => array( label, icon_name )`. Mapping: overview→`layout-dashboard`, content→`files`, strings→`type`, languages→`languages`, settings→`settings`, guide→`life-buoy`. Mapping for other occurrences: yes-alt→`circle-check`, translation→`languages`, visibility→`eye`, warning→`triangle-alert`, upload→`upload`, download→`download`, sos→`life-buoy`, search→`search`, plus→`plus`, info→`info`, groups→`users`, external→`external-link`, editor-textcolor→`type`, dashboard→`layout-dashboard`, admin-page→`files`, admin-generic→`settings`.

- [ ] **Step 1: Write failing tests** (add to `Test_Lingolane_UI`, load the additional admin files as in `test-admin.php`, and log in as an administrator):
  - `test_no_screen_uses_dashicons()`: for each `$tab` in `array_keys( Lingolane_Admin::tabs() )`, set `$_GET['tab']`, `set_current_screen('toplevel_page_lingolane')`, `ob_start(); Lingolane_Admin::render(); $html = ob_get_clean();`. Assert `assertStringNotContainsString( 'dashicons', $html, $tab )` and `assertStringContainsString( 'lingolane-icon', $html, $tab )`.
  - `test_metabox_and_column_use_svg_icons()`: render `Lingolane_Metabox::render( get_post( $page ) )` (a page with a translation) and `Lingolane_Columns::render( 'lingolane_language', $page )` through an output buffer; neither contains `dashicons`.
  - `test_styles_no_longer_depend_on_dashicons()`: call `Lingolane_Admin::enqueue( 'toplevel_page_lingolane' )`; `assertNotContains( 'dashicons', wp_styles()->registered['lingolane-admin']->deps )`.
- [ ] **Step 2: Run** `--filter Test_Lingolane_UI`. Expected: FAIL for the 3 new tests.
- [ ] **Step 3: Replace** each occurrence using the mapping. Use `echo Lingolane_UI::icon( '…' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from a fixed list` wherever an icon is printed. Find remaining occurrences with `grep -rn dashicons lingolane/` (expected: 0 results outside `.pot/.po`).
- [ ] **Step 4: Rerun** the task tests and the full suite. Expected: PASS.
- [ ] **Step 5: Commit** `refactor(ui): replace dashicons with SVG icons on every screen`.

### Task 4: Shared Components and Screen Restyling

**Files:**
- Modify: `lingolane/assets/admin.css` (rewrite the component and screen sections; remove the old token aliases from Task 1)
- Modify: `lingolane/admin/class-lingolane-admin.php` (header: logo tile `<span class="lingolane-logo">` containing `icon('globe')`; add classes for metric cards in `render_overview()`)
- Test: `tests/phpunit/test-ui.php` (add tests)

**Interfaces:**
- Consumes: tokens (Task 1), icons (Task 2–3).
- Produces: shared component classes for later use in P1/P3/P4/P5, documented in a comment at the start of each CSS block:
  - `.lingolane-logo`;
  - `.lingolane-tabs` (pill style; selected item uses an `accent-soft` background and `accent-ink` text);
  - `.lingolane-card` (card background, `line` border, `radius`, `shadow-sm`, increasing to `shadow-md` on hover if the `.is-interactive` class is present);
  - `.lingolane-kpi` (28px/700 numbers, 12px `muted` labels);
  - `.lingolane-progress` (6px bar, filled portion uses an `accent→accent-2` gradient, animates from 0 on page load with `transition: width var(--lingolane-dur)`, and remains static with reduced motion);
  - `.lingolane-chip--*` (keep the existing ok/warn/info/brand variants);
  - `.lingolane-table` (12px uppercase `muted` headers, `bg` background on row hover);
  - `.lingolane-button` (extends WP's `.button`: `radius-sm` corners, `accent` background for primary buttons, focus `outline: 2px solid var(--lingolane-accent); outline-offset: 2px`);
  - `.lingolane-field` (inputs with `radius-sm` corners and an `accent` border on focus).

  Apply to these screens: Overview, Posts & Pages (matrix table), Strings, Languages, Settings, Guide. Also apply to the "Translations" box in the editor (`.lingolane-box`) and the language column in the Pages/Posts list (`.lingolane-col`): neither has `body.lingolane-screen`, so use only the tokens on their own roots. Below 782px: stack the header vertically, and give `.lingolane-tabs` and `.lingolane-table-wrap` `overflow-x:auto` within their own containers.

- [ ] **Step 1: Write failing tests**:
  - `test_header_has_logo_tile()`: the rendered overview tab contains `class="lingolane-logo"` and `lingolane-icon--globe`.
  - `test_overview_uses_kpi_cards()`: the rendered overview with 2 languages contains `lingolane-kpi` and `lingolane-progress`.
  - `test_old_token_aliases_removed()`: `admin.css` no longer contains `--lingolane-brand` or `--lingolane-soft`.
- [ ] **Step 2: Run**. Expected: FAIL.
- [ ] **Step 3: Implement** the CSS and markup described above. Do not rename classes used by `admin.js` (`data-lingolane-*`, `.lingolane-string*`, `.lingolane-popover`).
- [ ] **Step 4: Rerun** the task tests and the full suite. Expected: PASS. Also run `composer lint` from the repository root. Expected: 0 errors.
- [ ] **Step 5: Commit** `feat(ui): shared components and restyled admin screens`.

### Task 5: Toasts Replacing WordPress Notices

**Files:**
- Modify: `lingolane/admin/class-lingolane-admin.php` (`render_notices()` around lines ~427–434)
- Modify: `lingolane/assets/admin.js` (add an independent toast block at the start of the IIFE, before the preset section)
- Modify: `lingolane/assets/admin.css` (`.lingolane-toasts`, `.lingolane-toast--{success|info|warning|error}` components, 160ms slide-in effect)
- Test: `tests/phpunit/test-ui.php` (add tests)

**Interfaces:**
- Produces: markup
  ```
  <div class="lingolane-toasts" role="region" aria-label="{Notifications}">
    <div class="lingolane-toast lingolane-toast--{type}" role="{status|alert}" data-autohide="{5000|0}">
      {icon} <p>{message}</p>
      <button type="button" class="lingolane-toast__close" aria-label="{Dismiss notification}">{icon('x')}</button>
    </div>
  </div>
  ```
  - `role="alert"` and `data-autohide="0"` for `error` and `warning`; `role="status"` and `data-autohide="5000"` for `success` and `info`.
  - Icons by type: success→`circle-check`, info→`info`, warning/error→`triangle-alert`.
  - JS: `window.lingolaneToast( message: string, type: string ): void` creates a toast with the same markup (for AJAX operations in P3–P5); auto-hides according to `data-autohide`, pauses on `mouseenter`/`focusin`, and removes the toast when the close button is clicked. Do not animate when reduced motion is enabled.

- [ ] **Step 1: Write failing tests**:
  - `test_success_notice_renders_as_autohiding_toast()`: call `Lingolane_Admin::flash( 'success', 'Saved <b>' )`, then render the overview. The HTML contains `lingolane-toast--success`, `role="status"`, `data-autohide="5000"`, `Saved &lt;b&gt;`, `lingolane-toast__close`, and no `notice notice-`.
  - `test_error_notice_stays_until_dismissed()`: with `flash('error', …)`, the output contains `role="alert"`, `data-autohide="0"`, `lingolane-icon--triangle-alert`.
- [ ] **Step 2: Run**. Expected: FAIL.
- [ ] **Step 3: Implement** PHP, CSS, and JS as described above. New strings: "Notifications", "Dismiss notification".
- [ ] **Step 4: Rerun** the task tests and the full suite. Expected: PASS.
- [ ] **Step 5: Commit** `feat(ui): toasts for notices, auto-hide on success, persist on error`.

### Task 6: Empty-State Illustrations and Welcome Block

**Files:**
- Create: `lingolane/assets/illustrations/welcome.svg`, `empty-languages.svg`, `all-done.svg` (original SVG artwork, each file ≤ 4 KB, viewBox 160×120, colors via `var(--lingolane-accent, #4f46e5)` / `var(--lingolane-accent-2, #7c3aed)` / `var(--lingolane-accent-soft, #eef0ff)`, no `<script>` or external links)
- Modify: `lingolane/admin/class-lingolane-ui.php` (add `illustration()`)
- Modify: `lingolane/admin/class-lingolane-admin.php`:
  - `render_overview()`: the `lingolane-empty` block around line ~444 uses `empty-languages`;
  - when all languages reach 100%, use `all-done`;
  - start the Guide tab with `welcome`.
- Modify: `lingolane/admin/class-lingolane-strings-admin.php:248` (use `empty-languages` for the empty state)
- Modify: `lingolane/assets/admin.css` (`.lingolane-illustration`, centered `.lingolane-empty` layout with an illustration above)
- Test: `tests/phpunit/test-ui.php` (add tests)

**Interfaces:**
- Produces: `Lingolane_UI::illustration( string $name ): string`. Valid names: `welcome`, `empty-languages`, `all-done`. Returns `<div class="lingolane-illustration" aria-hidden="true">{svg file contents}</div>`. Other names: `''` with `_doing_it_wrong`. Read files with `file_get_contents` and `// phpcs:ignore WordPress.WP.AlternativeFunctions -- local bundled asset`, as in `class-lingolane-switcher.php:16`.

- [ ] **Step 1: Write failing tests**:
  - `test_illustrations_are_safe_inline_svg()`: for each name, the output starts with `<div class="lingolane-illustration" aria-hidden="true"><svg` and contains no `<script`, `href="http`, or `xlink:href="http`; file size ≤ 4096 bytes.
  - `test_unknown_illustration_returns_empty()`: `setExpectedIncorrectUsage( 'Lingolane_UI::illustration' )`, `'' === illustration('../../wp-config')`.
  - `test_empty_states_show_illustration()`: filter `pre_option_lingolane_settings` to leave only one language; the rendered overview and strings tab both contain `lingolane-illustration`.
- [ ] **Step 2: Run**. Expected: FAIL.
- [ ] **Step 3: Implement** as described above.
- [ ] **Step 4: Rerun** the task tests and the full suite. Expected: PASS.
- [ ] **Step 5: Commit** `feat(ui): illustrations for empty, completed and guide screens`.

### Task 7: Visual QA, i18n, and Documentation

**Files:**
- Create: `bin/ui-preview.sh` (generate static HTML for each tab for viewing without logging in)
- Modify: `.gitignore` (add `dist/ui-preview/`)
- Modify: `lingolane/languages/lingolane.pot`, `lingolane-vi.po`, `lingolane-vi.mo`
- Modify: `docs/RELEASE-PLAN.md` (P2 log), `README.md` (UI description and Lucide credit)

**Interfaces:**
- Produces: `bin/ui-preview.sh`, with no arguments. The script builds and installs the plugin on the Local site `check` using `/tmp/wpc.sh`, as in `cycle.sh`. Then, for each tab, run `wp eval` to render `Lingolane_Admin::render()` (loading admin files as in the tests) into `dist/ui-preview/<tab>.html`, with `<link>` elements pointing to the core CSS at `http://check.local/wp-admin/css/{common,forms,buttons,list-tables}.min.css` and `http://check.local/wp-content/plugins/lingolane/assets/admin.css`, a `<body class="wp-admin lingolane-screen">`, and finally print the file path.

- [ ] **Step 1: Write** `bin/ui-preview.sh` and run it. Expected: 6 files, `dist/ui-preview/{overview,content,strings,languages,settings,guide}.html`.
- [ ] **Step 2: Check in a browser** (the app's browser pane, serving `dist/ui-preview` with `python3 -m http.server`) at widths of 1280px and 375px. Pass criteria:
  - icons align with text in tabs, buttons, and chips;
  - no page-level horizontal scrollbar at 375px;
  - focus outlines are clearly visible when pressing Tab;
  - toasts appear and auto-hide for success messages;
  - emulating `matchMedia('(prefers-reduced-motion: reduce)')` (DevTools/`resize_window`) produces no motion;
  - compare with mockup A (light gray background, white cards with subtle shadows, purple–indigo accents).

  Capture a `scale 0.5` screenshot of each tab for the maintainer to review.
- [ ] **Step 3: Update i18n:** `/tmp/wpc.sh i18n make-pot …` (command in the README), add vi translations for the new strings ("Notifications" → "Thông báo", "Dismiss notification" → "Đóng thông báo"), `python3 bin/make-mo.py`.
- [ ] **Step 4: Run all checks:**
  - `bash /private/tmp/lingolane-run/phpunit.sh` (PHP 8.2) and the PHP 7.4 version (`php74.sh` with `wp-tests-config-74.php`): all pass;
  - `composer lint`: 0 errors;
  - `bin/build.sh`: the zip contains `admin/class-lingolane-ui.php`, `assets/icons.php`, `assets/LICENSE-lucide.txt`, `assets/illustrations/*.svg`;
  - Plugin Check: 0 errors.
- [ ] **Step 5: Record the log** in RELEASE-PLAN (what was done, test count, screenshots checked, and anything not checked). Add the credit "Icons: Lucide (ISC)" to the README. Commit `chore(ui): i18n, UI preview and P2 log`.
