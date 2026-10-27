# Design: New UI, Location-Based Translation, Inline Translation, and AI Suggestions

Date: 2026-10-22. Decision-maker: the maintainer. Author: @tannguyen.
Scope: the `lingolane/` plugin (WP ≥ 6.2, PHP ≥ 7.4, GPL-2.0-or-later). Source baseline: `cfa5a49`.

## 1. Rationale

Lingolane already provides the basic translation functionality. When the maintainer tried it, four pain points emerged:
1. In the WordPress Pages/Posts list, originals and translations appear as separate, intermingled rows, making their relationships unclear. The "Languages" column is also at the far end of the table.
2. The admin UI looks like stock WordPress: dated, without effects, with basic colors, an unfinished appearance, misaligned icons, and few illustrations.
3. Many frontend strings have no obvious place to translate them, particularly in fixed locations such as the header, footer, menus, and areas added by themes or plugins. The "Strings" tab currently only scans text in theme code and groups it by text-domain.
4. Translating every string manually takes time. The maintainer wants AI translation suggestions, preferably free and high-quality, that **require no additional installation by users** and come with **detailed instructions**.

Success means:
- New users can immediately see the relationship between originals and translations when they open the Pages list.
- Lingolane screens look like a modern SaaS product.
- Every visible string on a page has a place to translate it, organized by its location, or can be edited directly on the page.
- One click produces an AI-generated draft translation.

## 2. Goals, Out of Scope, and Prohibited Approaches

**Goals:** the 5 parts in section 4 (P1 tree list, P2 design system, P3 location-based translation groups, P4 inline frontend translation, P5 AI suggestions).

**Out of scope (this round):**
- Inline translation of images, links, and SEO meta tags (text only).
- A proxy server holding a shared AI key for all sites. This is reserved for the paid version and requires the maintainer to approve the cost.
- Dark mode for the admin UI.
- Multisite.

**Prohibited approaches (failures even if they "work"):**
- Slowing down the visitor-facing frontend. All capture, text-marking, and editing-mode script-loading mechanisms must run only when an administrator enables that mode.
- Switching to post-render HTML translation (as TranslatePress does). A translated post remains a separate post; strings continue to use gettext/option/menu as they do now.
- Lingolane CSS/JS affecting other WordPress screens. Styles must be scoped to Lingolane screens and elements inserted by Lingolane.
- Showing machine translations to visitors before approval, unless an administrator enables "auto-approve".
- Exposing API keys: sending them to the browser, logging them, exporting them in CSV, or displaying them in full on screen.
- Using unofficial translation routes (e.g., Google's keyless `translate_a` endpoint), calling external servers without being enabled, or loading assets from a CDN/Google Fonts. These violate terms of service or WordPress.org plugin guidelines.

## 3. Constraints

- Compatible with WP 6.2 or later, PHP 7.4 or later (CI currently runs 7.4/8.1/8.4), and both classic and block themes.
- GPL-2.0+ license. Bundled libraries or icons must be GPL-compatible. Lucide (ISC) is acceptable, but its SVGs must be copied into the plugin rather than loaded from a CDN.
- Write UI strings in English, wrap them in translation functions, and update `.pot` and the `vi` translation (following the M1 convention).
- All write operations must check capabilities and nonces, as the existing `Lingolane_Admin::guard()`/`ajax_guard()` do. SQL must always use `prepare()`. Plugin Check: 0 errors; phpcs: 0 errors.
- PHPUnit tests for new PHP logic must not make real network requests (use short-circuit filters such as `lingolane_pre_install_language_pack`).
- Credit: `@tannguyen`.

## 4. Decisions Made by the Maintainer

### P1. Tree-Structured Pages/Posts List (WordPress `edit.php` Screen)
The maintainer chose to combine mockups A and B.
- Each original is a group row. Its translations appear directly below it, connected by directory-tree-style lines.
- Each group has a collapse/expand arrow. All groups are expanded by default, with an "Expand all / Collapse all" button, and the browser remembers each person's collapsed/expanded state.
- The Language column appears immediately after the Title column (the `lingolane_language` column is currently appended at the end in `admin/class-lingolane-columns.php`).
- A language without a translation appears as a muted row in the tree, with a "+ Create" button (using `Lingolane_Admin::translate_url()`). The original row has a progress label, for example "2/3 ngôn ngữ".
- Translations always stay with their original and are never split across pages. Pagination counts groups.
- When filtering by a specific language (using the existing `lingolane_lang` filter), the list returns to a flat layout.
- WordPress parent/child page relationships remain unchanged. The tree connects only originals and translations; it does not mix in parent/child relationships.
- When a search matches a translation, show the entire group and highlight the match.
- Shared posts and posts without an assigned language appear as ordinary rows, without a tree.

### P2. New Design System: "Light, Modern SaaS" Direction (Mockup A)
- Light gray background, rounded white cards with subtle shadows, and purple–indigo accents. Clear typographic hierarchy: large metrics and small gray labels.
- Apply to all Lingolane screens (Overview, Posts & Pages, Strings, Languages, Settings, Guide), the "Translations" box in the editor, the column and tree in P1, and the P4 toolbar.
- Replace dashicons with a uniform-stroke SVG icon set (Lucide), aligned to a 20px grid with consistent stroke weight.
- Small illustrations for empty states ("no second language yet", "all translated") and the first-run wizard.
- Subtle effects: hover states, animated progress bars, and save notifications that appear and dismiss automatically (toasts). Disable all effects when users enable `prefers-reduced-motion`.
- System fonts; no external font loading. WCAG 2.2 AA contrast, keyboard accessibility, and a good mobile experience.
- Define colors, spacing, corner radii, and shadows once as CSS variables shared by all screens.

### P3. Translate All Frontend Text, Grouped by Location
The "Strings" tab has a group column on the left. Each group shows its string count and % translated. Clicking a group opens the translation table (original | each language). Groups:
1. **Site information:** site name, tagline, copyright line, logo alt text.
2. **Header:** top bar text, call-to-action buttons, search field.
3. **Menu:** each item in each menu (label and custom link, if any), grouped by the theme's menu locations.
4. **Footer:** footer columns, footer widgets, copyright line.
5. **Sidebar & widgets:** widget titles and content, block widgets.
6. **Theme:** text built into theme code (the existing theme scan).
7. **Plugin:** one subgroup per plugin (e.g., Contact Form 7, WooCommerce).
8. **Categories & tags** (names, descriptions) and **Media** (alt text, captions).
9. **Other:** text whose location cannot be determined.

Text collection: add a **"Scan pages"** mode. The plugin opens the site's main pages and records every string actually displayed and its location. For block themes, determine location from the template part area (header/footer). For classic themes, use header/footer/sidebar landmarks. Keep the existing "Scan theme" button.

Each string has a **"View on page"** button that opens the frontend and highlights that exact string.

### P4. Inline Frontend Translation
- The admin bar has a **"Translate this page"** button. Clicking it opens the actual page in the selected language in translation-editing mode, with a small toolbar for switching languages and an "Exit" button.
- Hovering over translatable text shows an outline and a pencil icon. Clicking it opens an inline editor: original above, translation field below, a **Save** button, an **AI suggestion** button, and a line identifying the string's group (e.g., "Footer · Theme Astra"). After saving, the text on the page updates immediately without reloading.
- Applies to all text in the P3 groups.
- A post/page body is a separate translated post, so individual sentences cannot be edited inline. Clicking the content area shows "Edit this post's translation" and opens the editor. The **post title**, however, can be edited inline.
- Only users with translation-management capabilities can see this mode. Visitors are unaffected. Caching plugins must not cache pages in editing mode.

### P5. AI Translation Suggestions Combining Two Sources
- **Default: built-in browser translation AI** (Chrome's desktop Translator API, from 138). Runs on the administrator's machine: no key, no cost, and content never leaves the device.
- **Optional: Gemini.** Administrators paste a free API key from Google AI Studio for higher quality or when not using Chrome. Includes a "Test key" button. The key is stored on the site, restricted to administrators, always masked on screen, and used only by the WordPress server to call Gemini (the key is never sent to the browser).
- Two ways to use it:
  - an **"AI suggestion"** button beside each string (the P3 translation table and P4 inline editor);
  - a **"Draft translations for all missing text"** button per group or page. It shows progress, can be stopped midway, and does not damage saved work.
- **Draft translations of entire posts/pages** (the maintainer confirmed this for the current round). In the editor's "Translations" box, the "+ Create" row in the tree list (P1), and the "Posts & Pages" tab, add **"Create with AI"** beside "Create translation". This creates a draft translation as it does now (`Lingolane_Elements::duplicate_post`), then uses AI to translate the title, excerpt, and body.
  - Preserve the body's structure: translate only text, without changing block comments `<!-- wp:… -->`, HTML tags, links, images, or shortcodes. Also translate display text in block attributes (e.g., image `alt` text and button labels). Split content into small chunks for submission, then reassemble it in the correct positions.
  - Support the block editor and classic editor in this round. For Elementor and other page builders, still create the draft but leave the body unchanged, with a clear notice that "automatic translation is not yet supported for this page builder" *(confirmed by the maintainer; builder support is deferred to a later round)*.
  - With Chrome's AI, translation runs in the browser (the tab must remain open, with a progress bar). With Gemini, the server splits content into chunks and sends batches, respecting rate limits.
  - AI-created posts remain drafts, labeled "Machine-translated – awaiting review" in the tree list and "Translations" box until published.
- Machine translations receive the **"Machine-translated – awaiting review"** status and are not shown to visitors until approved. Include individual and bulk approval buttons, plus an "Auto-approve machine translations" option (off by default).
- **Detailed instructions** (required by the maintainer):
  - The "AI translation" card in Settings checks capabilities automatically and reports status in plain language, for example "Trình duyệt của bạn dịch được Anh → Việt, sẵn sàng", "Chrome đang tải mô hình…", "Chrome của bạn cũ hơn bản 138, hãy cập nhật", "Trình duyệt này không có AI dịch, hãy dùng Chrome hoặc thêm key Gemini".
  - Illustrated, step-by-step instructions: (a) using Chrome's built-in AI; (b) obtaining a Gemini key: open Google AI Studio → sign in → Get API key → copy → paste into Lingolane → Test.
  - Clearly state that, on Gemini's free tier, Google may use submitted content to improve its products and that rate limits apply.
  - When the "AI suggestion" button is unavailable, show the specific reason and a direct link to the instructions rather than a generic error.
  - Include the same content in `readme.txt` (the external services section, as required by WordPress.org) and the project documentation.

## 5. Proposed Implementation Order

These five parts can be separated, each with its own plan, branch, and acceptance review:
1. **P2 Design system**: shared foundation (CSS variables, icons, cards, toasts, illustrations), restyling existing screens.
2. **P1 Tree list**: uses P2.
3. **P3 Location groups + Scan pages**: required before P4 and P5 because both use the shared, grouped string store.
4. **P5 AI suggestions**: integrate with the P3 translation table first.
5. **P4 Inline translation**: uses P3 and P5.

Insert this work into `RELEASE-PLAN.md` between M2 and M3 (compatibility), so M3 and M4 test the final UI.

## 6. Decisions After Review (Finalized)

The draft was reviewed independently from a planning perspective to identify areas requiring assumptions. The decisions are as follows:

1. **Text entered directly into template parts, block widgets, and custom HTML** (not through gettext/option/menu). To retain the goal of "all text on the page", allow a narrow exception: replace text per block in `render_block`, **only** for blocks within template parts (header/footer/sidebar areas) and widget areas, and **only** for registered text segments with translations. Do not touch post content or process the entire HTML page, so this remains distinct from the TranslatePress architecture. `wp_template_part` is currently skipped during scanning (`admin/class-lingolane-admin.php:955`), so P3 must re-enable it for string scanning, not for creating translated posts. Plugin forms (e.g., Contact Form 7) are covered when the plugin outputs text through gettext or blocks. Place the remainder in "Other" with a "cannot be translated automatically" note rather than promising 100% coverage.
2. **Menus, categories, and media retain the existing model, with added shortcuts.**
   - Menus: by default, translate each item's label as a string within the same menu. If the site already has separate menus per language (named with suffixes, `class-lingolane-switcher.php:71`), those take precedence, and the Menu group clearly states "using a separate menu for this language". No data migration and no removal of the separate-menu feature.
   - Categories/tags: still use separate translated terms (`tax_*`). The "Categories & tags" group shows names and descriptions for quick editing and saves them to the translated term (creating it automatically if missing).
   - Media alt text/captions: new strings.
3. **Each string has one translation shared across all locations**, matching current gettext semantics (key: `domain + context + source`). Groups are only for browsing and progress tracking. A string appearing in multiple locations is shown in each group; editing it anywhere updates the same translation.
4. **Permissions:** add the `lingolane_translate` capability, granted to Administrator by default. Users with this capability can translate, use inline translation mode, request AI suggestions, and approve machine translations. Entering or changing the Gemini key and other settings still requires `manage_options`. This prepares for a future "Translator" role without implementing it now.
5. **Approval of translated post titles** uses the post's own status: AI-created translations remain drafts until published.
6. **Chrome's AI also supports "bulk draft translation"**, provided the admin tab stays open. Each translated string is saved immediately, so closing the tab preserves completed translations, and clicking again resumes the missing ones. The UI reminds users to "keep this tab open".

## 7. Implementation Choices Left Open

- How to order and paginate groups on `edit.php` (e.g., modifying `posts_clauses`/`the_posts` or reordering in PHP), provided the P1 behavior is preserved and performance remains acceptable with 10k posts.
- Whether to store collapsed/expanded state in `localStorage` or user meta.
- The extended string schema: groups, source (theme/plugin/option/menu/widget), pages where the string appears, machine/human translation status. How to increment `DB_VERSION` and migrate existing data (old strings go into Theme or Other).
- How to mark text in editing mode (e.g., wrapping markers at gettext/option/menu and attaching them to the DOM with JS), provided markers never leak onto visitor-facing pages or into HTML attributes, `<title>`, or JSON.
- How "Scan pages" selects pages to scan (homepage, a few recent posts, archives…) and processes them in batches.
- Which specific Gemini model to use (the latest Flash version still supported at implementation time) and how to handle rate limits.
- How to detect browser support for the Translator API (feature detection) and handle unsupported language pairs.
- Whether to retain vanilla JS or add a build step. Keep plain JS/CSS by default; add a build step only with a clear reason.
- Specific copy and illustrations (compact, original SVG artwork).
