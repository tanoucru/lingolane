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

**Bổ sung từ phân tích đối thủ** ([COMPETITOR-ANALYSIS.md](COMPETITOR-ANALYSIS.md) §3, P1). Học ý tưởng, tự viết mã; Polylang là GPLv3 nên không chép.
| Mã | Việc | Gắn với |
|---|---|---|
| C1 | Đọc `wpml-config.xml` của theme/plugin đang bật (custom field copy/dịch, CPT, taxonomy, `admin-texts`, xpath block) | Nội dung, page builder, ACF |
| C2 | Shim WPML API (`wpml_object_id`, `icl_object_id`, `wpml_current_language`, `wpml_element_language_details`), chỉ khi không có WPML/Polylang | Xung đột (R6) |
| C3 | Truy vấn lọc bằng subquery không tương quan, bỏ `CONCAT`/`COLLATE`, index `(language_code, element_type, element_id)`; benchmark trước và sau | Hiệu năng (R7) |
| C4 | Nạp cache bản dịch theo lô (`the_posts`, `get_terms`), hết hạn theo `last_changed` thay TTL 60 giây | Hiệu năng (R7) |
| C5 | Filter `lingolane_copy_post_metas` và dịch `_thumbnail_id` khi tạo bản dịch | Nội dung |
| C6 | Sitemap lõi `wp-sitemap.xml` đa ngôn ngữ | SEO (B5) |
| C7 | Block `lingolane/language-switcher` (dùng được trong block Navigation) và switcher nổi tuỳ chọn | Theme khối (R3) |
| C8 | Kiểm textdomain của theme/plugin nạp đúng ngôn ngữ của request; nếu sai, hoãn `load_textdomain` | Theme, plugin |
- **Xong khi:** mọi ô "Đạt" hoặc "Không hỗ trợ + có cảnh báo trong plugin"; không lỗi PHP/JS ở console trên các site kiểm; C1–C8 có test PHPUnit; C3 có số liệu benchmark trước và sau ghi trong `docs/COMPAT.md`.

### M4 – Trải nghiệm lần đầu (2 ngày)
- **C9** Trình hướng dẫn cài đặt: ngôn ngữ gốc và phụ → kiểu URL → gán ngôn ngữ mặc định cho nội dung cũ → trang chủ từng ngôn ngữ → vị trí switcher (xem trước) → "Dịch trang đầu tiên"; không đổi URL hiện có cho tới khi người dùng xác nhận.
- **C10** Nút "Dịch trang này" trên admin bar: thanh bên frontend liệt kê bản dịch của trang (tạo/sửa) và các chuỗi gettext thật sự xuất hiện trên trang, dịch ngay tại chỗ.
- **C11** Trạng thái "bản dịch lỗi thời" khi bài gốc sửa sau bản dịch (cột danh sách, metabox, thống kê); bảng chuỗi thêm cột `status` (máy/người).
- **C12** Registry cấu hình có schema, làm nền cho trang cài đặt và nhập/xuất cấu hình.
- Tuỳ chọn "xoá dữ liệu khi gỡ cài đặt" (mặc định tắt), nhập/xuất cấu hình, công cụ nhập chỉ-đọc từ Polylang/WPML.
- Chi tiết và nguồn: [COMPETITOR-ANALYSIS.md](COMPETITOR-ANALYSIS.md) §3, P2.
- Kiểm trợ năng WCAG 2.2 AA cho trang quản trị và nút chuyển ngôn ngữ.
- **Xong khi:** người mới cài xong và có trang đầu tiên được dịch trong < 5 phút (đo trên 3 người thử).

### M5 – Phát hành (2–3 ngày) · chờ Tan duyệt
- Beta kín 5–10 site thật (gồm Teachme chuyển từ bản cũ), thu lỗi 1 tuần.
- Chính sách riêng tư/khai báo dữ liệu, `SECURITY.md`, `CONTRIBUTING.md`, ảnh chụp màn hình, banner.
  - `SECURITY.md` đã có; nút "Report privately" ở tab Support trỏ tới email bảo mật `tan.oucru@gmail.com` (Tan đã duyệt 2026-10-23). Kênh nhận báo cáo bảo mật đã hoàn tất.
- Tag `v1.0.0`, GitHub Release kèm zip; nộp WordPress.org nếu chọn kênh đó; cơ chế cập nhật (WordPress.org hoặc updater qua GitHub Releases).
- **Xong khi:** không còn lỗi nghiêm trọng sau 7 ngày beta; Tan duyệt tạo repo public/đăng.

### M6 – Sau phát hành
WooCommerce (1.1), dịch tự động tuỳ chọn (cần Tan duyệt chi phí và quyền riêng tư), multisite, kênh hỗ trợ và quy trình vá lỗi. Thêm C13 (sync, dịch option, URL domain/subdomain, REST `lang`) và C14 (phát hiện ngôn ngữ trình duyệt, ngôn ngữ ưa thích của người dùng, bộ nhớ dịch, dịch máy có trạng thái) theo [COMPETITOR-ANALYSIS.md](COMPETITOR-ANALYSIS.md) §3, P3.

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

**2026-10-21 · M2 chất lượng và kiểm thử tự động · xong phần chạy được cục bộ:**
- **20 lỗi SQL của Plugin Check → 0.** Nguyên nhân chung: chuỗi SQL được dựng ngoài lệnh `prepare()` (biến `$sql`, nối chuỗi bằng hàm). Sửa thật ở `class-lingolane-elements.php` (đưa `prepare()` thẳng vào `query()`), `class-lingolane-stats.php` (`source_sql()` dùng placeholder cho mọi danh sách `IN`, bỏ helper `in_list`) và `class-lingolane-admin.php` (`$type_sql` tách ra biến). Các cảnh báo còn lại chỉ là tên bảng/danh sách placeholder không thể bind; có ghi chú `phpcs:ignore` kèm lý do ở các điểm tự dựng.
- **Phát hiện về phép đo:** `wp plugin check lingolane` chạy khi thư mục hiện tại chứa `./lingolane` sẽ kiểm *thư mục mã nguồn trong repo* (kể cả `tests/`, `.DS_Store`) thay vì plugin đã cài, đó là nguyên nhân của con số 240 bất thường ở M0/M1. Luôn chạy từ thư mục trung lập (`cd /tmp`). Kết quả đúng: **0 lỗi, 136 cảnh báo** (truy vấn bảng riêng, tên bảng, hook `gettext`, 2 hàm `tml_*` của lớp tương thích bản cũ, `load_plugin_textdomain`).
- **PHPUnit:** 7 script `wp eval-file` cũ (112 phép kiểm) chuyển thành 7 lớp trong `tests/phpunit/` + lớp nền `Lingolane_TestCase` (cấu hình ngôn ngữ cố định, permalink đẹp, fixture dùng chung, khôi phục trạng thái ngoài DB): **40 test, 126 assertion đạt**, mỗi test chạy trong transaction nên không cần dọn dẹp. Bản cũ đã xoá (còn trong git, commit `f75debf`). Kiểm đột biến: tái hiện lỗi `IN ()` của M0 thì PHPUnit thất bại như mong đợi, sửa lại thì xanh.
- **Chạy được trên:** PHP 7.4.30 và PHP 8.2.29 thật (Local) với lõi WP 7.1.2: cả hai 40/40. `php -l` sạch trên 7.4 / 8.2 / 8.4.
- **phpcs (WPCS 3.1 + PHPCompatibilityWP, `.phpcs.xml.dist`):** 0 lỗi, 26 cảnh báo. `NotPrepared` vẫn là lỗi chặn; `InterpolatedNotPrepared`/`UnfinishedPrepare` hạ xuống cảnh báo; `DirectQuery`/`NoCaching` tắt có lý do (bảng riêng, có cache ở `Lingolane_Elements`/`Lingolane_Strings`); `compat-legacy.php` loại khỏi kiểm tiền tố.
- **Hạ tầng CI (đã viết, CHƯA chạy được ở đây):** `.wp-env.json`, `.github/workflows/ci.yml` (5 job: phpcs, `php -l` 7.4/8.1/8.4, PHPUnit qua wp-env với PHP 7.4+WP 6.2.2 / PHP 8.1+WP mới nhất / PHP 8.4 thử nghiệm, Plugin Check qua `bin/plugin-check-gate.py`, đóng gói zip + kiểm nội dung), `composer.json` (scripts `test`/`lint`/`build`, platform PHP 7.4.33). Đã kiểm cú pháp YAML bằng Ruby, kiểm script gate trên kết quả thật (trước sửa: thoát mã 1 với 20 lỗi; sau sửa: mã 0) và các bước `package` chạy tay.
- **Rủi ro chưa kiểm chứng** (máy không có Docker, repo chưa có remote GitHub nên chưa chạy workflow lần nào): (1) `tests-cli` của wp-env có đặt `WP_TESTS_DIR` như bootstrap giả định hay không, nếu không bootstrap rơi về `wp-phpunit` của Composer (bản 7.1.1, có thể lệch với WP 6.2); (2) cách chỉ định lõi 6.2.2 bằng URL zip và hình ảnh Docker PHP 8.4 của wp-env; (3) định dạng đầu ra `wp plugin check --format=json` của bản Plugin Check mới nhất có thể khác bản 2.1.0 đã dùng để viết script gate. Lần chạy CI đầu tiên cần được xem lại từng job.
- **Còn lại của M2:** chạy CI thật sau khi có repo GitHub; ngưỡng độ phủ ≥ 70% chưa đo (cần Xdebug/PCOV); thêm comment `translators:` cho chuỗi nhiều placeholder; bản WordPress.org lite bỏ `compat-legacy.php` và `load_plugin_textdomain`.

**2026-10-21 · Danh mục ngôn ngữ và tải gói dịch theo nhu cầu (yêu cầu của Tan):**
- `Lingolane_Catalog` thay 18 ngôn ngữ gắn cứng: danh mục lấy từ `wp_get_available_translations()` (API WordPress.org, core cache sẵn), cộng 44 ngôn ngữ dự phòng khi mất mạng; bỏ biến thể ba phần (`de_DE_formal`, `pt_PT_ao90`…). Mã URL = mã ngôn ngữ; locale thứ hai cùng ngôn ngữ thêm vùng (`pt-br`, `en-gb`, `fr-ca`); giữ nguyên `en`/`pt`/`zh`/`zh-tw` như bản cũ. Bộ chọn chia nhóm "Phổ biến" (24) và "Tất cả".
- `Lingolane_Packs`: thêm ngôn ngữ (hoặc đổi locale) thì tải gói lõi bằng `wp_download_language_pack()`, rồi bản dịch theme/plugin của đúng locale đó qua `Language_Pack_Upgrader`; lọc `plugins_update_check_locales`/`themes_update_check_locales` để cập nhật bản dịch tự động phủ mọi ngôn ngữ của site. Lỗi tải không chặn việc thêm ngôn ngữ (thông báo vàng). Bảng ngôn ngữ có cột "Bản dịch" (Đã cài/Chưa cài + nút Tải về/Cập nhật).
- Kiểm chứng: PHPUnit 52 test/221 assertion đạt trên PHP 8.2 và 7.4 (12 test mới, không gọi mạng); kiểm đột biến bỏ lệnh tải thì 2 test thất bại; phpcs 0 lỗi, 26 cảnh báo (không đổi). Chạy thật trên site Local `check`: danh mục 131 ngôn ngữ (API trả 135, trừ 5 biến thể, cộng en_US); tải `fr_FR` mất 10,5 giây, có gói lõi và 20 tệp bản dịch theme/plugin (gói fr_FR để lại trên site `check`).
- Chưa kiểm: giao diện trang Ngôn ngữ trên trình duyệt; host không cho ghi tệp (`DISALLOW_FILE_MODS`, FTP); multisite.

**2026-10-22 · P2 Hệ giao diện SaaS sáng (kế hoạch `docs/plans/2026-10-22-p2-design-system.md`, nhánh `feat/p2-design-system`):**
- Task 1–6: token màu/kích thước và phạm vi CSS `body.lingolane-screen`; bộ icon Lucide (ISC, chép vào `assets/icons.php`) qua `Lingolane_UI::icon()` thay dashicons trong trang (menu quản trị vẫn dùng dashicon); thành phần chung và restyle 6 tab; toast thay thông báo WordPress (thành công/thông tin tự ẩn sau 5 giây, tạm dừng khi rê chuột/focus; lỗi/cảnh báo giữ lại); minh hoạ SVG cho màn hình trống, hoàn thành và khối chào.
- Task 7 phần A: `bin/ui-preview.sh` dựng `dist/ui-preview/{overview,content,strings,languages,settings,guide}.html` (kèm bản sao CSS/JS lõi và plugin, xem không cần đăng nhập; overview và settings có toast mẫu). `.pot` sinh lại (291 chuỗi, thêm 8), thêm 8 bản dịch vi (Notifications → Thông báo, Dismiss notification → Đóng thông báo, khối chào, màn hình "đã dịch xong").
- Kiểm chứng: PHPUnit 78 test/393 assertion đạt trên PHP 8.2.29 và 7.4.30; phpcs 0 lỗi, 26 cảnh báo (đúng mốc); zip có `admin/class-lingolane-ui.php`, `assets/icons.php`, `assets/LICENSE-lucide.txt`, 3 file `assets/illustrations/*.svg`; Plugin Check 0 lỗi, 136 cảnh báo, trùng từng file/mã với lần chạy trước P2 (không cảnh báo mới).
- QA trình duyệt (1280px và 375px): ĐẠT — icon thẳng hàng trong tab (lệch 0px ×6), không cuộn ngang cấp trang ở 375px trên cả 6 tab (tab/bảng cuộn trong khung riêng), viền focus bàn phím 2px #4f46e5 cách 2px, có luật reduced-motion, minh hoạ hiển thị, toast thành công tự ẩn. Đã sửa sau QA: toast che các nút ngôn ngữ ở đầu trang (chuyển xuống góc dưới phải, <782px full-width), chân trang còn credit cũ (nay "Lingolane <phiên bản> · Developed by @tannguyen", dịch được), nút đóng toast hiện khi tắt JS.
- Chưa kiểm: CI PHP 8.1/8.4 (chưa có remote), viền focus khi bấm chuột trên nút của WP.

**2026-10-22 · P1 Danh sách Trang/Bài viết dạng cây (kế hoạch docs/plans/2026-10-22-p1-tree-list.md, nhánh feat/p1-tree-list):**
- Task 1–6: truy vấn dạng cây và nạp nhóm với ≤ 3 SQL; dòng bản dịch do `WP_Posts_List_Table` dựng trong template; dòng thiếu bản dịch và nhãn tiến độ; `tree.js` gập/mở từng nhóm hoặc tất cả, nhớ trạng thái và giữ nhóm sau Sửa nhanh; giữ phân cấp trang và chuyển về danh sách phẳng khi lọc.
- Task 7 phần chạy offline: thêm `bin/tree-fixture.sh` để build/cài plugin và chuẩn bị dữ liệu mẫu có khóa meta `_lingolane_fixture`, tái sử dụng ID; bật thêm fr, tạo trang/bài viết en–vi, bản nháp, trang con, bản dịch mồ côi và trang chưa gán ngôn ngữ. Script có chặn nhầm host và thùng rác bị tắt; chỉ kiểm cú pháp, chưa chạy trên site. Sinh lại `.pot`, thêm đủ 7 bản dịch vi mới và biên dịch `.mo`; README thêm dòng về danh sách cây.
- Kiểm chứng offline: PHPUnit 117 test/818 assertion đạt trên cả PHP 8.2 và 7.4; `composer lint` đạt, phpcs 0 lỗi/26 cảnh báo; `node --check` đạt cho `admin.js` và `tree.js`; `bash -n` và `php -l` đoạn PHP fixture đạt. `bin/build.sh` đạt; `unzip -l` xác nhận zip có `lingolane/admin/class-lingolane-tree.php` và `lingolane/assets/tree.js`.
- QA trình duyệt (1280px và 375px trên check.local): ĐẠT — cột Ngôn ngữ sau Tiêu đề; cây gập/mở từng nhóm và Mở tất cả/Gập tất cả chuẩn xác; nhớ trạng thái qua localStorage sau khi tải lại trang; gập nhóm tự động bỏ tick checkbox bài con để bulk action không gửi nhầm bài ẩn; bản dịch trong Thùng rác không bị tính là "chưa dịch"; Quick Edit gập/mở cùng nhóm và giữ vị trí sau khi lưu/huỷ; tìm kiếm mở nhóm và tô nổi dòng khớp; lọc ngôn ngữ tự phẳng danh sách; ở 375px không cuộn ngang cấp trang; 0 lỗi console.
- Kiểm chứng tổng thể: chạy fixture hai lần (lần 1 tạo 15 bài, lần 2 tái sử dụng 100% không sinh trùng bài); PHPUnit đạt 117 test/820 assertion trên cả PHP 8.2 và 7.4; phpcs 0 lỗi, 26 cảnh báo; Plugin Check 0 lỗi; zip đóng gói đầy đủ mã cây và tài nguyên. Đã sửa lỗi thẻ th scope="row" của WP 7.1+ cho selector tiêu đề.
