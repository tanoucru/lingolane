# Lingolane

Plugin đa ngôn ngữ nhẹ cho WordPress, tách từ dự án Teachme. Phát triển bởi @tannguyen. Phát hành hai kênh: bản lite trên WordPress.org và bản phát hành riêng.

- Mọi ngôn ngữ WordPress có bản dịch (131 ngôn ngữ với WP 7.1, danh mục lấy từ API WordPress.org, kèm 44 ngôn ngữ dự phòng khi mất mạng; bỏ các biến thể như `de_DE_formal`). Thêm ngôn ngữ nào thì chỉ tải gói dịch WordPress/theme/plugin của locale đó (`Lingolane_Packs`); bản cập nhật về sau đi theo cơ chế cập nhật bản dịch của WordPress cho mọi ngôn ngữ của site
- Nhóm bản dịch (translation group) cho bài viết, trang, term
- Danh sách Trang/Bài viết dạng cây: gập/mở nhóm bản dịch, nhớ trạng thái, hiển thị tiến độ và dòng tạo bản dịch còn thiếu; giữ nhóm sau Sửa nhanh
- Đường dẫn theo thư mục ngôn ngữ (`/vi/…`), ngôn ngữ mặc định không tiền tố
- Dịch chuỗi giao diện (theme, plugin), nhập/xuất CSV
- hreflang / x-default, nút chuyển ngôn ngữ (`[lingolane_switcher]`, widget, menu `#lingolane-switcher`)
- Viết lại link nội bộ, thống kê tiến độ dịch
- Giao diện quản trị "SaaS sáng": token màu/kích thước trong `assets/admin.css`, icon SVG nội tuyến (`Lingolane_UI::icon()`), minh hoạ cho màn hình trống, toast cho thông báo; không font ngoài, không CDN, tôn trọng `prefers-reduced-motion`. Icons: [Lucide](https://lucide.dev) (ISC, `assets/LICENSE-lucide.txt`)

Mã plugin nằm ở `lingolane/`. Yêu cầu WP ≥ 6.2, PHP ≥ 7.4. Giấy phép GPL-2.0-or-later.

## Định danh
Slug và text-domain `lingolane`; hàm/option/bảng/filter `lingolane_`; hằng `LINGOLANE_`; class `Lingolane_*`; shortcode `[lingolane_switcher]`.

## Hook mở rộng
| Filter | Mặc định | Ý nghĩa |
|---|---|---|
| `lingolane_translatable_post_types` | `post`, `page` | Loại nội dung dịch được |
| `lingolane_shared_page_slugs` | `[]` | Slug trang dùng chung mọi ngôn ngữ (không đổi URL) |
| `lingolane_shared_post_types` | `[]` | Post type dùng chung mọi ngôn ngữ |
| `lingolane_shared_taxonomies` | `[]` | Taxonomy dùng chung |
| `lingolane_gated_post_types` | `[]` | Bản chưa dịch của loại này bị loại khỏi hreflang/sitemap |
| `lingolane_language_catalog_source` | danh sách WordPress.org + dự phòng | Danh mục locale ⇒ [tên bản địa, tên tiếng Anh] cho bộ chọn ngôn ngữ |
| `lingolane_pre_install_language_pack` | `null` | Trả `true`/`WP_Error` để bỏ qua việc tải gói dịch (test, site không cho ghi tệp) |

Mặc định ngôn ngữ: English (gốc, không tiền tố) + Tiếng Việt; đổi trong Cài đặt.

## Ngôn ngữ giao diện
Chuỗi gốc bằng tiếng Anh, text-domain `lingolane`. `lingolane/languages/` có `lingolane.pot`, `lingolane-vi.po/.mo`. Sinh lại mẫu: `wp i18n make-pot lingolane lingolane/languages/lingolane.pot --slug=lingolane --domain=lingolane --skip-js --exclude=tests,languages`. Biên dịch `.mo`: `python3 bin/make-mo.py` (build.sh tự gọi).

## Build
```bash
bin/build.sh   # kiểm phiên bản khớp 3 nơi, lint PHP, ra dist/lingolane-<version>.zip (không gồm tests/)
bin/ui-preview.sh   # build, cài lên site Local `check`, dựng dist/ui-preview/<tab>.html cho 6 tab quản trị
python3 -m http.server 8765 --directory dist/ui-preview   # xem không cần đăng nhập
```

## Nâng cấp từ bản tiền phát hành `tml_` (Teachme)
Khi kích hoạt, plugin tự đổi tên bảng `tml_translations`/`tml_strings` và sao chép option `tml_settings` sang tên mới (không mất dữ liệu). `includes/compat-legacy.php` giữ alias `TML_*`, `tml_get_translation_url()`, shortcode `[tml_switcher]` và menu `#tml-switcher`/`{{tml}}` để theme/plugin Teachme chạy tiếp; tắt bằng `define( 'LINGOLANE_NO_LEGACY', true )`. Phải **tắt bản cũ trước khi bật bản mới** (hai bản không chạy song song).

Mu-plugin cho Teachme (thay các danh sách từng gắn cứng):
```php
$cpts = array( 'listing','science','language','computer-science','business','business-english','education','music','art-crafts','sport','career-coaching' );
add_filter( 'lingolane_shared_page_slugs', fn() => array( 'create','edit','remove','my-listings','my-favorites','profile','reset-password','change-password','logout','transactions','payment','invoices','my-watchdogs','package-information','listing-slider','form-redirect','report-spam-abuse-or-inappropriate-content' ) );
add_filter( 'lingolane_shared_post_types', fn() => $cpts );
add_filter( 'lingolane_gated_post_types', fn() => $cpts );
add_filter( 'lingolane_shared_taxonomies', fn() => array( 'listing_categories','locations' ) );
add_filter( 'lingolane_translatable_post_types', fn( $t ) => array_unique( array_merge( $t, array( 'listing','science','language','computer-science','faq' ) ) ) );
```
`is_tutor()`/`translated_tutor()` đã đổi thành `is_gated()`/`translated_gated()`.

## Kiểm thử và chất lượng
Bộ test PHPUnit (80 test, 400 assertion) nằm ở `tests/phpunit/`, mỗi file một mảng chức năng: nhóm bản dịch, router, URL/hreflang, bản ghi dùng chung, ngôn ngữ mặc định, quản trị, chuỗi giao diện, danh mục ngôn ngữ, icon và token giao diện. Mỗi test chạy trong một transaction của DB test và tự khôi phục.

```bash
composer install          # PHPUnit 9.6, thư viện test WordPress, WPCS
composer lint             # phpcs: lỗi làm hỏng build, cảnh báo chỉ liệt kê
composer test             # PHPUnit (cần DB test, xem dưới)
```
**Chạy bằng wp-env (cần Docker):**
```bash
npx @wordpress/env start
npx wp-env run tests-cli --env-cwd=wp-content/plugins/lingolane-dev vendor/bin/phpunit
```
**Chạy không cần Docker:** tạo một DB riêng (thư viện test **xoá toàn bộ bảng** của DB nó dùng, không dùng DB của site thật), viết `wp-tests-config.php` trỏ tới DB đó và thư mục WordPress, rồi đặt `WP_PHPUNIT__TESTS_CONFIG=/đường/dẫn/wp-tests-config.php` trước khi chạy `composer test`.

CI (`.github/workflows/ci.yml`) chạy khi push lên `main` và với mọi Pull Request: phpcs, `php -l` trên PHP 7.4/8.1/8.4, PHPUnit qua wp-env (PHP 7.4 với WP 6.2 tối thiểu, PHP 8.1 và 8.4 với WP mới nhất; 8.4 là thử nghiệm), Plugin Check (`bin/plugin-check-gate.py`: lỗi làm hỏng build, cảnh báo thì không) và đóng gói zip.

## Kế hoạch phát hành
Xem [docs/RELEASE-PLAN.md](docs/RELEASE-PLAN.md). Phân tích Polylang/TranslatePress và các đề xuất C1–C14: [docs/COMPETITOR-ANALYSIS.md](docs/COMPETITOR-ANALYSIS.md).
