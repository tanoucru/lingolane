# P2 Hệ giao diện SaaS sáng – Kế hoạch triển khai

**Goal:** Đưa mọi màn hình quản trị của Lingolane về một hệ giao diện "SaaS sáng, hiện đại" thống nhất: token màu và kích thước, icon SVG nét đều thay dashicons, minh hoạ cho màn hình trống, toast cho thông báo, hiệu ứng nhẹ có tôn trọng giảm chuyển động.

**Architecture:** CSS vẫn là một file thuần `lingolane/assets/admin.css`, sắp lại thành ba lớp theo thứ tự: token, thành phần, màn hình. Mọi selector gói trong các gốc `.lingolane-*` sẵn có, cộng `body.lingolane-screen` cho nền trang. Thêm lớp PHP `Lingolane_UI` (`lingolane/admin/class-lingolane-ui.php`) sinh SVG icon và minh hoạ. Mọi chỗ đang in dashicons chuyển sang `Lingolane_UI::icon()`. Thông báo flash hiện thành toast, có `admin.js` lo việc tự ẩn. Không thêm bước build, không thêm thư viện JS.

**Tech Stack:** PHP 7.4+ (WordPress ≥ 6.2), CSS thuần với custom properties, JS thuần (đang phụ thuộc `wp-a11y`), PHPUnit 9.6 + wp-phpunit, icon Lucide (ISC) chép tay vào plugin.

**Spec:** `docs/specs/2026-10-22-ux-translation-design.md` (mục P2 và mục 2–3).

## Global Constraints

- WP ≥ 6.2, PHP ≥ 7.4. CI chạy PHP 7.4 / 8.1 / 8.4.
- GPL-2.0-or-later. Icon Lucide (ISC) chép vào plugin kèm file giấy phép, **không tải qua CDN**.
- **Không tải font ngoài, không gọi máy chủ ngoài.** Dùng font hệ thống: `-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif`.
- CSS/JS không được làm lệch các màn hình WordPress khác: mọi selector nằm trong các gốc đã liệt kê ở Task 1.
- Tương phản WCAG 2.2 AA (≥ 4.5:1 cho chữ thường), dùng được bằng bàn phím, có viền focus thấy rõ, không tràn ngang ở bề rộng 375px.
- Mọi hiệu ứng tắt khi `prefers-reduced-motion: reduce`.
- Chuỗi giao diện viết bằng tiếng Anh, bọc `__()`/`esc_html__()` với text-domain `lingolane`; cập nhật `languages/lingolane.pot` và `lingolane-vi.po/.mo`.
- Plugin Check 0 lỗi; phpcs (`composer lint`) 0 lỗi, không thêm cảnh báo mới ngoài mốc 26.
- Lệnh chạy test cục bộ: `bash /private/tmp/lingolane-run/phpunit.sh` (DB riêng `lingolane_tests`, xem README mục "Chạy không cần Docker"). Mốc hiện tại: 52 test đạt.
- Credit hiển thị (nếu có ở chân trang Hướng dẫn): "Developed by @tannguyen".

## Review Focus

1. **Tên icon gõ sai hoặc không có trong bộ:** phải trả chuỗi rỗng kèm `_doing_it_wrong`, không làm vỡ trang. Test ở Task 2.
2. **Bề rộng điện thoại (375px):** thanh tab và bảng ngôn ngữ phải cuộn ngang trong khung của chúng, không đẩy cả trang tràn ngang. Kiểm ở Task 7 (bước QA ảnh chụp).
3. **Màn hình WordPress khác (Bài viết, Cài đặt chung):** không bị đổi nền hay font. Test phạm vi selector ở Task 1, cộng kiểm `body_class` chỉ thêm lớp trên trang Lingolane.
4. **Thông báo lỗi bị tự ẩn trước khi người dùng kịp đọc:** lỗi và cảnh báo không tự ẩn, chỉ thành công và thông tin mới ẩn sau 5 giây; rê chuột hoặc focus vào thì tạm dừng. Test markup ở Task 5.
5. **Trình duyệt tắt JS:** toast vẫn hiện đầy đủ (chỉ không tự ẩn); icon và minh hoạ là SVG nội tuyến nên không phụ thuộc JS. Test ở Task 5 (markup có sẵn nội dung, không cần JS dựng).

---

### Task 1: Token giao diện, phạm vi CSS và lớp nền trang

**Files:**
- Modify: `lingolane/assets/admin.css` (thay khối token đầu file, dòng 1–25; sửa comment đầu file từ "TeachMe Multilingual" thành "Lingolane")
- Modify: `lingolane/admin/class-lingolane-admin.php` (`init()` thêm hook; thêm `body_class()`)
- Test: `tests/phpunit/test-ui-tokens.php`

**Interfaces:**
- Produces:
  - Các biến CSS khai báo trên `.lingolane-wrap, .lingolane-box, .lingolane-col, .lingolane-toasts`: `--lingolane-bg`, `--lingolane-card`, `--lingolane-line`, `--lingolane-ink`, `--lingolane-muted`, `--lingolane-accent`, `--lingolane-accent-2`, `--lingolane-accent-ink`, `--lingolane-accent-soft`, `--lingolane-ok`, `--lingolane-ok-bg`, `--lingolane-warn`, `--lingolane-warn-bg`, `--lingolane-danger`, `--lingolane-danger-bg`, `--lingolane-info`, `--lingolane-info-bg`, `--lingolane-radius` (12px), `--lingolane-radius-sm` (8px), `--lingolane-shadow-sm`, `--lingolane-shadow-md`, `--lingolane-space-1…6` (4/8/12/16/24/32px), `--lingolane-ease` (`cubic-bezier(.2,.8,.2,1)`), `--lingolane-dur` (160ms), `--lingolane-font`.
  - `Lingolane_Admin::body_class( string $classes ): string`. Thêm ` lingolane-screen` khi `get_current_screen()->id === 'toplevel_page_lingolane'`, ngược lại trả nguyên `$classes`.

Giá trị màu (hex, không dùng `var()` cho các token màu để test đọc được): bg `#f7f8fb`, card `#ffffff`, line `#e6e8ef`, ink `#1f2433`, muted `#5b6275`, accent `#4f46e5`, accent-2 `#7c3aed`, accent-ink `#4338ca`, accent-soft `#eef0ff`, ok `#15803d`/`#ecfdf3`, warn `#b45309`/`#fffbeb`, danger `#b91c1c`/`#fef2f2`, info `#1d4ed8`/`#eff6ff`. Token cũ đang dùng ở chỗ khác (`--lingolane-soft`, `--lingolane-brand`, `--lingolane-shadow`) giữ lại làm bí danh trỏ sang token mới cho tới khi Task 4 gỡ hết.

- [ ] **Step 1: Viết test hỏng** `tests/phpunit/test-ui-tokens.php`, lớp `Test_Lingolane_UI_Tokens extends Lingolane_TestCase`:
  - Helper `tokens(): array` đọc `LINGOLANE_DIR . 'assets/admin.css'`, gom `--lingolane-([a-z0-9-]+):\s*(#[0-9a-fA-F]{3,6})` thành `name => hex`.
  - Helper `contrast( string $fg, string $bg ): float` tính tỉ lệ tương phản theo WCAG 2.x (độ sáng tương đối sRGB).
  - `test_text_pairs_meet_wcag_aa()`: với mỗi cặp `[ink,card] [ink,bg] [muted,card] [muted,bg] [accent-ink,accent-soft] [ok,ok-bg] [warn,warn-bg] [danger,danger-bg] [info,info-bg]`, `assertGreaterThanOrEqual( 4.5, contrast(...) )`; và `contrast('#ffffff', tokens['accent']) >= 4.5`.
  - `test_every_selector_is_scoped()`: bỏ comment, bỏ phần thân `@keyframes`, tách selector trước mỗi `{` (bỏ dòng bắt đầu bằng `@`). Mỗi selector sau khi tách dấu phẩy và trim phải khớp `/^(\.lingolane|body\.lingolane-screen|\.column-lingolane_language|\.wp-list-table \.column-lingolane_language|:root)/`. Thông báo lỗi in ra selector vi phạm.
  - `test_body_class_only_on_lingolane_screen()`: `set_current_screen( 'toplevel_page_lingolane' )` thì `body_class('a')` chứa `lingolane-screen`; `set_current_screen( 'edit-post' )` thì trả đúng `'a'`.
- [ ] **Step 2: Chạy** `bash /private/tmp/lingolane-run/phpunit.sh --filter Test_Lingolane_UI_Tokens`. Kỳ vọng: FAIL (thiếu `accent-soft`…, thiếu `body_class`).
- [ ] **Step 3: Cài đặt** khối token theo giá trị trên. Thêm `add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) )` trong `init()`. CSS nền: `body.lingolane-screen #wpcontent, body.lingolane-screen #wpbody-content { background: var(--lingolane-bg) }`. Vì body nằm ngoài gốc token nên đặt màu literal `#f7f8fb` ở đây kèm comment. Đặt `font-family: var(--lingolane-font)` trên các gốc. Thêm khối `@media (prefers-reduced-motion: reduce)` đặt `--lingolane-dur: 0ms` và `animation: none` cho các gốc.
- [ ] **Step 4: Chạy lại** test của task và toàn bộ suite. Kỳ vọng: PASS, tổng 55 test.
- [ ] **Step 5: Commit** `feat(ui): token giao diện SaaS sáng, phạm vi CSS và lớp nền trang`.

### Task 2: Bộ icon SVG và `Lingolane_UI::icon()`

**Files:**
- Create: `lingolane/admin/class-lingolane-ui.php`
- Create: `lingolane/assets/icons.php` (trả `array` tên ⇒ phần tử SVG bên trong, lấy từ Lucide `lucide-static@0.460.0`, viewBox 24)
- Create: `lingolane/assets/LICENSE-lucide.txt` (nguyên văn giấy phép ISC của Lucide)
- Modify: `lingolane/lingolane.php` (require `admin/class-lingolane-ui.php` trong khối `is_admin()`, trước `class-lingolane-admin.php`)
- Modify: `lingolane/assets/admin.css` (thành phần `.lingolane-icon`)
- Test: `tests/phpunit/test-ui.php`

**Interfaces:**
- Produces: `Lingolane_UI::icon( string $name, int $size = 20, string $label = '' ): string`. Trả `<svg class="lingolane-icon lingolane-icon--{name}" width="{size}" height="{size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" …>{inner}</svg>`:
  - Khi `$label === ''`: thêm `aria-hidden="true" focusable="false"`.
  - Khi có nhãn: `role="img"` và `<title>` đã escape.
  - Tên không có trong bộ: trả `''` và gọi `_doing_it_wrong( 'Lingolane_UI::icon', …, '1.0.0' )`.
  - `$size` được kẹp trong khoảng 12–48.
- Produces: `Lingolane_UI::icons(): array` (danh sách tên, đọc `assets/icons.php` một lần, cache tĩnh).
- Bộ tên bắt buộc (22): `layout-dashboard, files, type, languages, settings, life-buoy, circle-check, eye, triangle-alert, upload, download, search, plus, info, users, external-link, chevron-down, chevron-right, x, sparkles, globe, loader`.

- [ ] **Step 1: Viết test hỏng** `tests/phpunit/test-ui.php`, lớp `Test_Lingolane_UI extends Lingolane_TestCase` (require file UI trong `wpSetUpBeforeClass` giống `test-admin.php`):
  - `test_every_required_icon_exists()`: mỗi tên trong danh sách 22 có trong `Lingolane_UI::icons()`.
  - `test_decorative_icon_is_hidden_from_screen_readers()`: `icon('globe')` chứa `aria-hidden="true"`, `width="20"`, `class="lingolane-icon lingolane-icon--globe"`, và không chứa `<title>`.
  - `test_labelled_icon_has_escaped_title()`: `icon('info', 16, 'A <b>')` chứa `role="img"`, `<title>A &lt;b&gt;</title>`, `width="16"`.
  - `test_unknown_icon_returns_empty_string()`: `setExpectedIncorrectUsage( 'Lingolane_UI::icon' )`; `assertSame( '', icon('nope') )`.
  - `test_size_is_clamped()`: `icon('x', 500)` chứa `width="48"`; `icon('x', 1)` chứa `width="12"`.
- [ ] **Step 2: Chạy** `--filter Test_Lingolane_UI`. Kỳ vọng: FAIL (lớp chưa có).
- [ ] **Step 3: Cài đặt** lớp, file icon, file giấy phép. CSS `.lingolane-icon { flex: none; display: inline-block; vertical-align: -0.2em; }`. Khi icon đứng trong nút hoặc tab, căn bằng `display:inline-flex; align-items:center; gap: var(--lingolane-space-2)` ở thành phần cha, không dùng margin lẻ (đây là nguyên nhân "icon lệch").
- [ ] **Step 4: Chạy lại** test của task và toàn bộ suite. Kỳ vọng: PASS.
- [ ] **Step 5: Commit** `feat(ui): bộ icon SVG Lucide và Lingolane_UI::icon`.

### Task 3: Thay toàn bộ dashicons, bỏ phụ thuộc dashicons

**Files:**
- Modify: `lingolane/admin/class-lingolane-admin.php` (`tabs()` dòng 26–35: phần tử thứ 2 đổi thành tên icon; markup tab dòng ~403; header dòng ~383; 18 chỗ `dashicons`; `enqueue()` dòng 140 bỏ `'dashicons'` khỏi deps)
- Modify: `lingolane/admin/class-lingolane-strings-admin.php` (4 chỗ: 307, 370, 424, 435)
- Modify: `lingolane/admin/class-lingolane-metabox.php` (1 chỗ dashicons; `wp_enqueue_style` dòng 16 bỏ deps `dashicons`)
- Modify: `lingolane/admin/class-lingolane-columns.php` (1 chỗ dashicons; `enqueue()` dòng 15 bỏ deps `dashicons`)
- Test: `tests/phpunit/test-ui.php` (thêm test)

**Interfaces:**
- Consumes: `Lingolane_UI::icon()` (Task 2).
- Produces: `Lingolane_Admin::tabs()` trả `key => array( label, icon_name )`. Ánh xạ: overview→`layout-dashboard`, content→`files`, strings→`type`, languages→`languages`, settings→`settings`, guide→`life-buoy`. Ánh xạ cho các chỗ còn lại: yes-alt→`circle-check`, translation→`languages`, visibility→`eye`, warning→`triangle-alert`, upload→`upload`, download→`download`, sos→`life-buoy`, search→`search`, plus→`plus`, info→`info`, groups→`users`, external→`external-link`, editor-textcolor→`type`, dashboard→`layout-dashboard`, admin-page→`files`, admin-generic→`settings`.

- [ ] **Step 1: Viết test hỏng** (thêm vào `Test_Lingolane_UI`, nạp thêm các file admin như `test-admin.php`, đăng nhập administrator):
  - `test_no_screen_uses_dashicons()`: với mỗi `$tab` trong `array_keys( Lingolane_Admin::tabs() )`, đặt `$_GET['tab']`, `set_current_screen('toplevel_page_lingolane')`, `ob_start(); Lingolane_Admin::render(); $html = ob_get_clean();`. Assert `assertStringNotContainsString( 'dashicons', $html, $tab )` và `assertStringContainsString( 'lingolane-icon', $html, $tab )`.
  - `test_metabox_and_column_use_svg_icons()`: render `Lingolane_Metabox::render( get_post( $page ) )` (trang có bản dịch) và `Lingolane_Columns::render( 'lingolane_language', $page )` qua output buffer; không chứa `dashicons`.
  - `test_styles_no_longer_depend_on_dashicons()`: gọi `Lingolane_Admin::enqueue( 'toplevel_page_lingolane' )`; `assertNotContains( 'dashicons', wp_styles()->registered['lingolane-admin']->deps )`.
- [ ] **Step 2: Chạy** `--filter Test_Lingolane_UI`. Kỳ vọng: FAIL ở 3 test mới.
- [ ] **Step 3: Thay** từng chỗ theo bảng ánh xạ. Mọi chỗ in icon dùng `echo Lingolane_UI::icon( '…' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from a fixed list`. Tìm sót bằng `grep -rn dashicons lingolane/` (kỳ vọng 0 kết quả ngoài `.pot/.po`).
- [ ] **Step 4: Chạy lại** test của task và toàn bộ suite. Kỳ vọng: PASS.
- [ ] **Step 5: Commit** `refactor(ui): thay dashicons bằng icon SVG trên mọi màn hình`.

### Task 4: Thành phần chung và restyle các màn hình

**Files:**
- Modify: `lingolane/assets/admin.css` (viết lại phần thành phần và màn hình; gỡ bí danh token cũ của Task 1)
- Modify: `lingolane/admin/class-lingolane-admin.php` (header: ô logo `<span class="lingolane-logo">` chứa `icon('globe')`; thêm lớp cho thẻ số liệu ở `render_overview()`)
- Test: `tests/phpunit/test-ui.php` (thêm test)

**Interfaces:**
- Consumes: token (Task 1), icon (Task 2–3).
- Produces: các lớp thành phần dùng chung cho P1/P3/P4/P5 sau này, mô tả trong comment đầu mỗi khối CSS:
  - `.lingolane-logo`;
  - `.lingolane-tabs` (dạng pill, mục đang chọn nền `accent-soft`, chữ `accent-ink`);
  - `.lingolane-card` (nền card, viền `line`, `radius`, `shadow-sm`, rê chuột lên `shadow-md` nếu có lớp `.is-interactive`);
  - `.lingolane-kpi` (số 28px/700, nhãn 12px `muted`);
  - `.lingolane-progress` (thanh 6px, phần đầy gradient `accent→accent-2`, chạy từ 0 khi tải trang bằng `transition: width var(--lingolane-dur)`, và giữ nguyên khi giảm chuyển động);
  - `.lingolane-chip--*` (giữ các biến thể ok/warn/info/brand hiện có);
  - `.lingolane-table` (đầu bảng 12px chữ hoa `muted`, dòng rê chuột nền `bg`);
  - `.lingolane-button` (bổ sung cho `.button` của WP: bo `radius-sm`, nút chính nền `accent`, focus `outline: 2px solid var(--lingolane-accent); outline-offset: 2px`);
  - `.lingolane-field` (input bo `radius-sm`, focus viền `accent`).

  Áp dụng cho các màn hình: Tổng quan, Bài & trang (bảng ma trận), Chuỗi, Ngôn ngữ, Cài đặt, Hướng dẫn. Áp dụng thêm cho hộp "Bản dịch" trong trình soạn thảo (`.lingolane-box`) và cột ngôn ngữ ở danh sách Trang/Bài viết (`.lingolane-col`): hai phần này không có `body.lingolane-screen`, nên chỉ dùng token trên gốc của chính chúng. Dưới 782px: header xếp dọc, `.lingolane-tabs` và `.lingolane-table-wrap` dùng `overflow-x:auto` trong khung riêng.

- [ ] **Step 1: Viết test hỏng**:
  - `test_header_has_logo_tile()`: render tab overview chứa `class="lingolane-logo"` và `lingolane-icon--globe`.
  - `test_overview_uses_kpi_cards()`: render overview khi có 2 ngôn ngữ chứa `lingolane-kpi` và `lingolane-progress`.
  - `test_old_token_aliases_removed()`: `admin.css` không còn `--lingolane-brand` và `--lingolane-soft`.
- [ ] **Step 2: Chạy**. Kỳ vọng: FAIL.
- [ ] **Step 3: Cài đặt** CSS và markup như trên. Không đổi tên lớp đang được `admin.js` dùng (`data-lingolane-*`, `.lingolane-string*`, `.lingolane-popover`).
- [ ] **Step 4: Chạy lại** test của task và toàn bộ suite. Kỳ vọng: PASS. Chạy thêm `composer lint` từ gốc repo. Kỳ vọng: 0 lỗi.
- [ ] **Step 5: Commit** `feat(ui): thành phần chung và restyle các màn hình quản trị`.

### Task 5: Toast thay cho thông báo WordPress

**Files:**
- Modify: `lingolane/admin/class-lingolane-admin.php` (`render_notices()` dòng ~427–434)
- Modify: `lingolane/assets/admin.js` (thêm khối toast độc lập ở đầu IIFE, trước phần preset)
- Modify: `lingolane/assets/admin.css` (thành phần `.lingolane-toasts`, `.lingolane-toast--{success|info|warning|error}`, hiệu ứng trượt vào 160ms)
- Test: `tests/phpunit/test-ui.php` (thêm test)

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
  - `role="alert"` và `data-autohide="0"` cho `error` và `warning`; `role="status"` và `data-autohide="5000"` cho `success` và `info`.
  - Icon theo loại: success→`circle-check`, info→`info`, warning/error→`triangle-alert`.
  - JS: `window.lingolaneToast( message: string, type: string ): void` tạo một toast cùng markup (để các phần P3–P5 dùng cho thao tác AJAX); tự ẩn theo `data-autohide`, tạm dừng khi `mouseenter`/`focusin`, nút đóng gỡ toast. Khi giảm chuyển động thì không chạy hiệu ứng.

- [ ] **Step 1: Viết test hỏng**:
  - `test_success_notice_renders_as_autohiding_toast()`: `Lingolane_Admin::flash( 'success', 'Saved <b>' )` rồi render overview. HTML chứa `lingolane-toast--success`, `role="status"`, `data-autohide="5000"`, `Saved &lt;b&gt;`, `lingolane-toast__close`, và không chứa `notice notice-`.
  - `test_error_notice_stays_until_dismissed()`: với `flash('error', …)` thì có `role="alert"`, `data-autohide="0"`, `lingolane-icon--triangle-alert`.
- [ ] **Step 2: Chạy**. Kỳ vọng: FAIL.
- [ ] **Step 3: Cài đặt** PHP, CSS, JS như trên. Chuỗi mới: "Notifications", "Dismiss notification".
- [ ] **Step 4: Chạy lại** test của task và toàn bộ suite. Kỳ vọng: PASS.
- [ ] **Step 5: Commit** `feat(ui): toast cho thông báo, tự ẩn với thành công, giữ với lỗi`.

### Task 6: Minh hoạ cho màn hình trống và khối chào

**Files:**
- Create: `lingolane/assets/illustrations/welcome.svg`, `empty-languages.svg`, `all-done.svg` (SVG tự vẽ, mỗi file ≤ 4 KB, viewBox 160×120, màu qua `var(--lingolane-accent, #4f46e5)` / `var(--lingolane-accent-2, #7c3aed)` / `var(--lingolane-accent-soft, #eef0ff)`, không `<script>`, không link ngoài)
- Modify: `lingolane/admin/class-lingolane-ui.php` (thêm `illustration()`)
- Modify: `lingolane/admin/class-lingolane-admin.php`:
  - `render_overview()`: khối `lingolane-empty` dòng ~444 dùng `empty-languages`;
  - khi mọi ngôn ngữ đạt 100% thì dùng `all-done`;
  - tab Hướng dẫn mở đầu bằng `welcome`.
- Modify: `lingolane/admin/class-lingolane-strings-admin.php:248` (màn hình trống dùng `empty-languages`)
- Modify: `lingolane/assets/admin.css` (`.lingolane-illustration`, `.lingolane-empty` dạng căn giữa có minh hoạ ở trên)
- Test: `tests/phpunit/test-ui.php` (thêm test)

**Interfaces:**
- Produces: `Lingolane_UI::illustration( string $name ): string`. Tên hợp lệ: `welcome`, `empty-languages`, `all-done`. Trả `<div class="lingolane-illustration" aria-hidden="true">{nội dung file svg}</div>`. Tên khác: `''` kèm `_doing_it_wrong`. Đọc file bằng `file_get_contents` kèm `// phpcs:ignore WordPress.WP.AlternativeFunctions -- local bundled asset`, giống `class-lingolane-switcher.php:16`.

- [ ] **Step 1: Viết test hỏng**:
  - `test_illustrations_are_safe_inline_svg()`: với mỗi tên, kết quả bắt đầu bằng `<div class="lingolane-illustration" aria-hidden="true"><svg`, không chứa `<script`, `href="http`, `xlink:href="http`; kích thước file ≤ 4096 byte.
  - `test_unknown_illustration_returns_empty()`: `setExpectedIncorrectUsage( 'Lingolane_UI::illustration' )`, `'' === illustration('../../wp-config')`.
  - `test_empty_states_show_illustration()`: lọc `pre_option_lingolane_settings` chỉ còn một ngôn ngữ; render overview và tab strings đều chứa `lingolane-illustration`.
- [ ] **Step 2: Chạy**. Kỳ vọng: FAIL.
- [ ] **Step 3: Cài đặt** như trên.
- [ ] **Step 4: Chạy lại** test của task và toàn bộ suite. Kỳ vọng: PASS.
- [ ] **Step 5: Commit** `feat(ui): minh hoạ cho màn hình trống, hoàn thành và hướng dẫn`.

### Task 7: Kiểm giao diện thật, i18n và tài liệu

**Files:**
- Create: `bin/ui-preview.sh` (dựng HTML tĩnh của từng tab để xem không cần đăng nhập)
- Modify: `.gitignore` (thêm `dist/ui-preview/`)
- Modify: `lingolane/languages/lingolane.pot`, `lingolane-vi.po`, `lingolane-vi.mo`
- Modify: `docs/RELEASE-PLAN.md` (nhật ký P2), `README.md` (dòng về giao diện, credit Lucide)

**Interfaces:**
- Produces: `bin/ui-preview.sh`, không tham số. Script build và cài plugin lên site Local `check` bằng `/tmp/wpc.sh` giống `cycle.sh`. Sau đó, với mỗi tab, chạy `wp eval` dựng `Lingolane_Admin::render()` (nạp các file admin như test) thành `dist/ui-preview/<tab>.html`, có `<link>` tới CSS lõi `http://check.local/wp-admin/css/{common,forms,buttons,list-tables}.min.css` và `http://check.local/wp-content/plugins/lingolane/assets/admin.css`, có `<body class="wp-admin lingolane-screen">`, và cuối cùng in đường dẫn file.

- [ ] **Step 1: Viết** `bin/ui-preview.sh`, chạy nó. Kỳ vọng: 6 file `dist/ui-preview/{overview,content,strings,languages,settings,guide}.html`.
- [ ] **Step 2: Kiểm bằng trình duyệt** (khung trình duyệt của app, phục vụ thư mục `dist/ui-preview` bằng `python3 -m http.server`) ở bề rộng 1280px và 375px. Đạt khi:
  - icon thẳng hàng với chữ trong tab, nút và chip;
  - không có thanh cuộn ngang ở cấp trang khi rộng 375px;
  - viền focus thấy rõ khi bấm Tab;
  - toast hiện và tự ẩn với thông báo thành công;
  - chạy `matchMedia('(prefers-reduced-motion: reduce)')` giả lập (DevTools/`resize_window`) thì không có chuyển động;
  - so với mockup A (nền xám nhạt, thẻ trắng bóng nhẹ, màu nhấn tím–chàm).

  Chụp ảnh `scale 0.5` mỗi tab cho Tan xem.
- [ ] **Step 3: Cập nhật i18n:** `/tmp/wpc.sh i18n make-pot …` (lệnh ở README), thêm bản dịch vi cho chuỗi mới ("Notifications" → "Thông báo", "Dismiss notification" → "Đóng thông báo"), `python3 bin/make-mo.py`.
- [ ] **Step 4: Chạy toàn bộ kiểm tra:**
  - `bash /private/tmp/lingolane-run/phpunit.sh` (PHP 8.2) và bản PHP 7.4 (`php74.sh` với `wp-tests-config-74.php`): tất cả đạt;
  - `composer lint`: 0 lỗi;
  - `bin/build.sh`: zip có `admin/class-lingolane-ui.php`, `assets/icons.php`, `assets/LICENSE-lucide.txt`, `assets/illustrations/*.svg`;
  - Plugin Check: 0 lỗi.
- [ ] **Step 5: Ghi nhật ký** vào RELEASE-PLAN (đã làm gì, số test, ảnh đã kiểm, điều chưa kiểm). README thêm dòng credit "Icons: Lucide (ISC)". Commit `chore(ui): i18n, xem trước giao diện và nhật ký P2`.
