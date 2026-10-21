# Kế hoạch phát hành Lingolane 1.0 (chạy được trên website bất kỳ)

Ngày rà soát: 2026-10-21. Phạm vi: `lingolane/` (~3.500 dòng PHP, 7 file test thủ công).

## 1. Hiện trạng (đã rà bằng mã nguồn)

**Đạt, giữ nguyên**
- Bảo mật cơ bản: mọi `admin_post_*` đi qua `TML_Admin::guard()` (`manage_options` + `check_admin_referer`); metabox kiểm `edit_post` + nonce; AJAX có `ajax_guard()`; không còn `$_GET/$_POST` thô; không có `$wpdb` raw thiếu `prepare`.
- Router bỏ qua `wp-admin`, `wp-json`, `wp-login.php`, `wp-cron`, `admin-ajax` (`class-tml-router.php:21-25`).
- Bảng DB dùng `dbDelta` + `get_charset_collate`, có `tml_db_version` tự nâng cấp; cache object/transient có xoá đúng chỗ.
- Gỡ plugin không xoá dữ liệu (chủ ý). Đã gỡ phụ thuộc Teachme bằng filter.

**Chưa đạt (chặn phát hành)**
| # | Vấn đề | Bằng chứng | Mức |
|---|---|---|---|
| B1 | Chuỗi gốc là tiếng Việt (~256 dòng), không `load_plugin_textdomain`, không thư mục `languages/`, không `.pot` | admin 175, strings-admin 51, metabox 12… | Chặn |
| B2 | `readme.txt` cũ: tiếng Việt, nhắc "hồ sơ gia sư", `License: Proprietary`, changelog 1.2 của Teachme | `readme.txt` | Chặn |
| B3 | Giấy phép: `LICENSE` là bản tóm tắt tự viết, chưa phải GPL đầy đủ; chưa quyết định kênh phân phối | `LICENSE` | Chặn |
| B4 | Chưa có test tự động/CI; test cũ là `wp eval-file` chỉ chạy trên host `.local/.test`, chưa chạy lại sau khi tách | `tests/` | Chặn |
| B5 | Chỉ tương thích Yoast. Không xử lý Rank Math, AIOSEO, SEOPress, sitemap lõi WP (`wp-sitemap.xml`) | `class-tml-seo.php` | Chặn |

**Rủi ro khi gắn vào site lạ (cần kiểm, chưa có bằng chứng)**
R1 Multisite (không có mã xử lý). R2 Site cài trong thư mục con, permalink "Plain", HTTPS/proxy. R3 Theme khối (FSE) và block Navigation (switcher hiện hỗ trợ `wp_nav_menu`/widget/shortcode). R4 Page builder (Elementor, Divi, Bricks), ACF/meta tuỳ chỉnh, WooCommerce. R5 Plugin cache (WP Rocket, LiteSpeed, Cloudflare) với URL theo ngôn ngữ. R6 Chạy cùng Polylang/WPML gây xung đột. R7 Hiệu năng truy vấn lọc theo ngôn ngữ trên site lớn (10k+ bài). R8 Tiền tố `tml_` chỉ 3 ký tự (ĐÃ XỬ LÝ ở M0: đổi thành `lingolane_`). R9 Phiên bản lặp ở 3 nơi (header, `TML_VERSION`, readme). R10 Chưa kiểm có gọi HTTP ra ngoài hay không (ảnh hưởng chính sách riêng tư).

## 2. Quyết định cần Tan (chặn M0)
1. **Kênh phân phối:** WordPress.org (miễn phí, GPL, duyệt thủ công 1–4 tuần) · bán/phát hành riêng trên site (GPL, tự cập nhật qua GitHub Releases) · cả hai (bản lite + bản trả phí).
2. **Tên thương hiệu và slug cuối** ("Lingolane" là tên nội bộ; cần kiểm trùng tên). Nếu đổi prefix `tml_` thành ≥4 ký tự, làm **trước** khi có người dùng ngoài.
3. **Phạm vi 1.0:** đơn site, không multisite (khuyến nghị) · hỗ trợ Yoast + Rank Math + sitemap lõi (khuyến nghị) · WooCommerce để 1.1.
4. Tạo repo GitHub (private trước, public khi phát hành).

## 3. Lộ trình

### M0 – Nền tảng (1–2 ngày)
- Chốt tên/prefix; nếu đổi, đổi bằng script một lần, có test hồi quy.
- Một nguồn phiên bản (đọc từ header plugin), script `bin/build.sh` ra zip sạch (loại `tests/`, `.git`).
- Thay `LICENSE` bằng GPL-2.0-or-later đầy đủ; viết lại `readme.txt` tiếng Anh theo chuẩn WordPress.org (mô tả, FAQ, screenshots, changelog 1.0.0).
- **Xong khi:** zip cài được trên WP trống, `readme.txt` qua trình kiểm readme của WordPress.org.

### M1 – Chuẩn hoá i18n (2 ngày)
- Đổi toàn bộ chuỗi gốc sang tiếng Anh; `load_plugin_textdomain` ở `init`; sinh `languages/lingolane.pot`; xuất bản dịch `vi` (.po/.mo) từ chuỗi cũ để Teachme không mất chữ.
- Chuỗi ghép dùng `sprintf`/`_n`, có comment `translators:`.
- **Xong khi:** đổi ngôn ngữ quản trị sang vi vẫn ra giao diện tiếng Việt như cũ; grep ký tự tiếng Việt trong mã nguồn trả 0.

### M2 – Tự động kiểm thử và chất lượng (2–3 ngày)
- Chuyển 7 file `tests/` sang PHPUnit + `wp-env` (bỏ ràng buộc host `.local`); dữ liệu mẫu tạo trong test, không phụ thuộc CPT Teachme.
- GitHub Actions: ma trận PHP 7.4 / 8.1 / 8.4 × WP 6.2 / mới nhất / nightly; thêm WPCS (Security, i18n, tương thích PHP 7.4) và **Plugin Check**.
- **Xong khi:** CI xanh trên ma trận; phpcs bảo mật 0 lỗi; độ phủ các lớp Router/Urls/Elements/Strings/Shared ≥ 70%.

### M3 – Tương thích "website bất kỳ" (4–6 ngày)
Kiểm trên site Local sạch cho từng ô, ghi kết quả vào `docs/COMPAT.md`:
| Nhóm | Việc |
|---|---|
| SEO | Adapter Rank Math, AIOSEO, SEOPress; hreflang/canonical/sitemap cho `wp-sitemap.xml` lõi; chỉ gắn hook Yoast khi có Yoast |
| Cấu hình URL | Cài thư mục con, permalink Plain (tự phát hiện + cảnh báo thay vì hỏng), HTTPS sau proxy/Cloudflare, `WP_HOME` ≠ `WP_SITEURL` |
| Theme | Theme cổ điển (menu, widget), theme khối (FSE: block Navigation, template part), shortcode trong block |
| Nội dung | Gutenberg, Classic, Elementor, ACF/meta sao chép khi tạo bản dịch, ảnh nổi bật, taxonomy tuỳ chỉnh qua `tml_translatable_post_types` |
| Cache | WP Rocket, LiteSpeed, Cloudflare: tài liệu quy tắc cache theo URL ngôn ngữ, không dùng cookie ngôn ngữ làm khoá |
| Xung đột | Phát hiện Polylang/WPML đang bật → cảnh báo và tự tạm tắt router thay vì chạy song song |
| Multisite | 1.0: chặn kích hoạt network-wide kèm thông báo rõ (hoặc hỗ trợ đầy đủ nếu Tan chọn) |
| Hiệu năng | Benchmark 10k bài/50k term: số truy vấn thêm, TTFB; thêm chỉ mục nếu cần |
- **Xong khi:** mọi ô "Đạt" hoặc "Không hỗ trợ + có cảnh báo trong plugin"; không lỗi PHP/JS ở console trên các site kiểm.

### M4 – Trải nghiệm lần đầu (2 ngày)
- Trình hướng dẫn cài đặt (ngôn ngữ gốc, ngôn ngữ phụ, kiểu URL); không đổi URL hiện có cho tới khi người dùng xác nhận.
- Tuỳ chọn "xoá dữ liệu khi gỡ cài đặt" (mặc định tắt), nhập/xuất cấu hình, công cụ nhập chỉ-đọc từ Polylang/WPML.
- Kiểm trợ năng WCAG 2.2 AA cho trang quản trị và nút chuyển ngôn ngữ.
- **Xong khi:** người mới cài xong và có trang đầu tiên được dịch trong < 5 phút (đo trên 3 người thử).

### M5 – Phát hành (2–3 ngày) · chờ Tan duyệt
- Beta kín 5–10 site thật (gồm Teachme chuyển từ bản cũ), thu lỗi 1 tuần.
- Chính sách riêng tư/khai báo dữ liệu, `SECURITY.md`, `CONTRIBUTING.md`, ảnh chụp màn hình, banner.
- Tag `v1.0.0`, GitHub Release kèm zip; nộp WordPress.org nếu chọn kênh đó; cơ chế cập nhật (WordPress.org hoặc updater qua GitHub Releases).
- **Xong khi:** không còn lỗi nghiêm trọng sau 7 ngày beta; Tan duyệt tạo repo public/đăng.

### M6 – Sau phát hành
WooCommerce (1.1), dịch tự động tuỳ chọn (cần Tan duyệt chi phí và quyền riêng tư), multisite, kênh hỗ trợ và quy trình vá lỗi.

## 4. Thứ tự và phụ thuộc
M0 → (M1 ∥ M2) → M3 → M4 → M5. M1 và M2 độc lập nên chạy song song; M3 cần CI của M2 và chuỗi tiếng Anh của M1. Ước tính 2,5–3 tuần làm việc cho một người.

## 5. Chuyển Teachme sang bản mới
Bảng DB `tml_translations`/`tml_strings` giữ nguyên tên nên không cần di chuyển dữ liệu. Chỉ cần kích hoạt bản mới thay bản cũ, thêm mu-plugin filter (README), và đổi `is_tutor` thành `is_gated` nếu theme/plugin Teachme có gọi. Làm trên Local trước; live cần Tan duyệt.

## 6. Nhật ký thực hiện
**2026-10-21 · M0 (phần làm được không cần site thật) · Đã quyết:** chọn cả WordPress.org (bản lite) và phát hành riêng (Tan duyệt); tên **Lingolane**, slug/text-domain `lingolane`, tiền tố `lingolane_`/`LINGOLANE_`/`Lingolane_*` (kiểm API WordPress.org: slug `lingolane` chưa tồn tại, tìm kiếm tên không ra kết quả tương tự). Lý do: tên ngắn, không mang tên Teachme, prefix dài đủ tránh trùng. Hoàn tác: `git revert` commit đổi tên.
Đã xong: đổi tên toàn bộ, chuyển dữ liệu `tml_` → `lingolane_` khi kích hoạt, lớp tương thích `TML_*`, GPL-2.0 đầy đủ, `readme.txt` tiếng Anh, `bin/build.sh` (kiểm phiên bản khớp 3 nơi, lint, zip không gồm tests; chạy thử ra 27 file).
Chưa xong M0: kiểm readme bằng trình kiểm của WordPress.org; cài thử zip trên WP sạch (site Local `check` đã có Plugin Check, chờ Tan cho phép cài thử); kiểm trùng thương hiệu "Lingolane" ngoài WordPress.org (chưa tra cứu nhãn hiệu).

**2026-10-21 · M0 nghiệm thu trên site Local `check` (WP 7.1.2, PHP 8.2.29, MySQL 8.4.0, theme khối Twenty Twenty-Five, permalink `/%postname%/`):**
- Cài từ chính file zip `dist/lingolane-1.0.0-dev.zip` và kích hoạt: đạt; tạo 2 bảng `wp_lingolane_strings`, `wp_lingolane_translations`; `lingolane_db_version` = 0.4.0; class và alias `TML_*` nạp được.
- **Lỗi thật tìm thấy và đã sửa:** sau khi gỡ danh sách Teachme, danh sách dùng chung mặc định rỗng làm truy vấn sinh `IN ()` → lỗi cú pháp SQL ở `class-lingolane-query.php` và `class-lingolane-shared.php`. Đã thêm xử lý danh sách rỗng. Đã thay `seems_utf8()` (lỗi thời từ WP 6.9) bằng kiểm UTF-8 không phụ thuộc phiên bản; rút gọn mô tả readme còn 115 ký tự.
- **Bộ test (7 file `wp eval-file`):** 112 kiểm tra, 0 lỗi (elements 14, urls 13, router 34, shared 9, default 16, admin 14, strings 12). Test được viết lại để tự chứa: cấu hình ngôn ngữ cố định và tự khai báo CPT/taxonomy thay vì dựa vào Teachme.
- **Plugin Check 2.1.0 (lần chạy sạch):** 20 lỗi + 126 cảnh báo, 0 phát hiện ngoài nhóm sau. 20 lỗi đều là `PreparedSQL.NotPrepared`/`UnescapedDBParameter` tại `class-lingolane-elements.php:64`, `class-lingolane-admin.php:539-541`, `class-lingolane-stats.php:39,57,61,76` (SQL ghép từ đoạn đã `prepare`, cần tách lại thành một lệnh `prepare` hoặc ghi chú bỏ qua có lý do). Cảnh báo: truy vấn trực tiếp bảng riêng không dùng cache (~85), nội suy tên bảng (20), hook `gettext` và 2 hàm `tml_*` của lớp tương thích bản cũ (cần loại `compat-legacy.php` khỏi bản WordPress.org lite). Một lần chạy lẻ báo 240 phát hiện kèm đường dẫn `Projects/tests/…` không tồn tại; không giải thích được, chạy lại cho kết quả 146.
- **Việc chuyển sang M2:** xử lý 20 lỗi SQL; bản lite bỏ `compat-legacy.php`; trình kiểm readme của WordPress.org; kiểm nhãn hiệu "Lingolane".
- **Chưa kiểm:** giao diện thật trên frontend (chưa có bài viết, chưa gọi URL `/vi/`), nâng cấp từ bảng `tml_*` thật (chưa chạy migration trên dữ liệu Teachme), PHP 7.4/8.4, Rank Math/Yoast.

**2026-10-21 · M1 chuẩn hoá i18n · xong:**
- 264 chuỗi gốc tiếng Việt (249 ở lượt đầu + 15 chuỗi bộ lọc ký tự bỏ sót ở lượt hai) đổi sang tiếng Anh trong 7 file PHP; 8 thông báo lỗi trước đây không bọc hàm dịch (`class-lingolane-elements.php`) nay dùng `__()`; chuỗi số nhiều `%s item/%s items`. Các chuỗi còn nhắc "TeachMe", "hồ sơ gia sư" được viết lại trung tính. Còn lại duy nhất tên bản địa của ngôn ngữ (Português, Français, Español, Tiếng Việt…) – giữ nguyên có chủ ý.
- `lingolane.php`: thêm `Domain Path: /languages` và `load_plugin_textdomain( 'lingolane', … )` ở `init` ưu tiên 0.
- `languages/lingolane.pot` (270 msgid, sinh bằng `wp i18n make-pot`), `lingolane-vi.po` (266 mục = 264 chuỗi giao diện + mô tả plugin + “Locale”; vi giữ nguyên câu chữ gốc; 4 msgid metadata của header plugin như tên, URI không cần dịch), `lingolane-vi.mo`.
- `bin/make-mo.py` biên dịch `.po` → `.mo` không cần gettext (máy không có `msgfmt`, WP-CLI 2.4.0 không có `make-mo`); `bin/build.sh` gọi script này và đưa `languages/` vào zip.
- Kiểm chứng trên site `check`: bảng đăng ký textdomain trỏ đúng `languages/` cho locale `vi`; nạp lười (JIT) trả `Cài đặt`, `3 mục` (số nhiều), `Lingolane — Ngôn ngữ`; chuỗi ngoài danh mục giữ tiếng Anh. Bộ test: 112/112 đạt sau đổi chuỗi. Plugin Check: 147 phát hiện (20 lỗi SQL như cũ, 0 lỗi i18n; +1 cảnh báo `load_plugin_textdomain`).
- **Việc chuyển tiếp:** bản WordPress.org lite bỏ `load_plugin_textdomain` và `compat-legacy.php` (dịch qua translate.wordpress.org; bản phát hành riêng vẫn dùng `languages/`); thêm comment `/* translators: */` cho chuỗi nhiều placeholder và dùng `_n()` đúng chỗ (WPCS, M2); chưa kiểm giao diện quản trị trên trình duyệt ở locale `vi`; `.po` chưa qua trình kiểm của Poedit/GlotPress.
