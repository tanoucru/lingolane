# P5 AI Translation (AI Translation Suggestions Combining Chrome Translator API & Gemini) Implementation Plan

**Goal:** Implement AI translation suggestions (P5) using 2 sources: on-device translation via Chrome Translator API by default (100% free, no installation or API key required, content stays on the device) and optional Google Gemini Flash API translation (high quality, free through Google AI Studio). Support single-string translation, batch translation, and duplication of entire posts/pages into translated versions while preserving HTML/Gutenberg Blocks structure.

**Architecture:** 
- The `Lingolane_AI` backend manages API key settings securely (masks the key for display, calls Gemini through the server-side WordPress HTTP API, and never exposes the key to the frontend), with AJAX endpoints for key validation and text translation.
- The `ai-translate.js` frontend provides the `LingolaneAI.translate()` abstraction: it automatically detects the Chrome On-Device Translator API (`window.translation` or `ai.translator`). If the browser supports it, translation runs immediately on-device; otherwise, it automatically falls back to the server-side Gemini endpoint.
- Integrate an "AI Suggestion" button into the P3 string table, P4 In-Context popover, post metabox, and P1 tree list table.
- The `Lingolane_AI_Post` backend handles post translation: extracts translatable text, preserves `<!-- wp:... -->` block comments, HTML tags, links, media URLs, and shortcodes intact, and assigns draft status (`draft`) and the `_lingolane_machine_translated = 1` meta flag.

**Tech Stack:** PHP 7.4+, WordPress Core APIs (`wp_remote_post`, AJAX, Options), Chrome On-Device Translator API (`window.translation` / `window.ai.translator`), Google Gemini API (v1beta `gemini-2.5-flash`), Vanilla JS / jQuery.

**Spec:** `docs/specs/2026-10-22-ux-translation-design.md` §4 P5.

## Global Constraints

- Support WordPress 6.2 and PHP 7.4.33+ at minimum.
- Meet WordPress Coding Standards (WPCS 3.1) with 0 errors and 0 warnings.
- Security: store the Gemini API key only in options (`lingolane_ai_settings`); only users with the `manage_options` capability may view/edit it. Always mask it as `AIzaSy...xxxx` when returning it to the UI; NEVER print it in HTML or pass it to frontend JS.
- Make all Gemini calls through `wp_remote_post()` on the backend server.
- By default, assign machine translations the `_lingolane_machine_translated = 1` flag and the "Machine-translated – pending review" label, and assign posts draft status (`draft`).

## Review Focus

1. API Key Protection: Do not expose the key in HTML responses, JS data, or error logs.
2. Post Structure Preservation: AI must not distort or break the syntax of HTML tags and block comments in Gutenberg/Classic post content.
3. Network / Rate Limit Resilience: When a Gemini call fails (network outage, invalid key, quota 429), return a friendly error message instead of freezing the interface.
4. Automatic Chrome Translator API Compatibility: Support both `window.translation.createTranslator` and `window.ai.translator` syntax.
5. Module Independence: If Chrome AI is unavailable and no Gemini key has been entered, show clear, step-by-step instructions for obtaining a free key without causing errors.

---

### Task 1: Core AI Backend Engine & Gemini Client (`Lingolane_AI`)

**Files:**
- Create: `lingolane/includes/class-lingolane-ai.php`
- Modify: `lingolane/lingolane.php`
- Test: `tests/phpunit/test-ai.php`

**Interfaces:**
- Consumes: `Lingolane_Admin::can_translate()`, `current_user_can('manage_options')`, `wp_remote_post()`.
- Produces: `Lingolane_AI::init()`, `Lingolane_AI::get_settings()`, `Lingolane_AI::save_settings()`, `Lingolane_AI::mask_key()`, `Lingolane_AI::test_api_key()`, `Lingolane_AI::translate_text()`.

- [ ] **Step 1: Write the failing tests**
  - Create `tests/phpunit/test-ai.php` to test settings persistence, API key masking, permission checks, and mocked text translation.

- [ ] **Step 2: Run test to verify it fails**
  - Run: `phpunit --filter Test_Lingolane_AI` -> FAIL.

- [ ] **Step 3: Implement `Lingolane_AI` in `lingolane/includes/class-lingolane-ai.php`**
  - Save and read the `lingolane_ai_settings` options.
  - Mask the key: retain the first 6 and last 4 characters.
  - Call the Gemini API endpoint `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={key}` with a dedicated translation prompt.
  - Handle AJAX endpoints: `lingolane_ai_test_key` and `lingolane_ai_translate`.

- [ ] **Step 4: Run test to verify it passes**
  - Run: `phpunit --filter Test_Lingolane_AI` -> PASS.

- [ ] **Step 5: Commit**
  - `git add lingolane/includes/class-lingolane-ai.php tests/phpunit/test-ai.php lingolane/lingolane.php`
  - `git commit -m "feat(P5): lop backend Lingolane_AI quan ly Gemini client va thiet lap AI"`

---

### Task 2: AI Settings Screen & Diagnostic UI

**Files:**
- Modify: `lingolane/admin/class-lingolane-admin.php`
- Modify: `lingolane/admin/class-lingolane-ui.php`
- Test: `tests/phpunit/test-ai.php`

**Interfaces:**
- Consumes: `Lingolane_AI::get_settings()`, `Lingolane_UI::card()`.
- Produces: An "AI Translation" tab (`tab=ai`) in Lingolane Settings with a Chrome on-device AI status card and Gemini key settings.

- [ ] **Step 1: Write test for AI settings tab rendering**
  - Add a test in `test-ai.php` to confirm that the AI tab is displayed to admins and includes the configuration fields.

- [ ] **Step 2: Implement the "AI Translation" UI tab in `Lingolane_Admin`**
  - Card 1: "Browser AI (Chrome On-Device Translator)" with a live JS availability check.
  - Card 2: "Cloud AI (Google Gemini Flash)" with an API key input, a "Test Key" button, model selection (`gemini-2.5-flash`, `gemini-1.5-flash`), and an "Auto-approve machine translations" option.
  - Card 3: Detailed, step-by-step instructions for obtaining a free key from Google AI Studio, including notes on the free quota.

- [ ] **Step 3: Run tests and verify**
  - Run `phpunit --filter Test_Lingolane_AI` -> PASS.

- [ ] **Step 4: Commit**
  - `git add lingolane/admin/class-lingolane-admin.php`
  - `git commit -m "feat(P5): giao dien tab Dich AI va huong dan lay key mien phi"`

---

### Task 3: Dual-Engine Client-Side Translation Controller (`ai-translate.js`)

**Files:**
- Create: `lingolane/assets/ai-translate.js`
- Modify: `lingolane/admin/class-lingolane-admin.php` (enqueue script)

**Interfaces:**
- Produces: Global object `window.LingolaneAI = { isChromeAvailable, translate, batchTranslate }`.

- [ ] **Step 1: Implement `lingolane/assets/ai-translate.js`**
  - Check for `window.translation` or `window.ai.translator`.
  - Function `LingolaneAI.translate(text, sourceLang, targetLang)`:
    - If Chrome Translator is available: call `createTranslator({ sourceLanguage, targetLanguage })` -> return the result immediately.
    - If Chrome Translator is unavailable or fails: send a `lingolane_ai_translate` AJAX request to the Gemini backend server.
  - Function `LingolaneAI.batchTranslate(items, onProgress, onComplete)`:
    - Translate items sequentially in a queue, update the progress bar, and provide a Cancel button.

- [ ] **Step 2: Enqueue the script in admin and in-context mode**
  - Load `ai-translate.js` on the Lingolane admin screen and when In-Context mode `is_active()`.

- [ ] **Step 3: Test syntax and build**
  - Run `bin/build.sh` and verify packaging.

- [ ] **Step 4: Commit**
  - `git add lingolane/assets/ai-translate.js lingolane/admin/class-lingolane-admin.php`
  - `git commit -m "feat(P5): bo dieu khien frontend LingolaneAI ket hop Chrome va Gemini"`

---

### Task 4: Integration into P3 String Table & P4 In-Context Popover

**Files:**
- Modify: `lingolane/assets/string-groups.js`
- Modify: `lingolane/admin/class-lingolane-strings-admin.php`
- Modify: `lingolane/assets/incontext.js`

**Interfaces:**
- Consumes: `window.LingolaneAI.translate()`, `window.LingolaneAI.batchTranslate()`.

- [ ] **Step 1: Integrate into P3 String Groups table**
  - Add an "AI Suggestion" button next to each translation input in the Strings tab. Clicking it automatically fills in a draft translation.
  - Add a "Draft all missing translations" button (Batch AI Translation) to the string group toolbar, displaying a progress modal.

- [ ] **Step 2: Integrate into P4 In-Context Editor popover**
  - Activate the `#lingolane-ic-ai-suggest` button: on click, call `LingolaneAI.translate(sourceText, 'auto', state.targetLang)` and fill the translation textarea directly.

- [ ] **Step 3: Test and verify**
  - Run phpunit and check the frontend.

- [ ] **Step 4: Commit**
  - `git add lingolane/assets/string-groups.js lingolane/assets/incontext.js lingolane/admin/class-lingolane-strings-admin.php`
  - `git commit -m "feat(P5): tich hop nut Goi y AI vao bang chuoi P3 va in-context popover P4"`

---

### Task 5: Post & Page AI Translation ("Create with AI" & HTML/Block Preserving)

**Files:**
- Create: `lingolane/includes/class-lingolane-ai-post.php`
- Modify: `lingolane/lingolane.php`
- Modify: `lingolane/admin/class-lingolane-metabox.php`
- Modify: `lingolane/admin/class-lingolane-columns.php`
- Test: `tests/phpunit/test-ai-post.php`

**Interfaces:**
- Consumes: `Lingolane_AI::translate_text()`, `Lingolane_Elements::duplicate_post()`.
- Produces: `Lingolane_AI_Post::translate_post( $post_id, $target_lang )`, `Lingolane_AI_Post::translate_html( $html, $source_lang, $target_lang )`.

- [ ] **Step 1: Write failing tests for HTML-preserving translation and post translation**
  - Create `tests/phpunit/test-ai-post.php` to verify that HTML tags, URLs, and `<!-- wp:paragraph -->` are preserved during translation.

- [ ] **Step 2: Implement `Lingolane_AI_Post`**
  - Text extraction algorithm: traverse the DOM / use regex to protect tags and block comments, translating only text nodes.
  - Duplicate the source post into the target language as a `draft`.
  - Translate `post_title`, `post_excerpt`, and `post_content`.
  - Set the `_lingolane_machine_translated = 1` meta flag and display the "Machine-translated – pending review" label.
  - AJAX endpoint: `lingolane_ai_translate_post`.

- [ ] **Step 3: Add UI buttons in Metabox and Post List Table**
  - Add a "Create with AI" button next to the "Create Translation" button in the Metabox and tree list column.

- [ ] **Step 4: Run tests and verify**
  - Run `phpunit --filter Test_Lingolane_AI_Post` -> PASS.

- [ ] **Step 5: Commit**
  - `git add lingolane/includes/class-lingolane-ai-post.php tests/phpunit/test-ai-post.php lingolane/admin/class-lingolane-metabox.php lingolane/admin/class-lingolane-columns.php lingolane/lingolane.php`
  - `git commit -m "feat(P5): tinh nang nhan ban va dich toan bo bai viet bang AI bao toan HTML"`

---

### Task 6: Final Verification, Package Build & Local Deploy

- [ ] **Step 1: Run full PHPUnit test suite on PHP 8.2 and PHP 7.4**
- [ ] **Step 2: Run phpcs validation across all files**
- [ ] **Step 3: Build new release zip and install on `check.local`**
- [ ] **Step 4: Test live translation on `check.local`**
- [ ] **Step 5: Update the project notes**
