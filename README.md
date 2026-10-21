# TML Multilingual

Plugin đa ngôn ngữ nhẹ cho WordPress, tách từ dự án Teachme. Phát triển bởi @tannguyen.

- Nhóm bản dịch (translation group) cho bài viết, trang, term
- Đường dẫn theo thư mục ngôn ngữ (`/vi/…`), ngôn ngữ mặc định không tiền tố
- Dịch chuỗi giao diện (theme, plugin)
- hreflang / x-default, sitemap, nút chuyển ngôn ngữ (`[tml_switcher]`, widget, menu)
- Viết lại link nội bộ, thống kê tiến độ dịch

Mã plugin nằm ở `tml-multilingual/` (zip thư mục này để cài). Yêu cầu WP ≥ 6.2, PHP ≥ 7.4.

## Hook mở rộng (tách khỏi Teachme)
| Filter | Mặc định | Ý nghĩa |
|---|---|---|
| `tml_translatable_post_types` | `post`, `page` | Loại nội dung dịch được |
| `tml_shared_page_slugs` | `[]` | Slug trang dùng chung mọi ngôn ngữ (không đổi URL) |
| `tml_shared_post_types` | `[]` | Post type dùng chung mọi ngôn ngữ |
| `tml_shared_taxonomies` | `[]` | Taxonomy dùng chung |
| `tml_gated_post_types` | `[]` | Bản chưa dịch của loại này bị loại khỏi hreflang/sitemap |

Mặc định ngôn ngữ: English (gốc, không tiền tố) + Tiếng Việt; đổi trong Cài đặt.

## Dùng lại trong Teachme
Teachme đang chạy bản cũ trong `wp-plugins/teachme-multilingual` (chưa đổi). Khi chuyển sang bản này, thêm mu-plugin:
```php
add_filter( 'tml_shared_page_slugs', fn() => array( 'create','edit','remove','my-listings','my-favorites','profile','reset-password','change-password','logout','transactions','payment','invoices','my-watchdogs','package-information','listing-slider','form-redirect','report-spam-abuse-or-inappropriate-content' ) );
$cpts = array( 'listing','science','language','computer-science','business','business-english','education','music','art-crafts','sport','career-coaching' );
add_filter( 'tml_shared_post_types', fn() => $cpts );
add_filter( 'tml_gated_post_types', fn() => $cpts );
add_filter( 'tml_shared_taxonomies', fn() => array( 'listing_categories','locations' ) );
add_filter( 'tml_translatable_post_types', fn( $t ) => array_unique( array_merge( $t, array( 'listing','science','language','computer-science','faq' ) ) ) );
```
Lưu ý: class `TML_Shared::is_tutor()` / `translated_tutor()` đã đổi tên thành `is_gated()` / `translated_gated()`; plugin teachme-directory/seo gọi `TML_Languages`, `TML_Urls`, `TML_Switcher` nên không ảnh hưởng.

## Kiểm thử
Chạy trên site Local/`.test`: `wp eval-file wp-content/plugins/tml-multilingual/tests/test-*.php` (chỉ chạy được trên host `.local`/`.test`).

## Việc còn lại trước khi phát hành
1. Chạy bộ test trên site Local sạch (chưa chạy ở bước tách).
2. Soát chuỗi giao diện gốc tiếng Việt → nạp `.pot`, đặt chuỗi gốc tiếng Anh.
3. Tạo repo GitHub private `tanoucru/wp-multilingual` (chờ Tan duyệt), đóng zip, đặt phiên bản 1.0.0.
