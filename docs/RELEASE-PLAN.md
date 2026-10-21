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
R1 Multisite (không có mã xử lý). R2 Site cài trong thư mục con, permalink "Plain", HTTPS/proxy. R3 Theme khối (FSE) và block Navigation (switcher hiện hỗ trợ `wp_nav_menu`/widget/shortcode). R4 Page builder (Elementor, Divi, Bricks), ACF/meta tuỳ chỉnh, WooCommerce. R5 Plugin cache (WP Rocket, LiteSpeed, Cloudflare) với URL theo ngôn ngữ. R6 Chạy cùng Polylang/WPML gây xung đột. R7 Hiệu năng truy vấn lọc theo ngôn ngữ trên site lớn (10k+ bài). R8 Tiền tố `tml_` chỉ 3 ký tự, dễ trùng khi lên WordPress.org (yêu cầu ≥4). R9 Phiên bản lặp ở 3 nơi (header, `TML_VERSION`, readme). R10 Chưa kiểm có gọi HTTP ra ngoài hay không (ảnh hưởng chính sách riêng tư).

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
