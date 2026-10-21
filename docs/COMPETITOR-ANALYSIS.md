# Phân tích đối thủ: Polylang và TranslatePress

Ngày: 2026-10-21. Mục đích: tìm điều Lingolane nên học, gồm kiến trúc và dữ liệu từ Polylang, trải nghiệm người dùng từ TranslatePress.

**Nguồn đã đọc (mã thật, không dựa trí nhớ):**
- Polylang `4.0.0-dev`, nhánh `master` của `github.com/polylang/polylang`: `src/translatable-object.php`, `src/translated-object.php`, `src/Model/Languages.php`, `src/mo.php`, `src/query.php`, `src/olt-manager.php`, `src/translate-option.php`, `src/modules/wpml/wpml-config.php`, `src/modules/sync/sync-post-metas.php`, `src/frontend/choose-lang.php`, cùng cây thư mục `src/`.
- TranslatePress `3.3.8`, `plugins.svn.wordpress.org/translatepress-multilingual/trunk`: `includes/queries/class-query.php`, `class-translation-render.php`, `class-translation-manager.php`, `class-translation-memory.php`, `class-onboarding.php`, `class-language-switcher-v2.php`, `class-preferred-user-language.php`, cùng tài liệu "Translation Editor" trên translatepress.com.
- Lingolane: `lingolane/includes/*`, `lingolane/admin/class-lingolane-metabox.php` tại commit `fca2b0a`.

**Giấy phép:** Polylang dùng GPL-3.0-or-later, còn Lingolane dùng GPL-2.0-or-later. Chép mã Polylang vào thì bản phân phối Lingolane phải lên GPLv3. TranslatePress dùng GPL-2.0+, nhưng vẫn nên tự viết. **Chỉ học ý tưởng và tự viết mã**, không sao chép.

## 1. Polylang: kiến trúc và dữ liệu

| Khía cạnh | Polylang | Lingolane (`fca2b0a`) |
|---|---|---|
| Ngôn ngữ của bài/term | Gán qua taxonomy `language` và `term_language` (bảng `term_relationships` của lõi). Locale, rtl, cờ lưu serialize trong `description` của term (`Model/Languages.php::build_metas`). Danh sách ngôn ngữ cache trong transient `pll_languages_list` | Bảng riêng `lingolane_translations` (`element_type`, `element_id`, `trid`, `language_code`, `source_language_code`), cùng mô hình với `icl_translations` của WPML |
| Nhóm bản dịch | Mỗi nhóm là một term trong `post_translations`/`term_translations`, `description` = `{lang: id}` (`translated-object.php::save_translations`) | Gom theo `trid` và **có lưu ngôn ngữ nguồn**; Polylang không có thông tin này |
| Lọc truy vấn | `tax_query` theo `term_taxonomy_id` (`query.php::set_language`), ra một INNER JOIN có index. Mọi nội dung đều phải có ngôn ngữ | `EXISTS` tương quan với `CONCAT('post_', post_type) COLLATE …`, cộng `OR NOT EXISTS` vì nội dung chưa gán được coi là ngôn ngữ nguồn (`class-lingolane-query.php::condition`) |
| Cache | Nạp sẵn cho nhiều object một lượt (`update_object_term_cache` → `wp_cache_add_multiple`), hết hạn theo `wp_cache_set_last_changed` | Mỗi phần tử một truy vấn, `wp_cache_set(..., 60)` (`class-lingolane-elements.php::row`), dễ thành N+1 trên trang lưu trữ, hreflang, switcher |
| Dịch chuỗi | Term meta `_pll_strings_translations` trên term ngôn ngữ (`mo.php`, từ 3.4) | Bảng `lingolane_strings`, filter `gettext`, nạp cả bảng của một ngôn ngữ vào cache. Ngang nhau |
| Tạo bản dịch | Tách **copy** (lúc tạo) và **sync** (giữ đồng bộ taxonomy, ảnh đại diện, template, thứ tự, parent). Filter `pll_copy_post_metas`; `pll_translate_post_meta` đổi `_thumbnail_id` sang ảnh đã dịch | `duplicate_post()` chép toàn bộ meta một lần (trừ `_edit_lock`, `_edit_last`, `_wp_old_slug`), ánh xạ term sang bản dịch. Chưa có filter, chưa có sync |
| Kiểu URL | Lớp chiến lược `links-default`/`directory`/`subdomain`/`domain` | Chỉ thư mục `/vi/` |
| Cấu hình | Registry có kiểu (`src/Options/*`: Boolean/List/Map tự sanitize), mở qua REST `V1/Settings` | Mảng `lingolane_settings`, xử lý trong `class-lingolane-admin.php` (1.064 dòng) |
| Hệ sinh thái | Đọc `wpml-config.xml` của theme/plugin; có lớp tương thích WPML API (`wpml-api.php`, `wpml-legacy-api.php`) | Không có |
| Phát hiện ngôn ngữ | Header `Accept-Language` cộng cookie `pll_language`, không đặt cookie bằng JS khi có plugin cache (`choose-lang.php`) | Không có |
| Textdomain | `olt-manager.php` hoãn `load_textdomain` cho tới khi biết ngôn ngữ của request | Chỉ lọc `locale`. File `.mo` nạp sớm có thể sai ngôn ngữ (chưa kiểm) |
| Module khác | Sitemap lõi đa ngôn ngữ, block switcher (cả trong Navigation), REST `lang`, Site Health, cache compat, wizard | Yoast sitemap/canonical; switcher dạng shortcode, mục menu `#lingolane-switcher`, widget |

## 2. TranslatePress: trải nghiệm người dùng

**Kiến trúc (không nên theo):** TranslatePress dịch HTML sau khi render. `ob_start` → tách chuỗi → bảng `trp_dictionary_{gốc}_{đích}` (`original`, `translated`, `status`, `block_type`, `original_id`), cộng bảng `trp_original_strings`, `trp_original_meta` và các bảng gettext. Hằng trạng thái trong `class-query.php`: 0 chưa dịch, 1 máy dịch, 2 người duyệt, 3 tương tự, 4 lấy từ file ngôn ngữ. Cách này tốn tài nguyên mỗi request. Bản dịch bám theo câu chữ nên sửa bản gốc là mất. Lingolane đi theo hướng bản dịch là một bài riêng, đúng hơn cho SEO và cho hiệu năng.

**Ý tưởng UX nên lấy:**
1. **Trình dịch trực quan.** Nút "Translate Site/Page" trên admin bar (`add_shortcut_to_translation_editor`) mở màn hình chia đôi: thanh bên và iframe xem trước (`?trp-edit-translation=preview`). Di chuột lên chữ nào thì hiện bút để sửa. Danh sách chuỗi chia nhóm (thường, gettext, meta, ảnh). Có "View as" theo vai trò hoặc khách chưa đăng nhập, có chuyển chuỗi trước/sau, có gộp nhiều chuỗi thành "translation block".
2. **Trạng thái mỗi bản dịch** (máy dịch hay người duyệt).
3. **Bộ nhớ dịch** (`class-translation-memory.php`): gợi ý câu tương tự bằng `MATCH … AGAINST`.
4. **Wizard cài đặt** (`class-onboarding.php`): welcome → ai-api-key → languages → switcher → autotranslation → addons → finish.
5. **Nút chuyển ngôn ngữ nổi** (`render_floater` ở `wp_footer`, chỉnh vị trí, cờ, tên, có xem trước). Chạy trên mọi theme mà không cần cấu hình.
6. **Ngôn ngữ ưa thích của người dùng** (user meta `trp_language`, "luôn dùng ngôn ngữ này", email WooCommerce theo ngôn ngữ người nhận).
7. **Dịch phản hồi REST** theo cấu hình route `wp/v2/*` (`trp_rest_api_translation_config`).
8. **Dịch máy có hạn mức và nhật ký** (`class-machine-translator*.php`).

## 3. Đề xuất cho Lingolane

Mã đề xuất (C1…C14) được dẫn trong `RELEASE-PLAN.md`.

### P1, đưa vào M3 (tương thích website bất kỳ)
| Mã | Đề xuất | Học từ | Lý do |
|---|---|---|---|
| C1 | Đọc `wpml-config.xml` của theme/plugin đang bật: custom field copy/dịch, CPT, taxonomy, `admin-texts`, xpath của block | Polylang | Hàng nghìn plugin (Elementor, WooCommerce, ACF, Yoast…) có sẵn file này. Schema Lingolane giống WPML nên làm rẻ |
| C2 | Shim WPML API (`wpml_object_id`, `icl_object_id`, `wpml_current_language`, `wpml_element_language_details`), chỉ nạp khi không có WPML/Polylang | Polylang | Theme/plugin đang gọi các hàm này chạy được ngay. Đi cùng việc phát hiện xung đột (R6) |
| C3 | Viết lại truy vấn lọc: subquery không tương quan `ID IN (SELECT element_id … WHERE language_code=%s AND element_type IN (…))`, bỏ `CONCAT`/`COLLATE`, thêm index `(language_code, element_type, element_id)`. Benchmark 10k bài trước và sau | Polylang (JOIN có index) | R7. Hiện mỗi dòng phải chạy subquery, với nhánh `OR NOT EXISTS` |
| C4 | Nạp cache theo lô ở `the_posts`/`get_terms`; hết hạn cache theo `last_changed` thay cho TTL 60 giây | Polylang | Tránh N+1 ở trang lưu trữ, hreflang, switcher |
| C5 | Filter `lingolane_copy_post_metas` (danh sách loại trừ hoặc cho phép) và dịch `_thumbnail_id` sang ảnh đã dịch khi tạo bản dịch | Polylang | Meta của page builder, SEO, ACF cần kiểm soát được |
| C6 | Sitemap lõi `wp-sitemap.xml` đa ngôn ngữ | Polylang | B5 / M3 SEO |
| C7 | Block `lingolane/language-switcher` (dùng được trong block Navigation) và switcher nổi tuỳ chọn ở `wp_footer` | Polylang + TranslatePress | R3, theme khối |
| C8 | Kiểm thứ tự nạp textdomain theo ngôn ngữ của request. Nếu sai, hoãn `load_textdomain` như `olt-manager` | Polylang | Chuỗi của theme/plugin có thể ra sai ngôn ngữ |

### P2, đưa vào M4 (trải nghiệm lần đầu)
| Mã | Đề xuất | Học từ |
|---|---|---|
| C9 | Wizard ghép hai bên: ngôn ngữ → kiểu URL → **gán ngôn ngữ mặc định cho nội dung cũ** → trang chủ từng ngôn ngữ → vị trí switcher (có xem trước) → "Dịch trang đầu tiên" | Polylang (bước untranslated contents, home page) + TranslatePress (switcher, finish) |
| C10 | Nút "Dịch trang này" trên admin bar: thanh bên ở frontend liệt kê bản dịch của trang (tạo/sửa) và **các chuỗi gettext thực sự xuất hiện trên trang**, ghi lại khi bật chế độ sửa, để dịch ngay tại chỗ | TranslatePress (trình dịch trực quan, chuyển thể cho mô hình theo bài) |
| C11 | Trạng thái **"bản dịch lỗi thời"** khi bài gốc được sửa sau bản dịch, hiện ở cột danh sách, metabox, thống kê. Chuỗi có thêm cột `status` (máy/người) | TranslatePress (status). Polylang và TranslatePress đều không có trạng thái lỗi thời |
| C12 | Registry cấu hình có schema, dùng cho trang cài đặt, nhập/xuất cấu hình, sau này cho REST | Polylang (`src/Options`) |

### P3, bản 1.1 trở đi
| Mã | Đề xuất | Học từ |
|---|---|---|
| C13 | Module sync (taxonomy, ảnh đại diện, template, thứ tự, parent); dịch option (tên site, khẩu hiệu, theme mod; giữ bản dịch khi bản gốc đổi); kiểu URL domain/subdomain qua lớp chiến lược; REST `lang` | Polylang |
| C14 | Phát hiện ngôn ngữ trình duyệt (chỉ ở `/`, an toàn với cache); ngôn ngữ ưa thích của người dùng; bộ nhớ dịch; dịch máy có trạng thái máy/người (cần Tan duyệt chi phí và quyền riêng tư) | Polylang + TranslatePress |

## 4. Lợi thế sẵn có của Lingolane (cần giữ)
- Lưu ngôn ngữ nguồn (`source_language_code`), là nền cho C11.
- Nội dung "dùng chung" (shared post type, taxonomy, page slug), điều Polylang không có sẵn.
- Một bảng gọn, cùng mô hình WPML. Làm C1, C2 và công cụ nhập từ WPML (M4) dễ hơn.
- Ghi bằng `GET_LOCK` và `ON DUPLICATE KEY`, có ràng buộc một ngôn ngữ một phần tử trong mỗi nhóm.

## 5. Chưa kiểm chứng
- Kế hoạch truy vấn MySQL thật của cách lọc hiện tại và của C3 (chưa benchmark).
- Thứ tự nạp `locale`/textdomain trên site thật (C8).
- Bảng `lingolane_translations` không đi theo file xuất WXR của lõi. Cần tính tới khi làm công cụ nhập/xuất.
