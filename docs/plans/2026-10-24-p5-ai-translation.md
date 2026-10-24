# P5 AI Translation (Gợi ý dịch AI kết hợp Chrome Translator API & Gemini) Implementation Plan

**Goal:** Triển khai tính năng gợi ý dịch bằng AI (P5) kết hợp 2 nguồn: mặc định trên thiết bị qua Chrome Translator API (miễn phí 100%, không cần cài đặt, không cần API key, nội dung không rời máy) và tuỳ chọn qua Google Gemini Flash API (chất lượng cao, miễn phí từ Google AI Studio), hỗ trợ dịch chuỗi đơn lẻ, dịch theo lô và nhân bản dịch trọn vẹn bài viết/trang bảo toàn cấu trúc HTML/Gutenberg Blocks.

**Architecture:** 
- Backend `Lingolane_AI` quản lý cấu hình API key an toàn (che key khi hiển thị, gọi Gemini qua WordPress HTTP API máy chủ, không lộ key ra frontend), endpoint AJAX kiểm tra key và dịch văn bản.
- Frontend `ai-translate.js` tạo lớp trừu tượng `LingolaneAI.translate()`: tự động phát hiện Chrome On-Device Translator API (`window.translation` hoặc `ai.translator`). Nếu trình duyệt hỗ trợ, dịch tức thì trên máy; nếu không, tự động chuyển tiếp sang server-side Gemini endpoint.
- Tích hợp nút "Gợi ý AI" vào bảng chuỗi P3, popover In-Context P4, metabox bài viết và bảng danh sách cây P1.
- Backend `Lingolane_AI_Post` xử lý dịch bài viết: bóc tách văn bản cần dịch, bảo toàn nguyên vẹn `<!-- wp:... -->` block comments, HTML tags, links, media URLs, shortcodes, gán trạng thái nháp (`draft`) và meta `_lingolane_machine_translated = 1`.

**Tech Stack:** PHP 7.4+, WordPress Core APIs (`wp_remote_post`, AJAX, Options), Chrome On-Device Translator API (`window.translation` / `window.ai.translator`), Google Gemini API (v1beta `gemini-2.5-flash`), Vanilla JS / jQuery.

**Spec:** `docs/specs/2026-10-22-ux-translation-design.md` §4 P5.

## Global Constraints

- Hỗ trợ tối thiểu WordPress 6.2 và PHP 7.4.33+.
- Chuẩn lập trình WordPress Coding Standards (WPCS 3.1) sạch 0 lỗi, 0 cảnh báo.
- Bảo mật: API key Gemini chỉ lưu trong options (`lingolane_ai_settings`), chỉ người dùng có quyền `manage_options` mới xem/sửa, luôn che dạng `AIzaSy...xxxx` khi trả về UI, tuyệt đối KHÔNG in ra HTML hay truyền xuống frontend JS.
- Mọi thao tác gọi Gemini qua `wp_remote_post()` thực hiện từ server backend.
- Bản dịch máy mặc định gán cờ `_lingolane_machine_translated = 1` và nhãn "Máy dịch – chờ duyệt", bài viết gán trạng thái nháp (`draft`).

## Review Focus

1. Bảo vệ API Key: Không lộ key trong response HTML, JS data hay log lỗi.
2. Bảo toàn cấu trúc bài viết: Thân bài Gutenberg/Classic chứa thẻ HTML và block comments không bị AI làm biến dạng hoặc hỏng cú pháp.
3. Chịu lỗi mạng / Rate Limit: Khi gọi Gemini gặp lỗi (mất mạng, key sai, quota 429), trả thông báo lỗi thân thiện thay vì làm đơ giao diện.
4. Tự động tương thích Chrome Translator API: Hỗ trợ cả hai chuẩn cú pháp `window.translation.createTranslator` và `window.ai.translator`.
5. Độc lập module: Nếu không có Chrome AI và chưa nhập Gemini key, hệ thống hiển thị hướng dẫn rõ ràng từng bước lấy key miễn phí mà không gây lỗi.

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
  - Tạo `tests/phpunit/test-ai.php` kiểm tra lưu cấu hình, che API key, kiểm tra phân quyền và hàm dịch văn bản mock.

- [ ] **Step 2: Run test to verify it fails**
  - Chạy: `phpunit --filter Test_Lingolane_AI` -> FAIL.

- [ ] **Step 3: Implement `Lingolane_AI` in `lingolane/includes/class-lingolane-ai.php`**
  - Lưu và đọc options `lingolane_ai_settings`.
  - Che key: giữ lại 6 ký tự đầu và 4 ký tự cuối.
  - Gọi Gemini API endpoint `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={key}` với prompt dịch chuyên dụng.
  - Xử lý AJAX endpoints: `lingolane_ai_test_key` và `lingolane_ai_translate`.

- [ ] **Step 4: Run test to verify it passes**
  - Chạy: `phpunit --filter Test_Lingolane_AI` -> PASS.

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
- Produces: Tab "Dịch AI" (`tab=ai`) trong Lingolane Settings với thẻ trạng thái Chrome on-device AI và thiết lập Gemini key.

- [ ] **Step 1: Write test for AI settings tab rendering**
  - Thêm test trong `test-ai.php` xác nhận tab AI hiển thị cho admin và có các trường cấu hình.

- [ ] **Step 2: Implement UI tab "Dịch AI" trong `Lingolane_Admin`**
  - Thẻ 1: "AI có sẵn trong trình duyệt (Chrome On-Device Translator)" với trình kiểm tra trực tiếp qua JS.
  - Thẻ 2: "AI đám mây (Google Gemini Flash)" với ô nhập API key, nút "Kiểm tra key", lựa chọn model (`gemini-2.5-flash`, `gemini-1.5-flash`), tuỳ chọn "Tự duyệt bản dịch máy".
  - Thẻ 3: Hướng dẫn chi tiết từng bước lấy key miễn phí từ Google AI Studio kèm lưu ý quota miễn phí.

- [ ] **Step 3: Run tests and verify**
  - Chạy `phpunit --filter Test_Lingolane_AI` -> PASS.

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
  - Kiểm tra `window.translation` hoặc `window.ai.translator`.
  - Hàm `LingolaneAI.translate(text, sourceLang, targetLang)`:
    - Nếu Chrome Translator khả dụng: gọi `createTranslator({ sourceLanguage, targetLanguage })` -> trả kết quả ngay lập tức.
    - Nếu Chrome Translator không khả dụng hoặc lỗi: gửi AJAX `lingolane_ai_translate` đến backend server Gemini.
  - Hàm `LingolaneAI.batchTranslate(items, onProgress, onComplete)`:
    - Chạy dịch theo hàng đợi tuần tự, cập nhật thanh tiến độ và có nút Huỷ.

- [ ] **Step 2: Enqueue script trong admin và in-context mode**
  - Nạp `ai-translate.js` trong màn quản trị Lingolane và khi ở chế độ `is_active()` của In-Context.

- [ ] **Step 3: Test syntax and build**
  - Chạy `bin/build.sh` và kiểm tra đóng gói.

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
  - Thêm nút "Gợi ý AI" cạnh mỗi ô nhập bản dịch trong tab Chuỗi. Bấm vào tự động điền bản dịch nháp.
  - Thêm nút "Dịch nháp tất cả còn thiếu" (Batch AI Translation) ở thanh công cụ nhóm chuỗi, hiển thị progress modal.

- [ ] **Step 2: Integrate into P4 In-Context Editor popover**
  - Kích hoạt nút `#lingolane-ic-ai-suggest`: khi bấm, gọi `LingolaneAI.translate(sourceText, 'auto', state.targetLang)` và điền trực tiếp vào ô textarea bản dịch.

- [ ] **Step 3: Test and verify**
  - Chạy phpunit và kiểm tra frontend.

- [ ] **Step 4: Commit**
  - `git add lingolane/assets/string-groups.js lingolane/assets/incontext.js lingolane/admin/class-lingolane-strings-admin.php`
  - `git commit -m "feat(P5): tich hop nut Goi y AI vao bang chuoi P3 va in-context popover P4"`

---

### Task 5: Post & Page AI Translation ("Tạo bằng AI" & HTML/Block Preserving)

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
  - Tạo `tests/phpunit/test-ai-post.php` kiểm tra việc giữ nguyên thẻ HTML, URL, `<!-- wp:paragraph -->` khi dịch.

- [ ] **Step 2: Implement `Lingolane_AI_Post`**
  - Thuật toán bóc tách văn bản: duyệt DOM / regex bảo vệ thẻ tags, block comments và chỉ dịch text nodes.
  - Nhân bản bài viết gốc sang ngôn ngữ đích dạng `draft`.
  - Dịch `post_title`, `post_excerpt`, và `post_content`.
  - Gán meta `_lingolane_machine_translated = 1` và hiển thị nhãn "Máy dịch – chờ duyệt".
  - Endpoint AJAX: `lingolane_ai_translate_post`.

- [ ] **Step 3: Add UI buttons in Metabox and Post List Table**
  - Thêm nút "Tạo bằng AI" cạnh nút "Tạo bản dịch" trong Metabox và cột danh sách cây.

- [ ] **Step 4: Run tests and verify**
  - Chạy `phpunit --filter Test_Lingolane_AI_Post` -> PASS.

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
