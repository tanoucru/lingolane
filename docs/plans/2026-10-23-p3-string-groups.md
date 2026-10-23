# P3 Nhóm vị trí và Quét trang — Implementation Plan

**Goal:** Tìm và dịch chữ theo vị trí trên frontend, quét các trang chính và tô sáng đúng lần xuất hiện; nói rõ chữ nào chưa dịch tự động được.

**Architecture:** Giữ bảng chuỗi và khóa domain + hash(context, source); bảng occurrences riêng ghi nhiều vị trí của cùng một bản dịch. Adapter option/menu/media/gettext và ngoại lệ hẹp cho block áp dụng bản dịch tại nguồn, term vẫn dùng `tax_*`. Quét trong trình duyệt đã đăng nhập khi chủ động bật; không proxy URL hay dịch HTML toàn trang.

**Tech Stack:** WordPress ≥ 6.2, PHP ≥ 7.4, JS/CSS thuần, PHPUnit + wp-phpunit, token/icon P2.

**Spec:** `docs/specs/2026-10-22-ux-translation-design.md` §4 P3, §2–3 và §6.

**Trạng thái:** Đã cập nhật sau vòng review độc lập (2026-10-23). Đã khắc phục 3 Blocker (Text Node Tokenizer PHP 7.4/WP 6.2, Identity sync giữa Locations & Strings, trần tích lũy Occurrences) và 5 Gợi ý kỹ thuật. Worktree `lingolane-p3`, branch `feat/p3-string-groups`, base `396515b`. P1 follow-ups nằm riêng; không merge nhánh đó trong kế hoạch này.

## Global Constraints

- WP 6.2 trở lên, PHP 7.4 trở lên, cả theme cổ điển và theme khối; multisite ngoài phạm vi.
- GPL-2.0+; credit `@tannguyen`; không CDN, không tài nguyên ngoài, không thêm bước build.
- UI tiếng Anh, bọc hàm dịch, cập nhật `.pot` và bản `vi` theo quy ước M1.
- Mọi thao tác ghi kiểm quyền và nonce. SQL có giá trị động qua `prepare()` hoặc API `$wpdb` có format; DDL chỉ dùng identifier từ prefix WordPress.
- Plugin Check 0 lỗi, phpcs 0 lỗi; không tăng baseline 26 cảnh báo nếu chưa giải thích và được chấp nhận.
- PHPUnit không gọi mạng thật; kiểm PHP 7.4/8.2 tại máy, không bỏ ma trận CI 7.4/8.1/8.4.
- Khách không nhận script quét, marker, manifest nguồn hay nonce; không ghi DB khi khách đọc trang. CSS chỉ trong vùng Lingolane.
- Không dịch HTML toàn trang, không sửa nội dung bài qua render_block, không mở wp_template_part thành post dịch độc lập.
- `lingolane_translate` dùng cho dịch; `manage_options` giữ cài đặt/key. Không tạo role người dịch trong P3.
- P4 sửa tại chỗ và P5 AI ngoài phạm vi. Không thêm nhà cung cấp AI hay trạng thái bản dịch máy trong đợt này.
- Không commit/push/deploy hoặc đọc bí mật khi chưa nghiệm thu; nghiệm thu rồi commit từng task. Check.local cần phê duyệt riêng trước cài đè.

## Review Focus

1. Chuỗi trùng vị trí nhưng khác domain/context: chia sẻ đúng, không nhập nhầm nghĩa; Task 1/7.
2. Dữ liệu riêng, form và URL độc hại: scan không thu bí mật hoặc tin identity do client tự khai; Task 6.
3. Menu riêng theo ngôn ngữ: không bị adapter dịch đè hoặc rewrite URL hai lần; Task 3/5.
4. Block lồng nhau, widget trong bài, HTML lỗi, DOM đổi sau scan: không thay hoặc highlight nhầm; Task 5/6.
5. Upgrade lặp, thêm ngôn ngữ, xóa chuỗi và cache cũ: không mất bản dịch hoặc tạo tiến độ giả; Task 1/7.

## File map và hợp đồng dữ liệu

File mới trong `lingolane/includes/`:
- `class-lingolane-string-locations.php`: occurrences, phân trang, thống kê nhóm.
- `class-lingolane-string-sources.php`: registry và adapter option/menu/media.
- `class-lingolane-string-blocks.php`: phạm vi template/widget và thay text node đã đăng ký.

File mới trong `lingolane/admin/`:
- `class-lingolane-string-scan.php`: phiên scan, danh sách trang, manifest và endpoints.
- `class-lingolane-string-terms.php`: danh sách/sửa nhanh term dịch, không ghi term vào bảng strings.

Assets mới: `assets/string-scan.js`, `assets/string-scan.css`, `assets/string-groups.js`. Tái dùng admin.css và P2 UI/toast. Bootstrap tại `lingolane/lingolane.php`.

**Identity:** array `{domain:string, source:string, context:string}`. Hash luôn tính server bằng `Lingolane_Strings::hash()`, không dùng ID dòng của một ngôn ngữ làm khóa chung.

**Occurrence:** array `{group:string, subgroup:string, provider:string, object_id:int, field:string, page_path:string, locator:string, supported:bool}`. Locator chỉ giúp tìm DOM, không quyết định quyền hay nơi ghi. page_path là path nội bộ bỏ query/fragment; không lưu cookie, giá trị form hoặc HTML toàn trang.

**Nhóm:** `site`, `header`, `menu`, `footer`, `widgets`, `theme`, `plugin`, `terms`, `media`, `other`. Spec gộp Terms và Media ở mục 8; UI tách hai mục vì hai mô hình dữ liệu. Plugin subgroup theo domain, nhãn tên plugin nếu xác định được. Menu subgroup theo location, menu chưa gán vào `unassigned`.

**Thực thi:** Tasks 1→8 tuần tự vì dùng chung bootstrap/admin. Kiểm quyết định DB/quyền và bảo mật. Mỗi task red/green + review trước chuyển task. Không triển khai trước khi kế hoạch qua review độc lập và được duyệt.

## Task 1: Kho vị trí và migration

**Files:** Create `lingolane/includes/class-lingolane-string-locations.php`, `tests/phpunit/test-string-locations.php`; Modify `lingolane/includes/class-lingolane-install.php`, `lingolane/includes/class-lingolane-strings.php`, `lingolane/lingolane.php`.

**Interfaces:** `Lingolane_String_Locations::record(array $identity,array $occurrence): bool|WP_Error`; `groups(string $language): array`; `page(string $group,string $subgroup,string $language,string $status,string $keyword,int $page): array{items:array,total:int}`; `forget(string $domain,string $hash): void`; `invalidate(): void`. Kiểu mô tả ở đây là hợp đồng, không dùng PHP 8 union syntax trong code PHP 7.4.

- [ ] Viết test `test_identity_shared_across_locations`: cùng identity tại header/footer → mỗi nhóm total=1, tổng distinct=1; save một bản dịch → cả hai nhóm own=1. `test_domain_and_context_stay_distinct`: đổi domain/context → identity khác. `test_upgrade_idempotent`: activate hai lần giữ bản dịch cũ. `test_failed_schema_keeps_old_version`: tạo bảng lỗi không tăng DB version.
- [ ] Chạy PHPUnit `--filter String_Locations`; FAIL do schema/interface chưa có. Ghi assertion thực, không chấp nhận lỗi cấu hình harness thay cho red test.
- [ ] Implement bảng `{$wpdb->prefix}lingolane_string_locations`, DB_VERSION `0.5.0`: id bigint PK; domain varchar(100); source_hash char(32); occurrence_hash char(64); group_key varchar(20); subgroup varchar(100); provider varchar(30); object_id bigint; field varchar(100); page_path text; locator text; supported tinyint; last_seen datetime. UNIQUE `(domain,source_hash,occurrence_hash)`, index `(group_key,subgroup)`, composite index `(group_key,domain,source_hash)` để tối ưu hóa truy vấn COUNT DISTINCT identity theo nhóm. occurrence_hash là SHA-256 các trường định vị canonical, không gồm thời gian. Chỉ nâng version sau xác nhận đủ ba bảng; giữ chính sách không xóa dữ liệu khi uninstall.
- [ ] Đồng bộ Identity giữa Locations và Strings: Khi chuỗi mới được phát hiện từ scan/adapter (chưa có trong `lingolane_strings`), hàm `Lingolane_Strings::save()` và endpoint AJAX chấp nhận tuple Identity `{domain, source, context, language_code, translation}` và thực thi `INSERT ... ON DUPLICATE KEY UPDATE` trực tiếp vào `lingolane_strings`. Không phụ thuộc vào việc chuỗi đã có sẵn ID dòng hay chưa.
- [ ] Backfill 200 identity/lô qua admin request có quyền, cursor riêng; theme domain đang dùng → Theme, còn lại → Khác, không sửa bản dịch. Khi backfill chưa xong, query có fallback nhóm cho identity chưa có occurrence. LEFT JOIN bản dịch theo ngôn ngữ, nên ngôn ngữ mới vẫn thấy missing dù chưa có dòng tương ứng.
- [ ] Đếm distinct identity: total/own/inherited/missing/unsupported/percent; percent=floor(100*(own+inherited)/total), total=0 → 0. Unsupported vẫn thuộc total nhưng hiện riêng. Inherited xử lý theo lô, không dùng LIMIT 5000 làm sai tổng. Cache chỉ phía admin; invalidate khi save/delete/import/scan, đổi ngôn ngữ/theme/catalog. Test `test_new_language_missing_rows`, `test_empty_group_zero_percent`, `test_counts_above_5000`, `test_save_delete_invalidates_counts`.
- [ ] Chạy tests mới + test-strings.php; PASS. Review rồi commit `feat(strings): track locations without duplicating translations`.

## Task 2: Quyền dịch tách khỏi cài đặt

**Files:** Modify `lingolane/includes/class-lingolane-install.php`, `lingolane/admin/class-lingolane-admin.php`, `lingolane/admin/class-lingolane-strings-admin.php`; Create `tests/phpunit/test-translation-capabilities.php`.

**Interfaces:** `Lingolane_Admin::can_translate(): bool`; `translation_guard(string $nonce): void`. `tabs()` lọc theo quyền, tab mặc định người chỉ có quyền dịch là Strings; guard cài đặt hiện có không đổi.

- [ ] Test `test_translator_opens_and_saves_strings`, `test_translator_cannot_write_settings`, `test_subscriber_and_bad_nonce_rejected`, `test_upgrade_grants_cap_once`. Dùng user chỉ `read + lingolane_translate`, không dựa vào role editor/admin giả lập.
- [ ] Chạy `--filter Translation_Capabilities`; FAIL.
- [ ] Cấp capability cho Administrator lúc activate/upgrade, không cấp lại mỗi request. Top-level menu cho quản trị hoặc người dịch; render kiểm từng tab kể cả URL trực tiếp. Save/import/export chuỗi dùng quyền dịch + nonce; xóa identity dùng manage_options vì ảnh hưởng nguồn chung. Scan và term endpoint dùng quyền dịch. Settings, language installation, API key và handler quản trị khác giữ manage_options; không trả settings nhạy cảm qua localized JS.
- [ ] Chạy tests mới + test-admin.php; PASS cả form thường và AJAX. Review rồi commit `feat(auth): separate translation from site settings`.

## Task 3: Nguồn site, menu và media

**Files:** Create `lingolane/includes/class-lingolane-string-sources.php`, `tests/phpunit/test-string-sources.php`; Modify `lingolane/includes/class-lingolane-strings.php`, `lingolane/includes/class-lingolane-switcher.php`, `lingolane/lingolane.php`.

**Interfaces:** `Lingolane_String_Sources::enumerate(string $provider,int $offset,int $limit): array{items:array,next:?int}`; `resolve(string $provider,int $object_id,string $field): array|WP_Error` trả Identity đáng tin; `translate(array $identity,string $fallback): string`; `menu_is_language_specific(int $menu_id,string $language): bool`. Provider allowlist option/menu/media; registry gettext/block bổ sung ở Tasks 5/6.

- [ ] Test `test_site_title_tagline_keep_original_options`, `test_menu_labels_and_custom_urls`, `test_language_specific_menu_wins`, `test_logo_alt_and_caption`, `test_unknown_option_rejected`, `test_guest_never_registers_sources`. URL javascript phải bị từ chối; URL nội bộ chỉ rewrite tiền tố một lần.
- [ ] Chạy `--filter String_Sources`; FAIL.
- [ ] Option filters chỉ blogname/blogdescription; domain `lingolane-site`, context theo field. Không đoán option copyright của theme: có adapter chứng minh thì dùng, không có thì block/gettext hoặc Khác. Media domain `lingolane-media`, context alt/caption, không sửa attachment gốc; logo alt cùng identity với media alt, thêm occurrence Site. Các object cùng source/context chia sẻ bản dịch theo spec.
- [ ] Menu domain `lingolane-menu`, context label/custom-url. Chỉ thay custom URL của item loại custom; URL post/tax do router xử lý. Khi rewrite custom URL sang ngôn ngữ đích: bóc tách tiền tố ngôn ngữ hiện tại bằng `Lingolane_URLs::strip_slug()` trước khi ghép slug ngôn ngữ mới, tránh lỗi lặp prefix (ví dụ `/fr/en/about`). Menu riêng theo ngôn ngữ được ưu tiên kể cả khi gọi menu trực tiếp, không chỉ location. Đọc nguồn gốc từ DB/meta, không lấy object đã bị filter. Lookup nội bộ không bị allowed_domains gettext vô tình chặn; source language/admin trả gốc. Không ghi DB trong frontend filters.
- [ ] Đăng ký nguồn theo lô qua scan admin; source đổi tạo identity mới, không tự chuyển bản dịch cũ. Escape theo sink, URL chỉ http(s), root-relative hoặc fragment an toàn. Caption theo chính sách HTML của WP. Chạy tests mới + strings/URLs hiện có; PASS. Review rồi commit `feat(strings): translate site menu and media sources`.

## Task 4: Sửa nhanh bản dịch term

**Files:** Create `lingolane/admin/class-lingolane-string-terms.php`, `tests/phpunit/test-string-terms.php`; Modify `lingolane/lingolane.php`.

**Interfaces:** `Lingolane_String_Terms::page(string $taxonomy,string $language,string $keyword,int $page): array{items:array,total:int}`; `save(int $source_id,string $taxonomy,string $language,string $name,string $description): int|WP_Error`; `progress(string $language): array`. AJAX `lingolane_save_term_translation`, nonce `lingolane_terms_ajax`, capability dịch.

- [ ] Test `test_create_and_reuse_translation_term`, `test_taxonomy_enabled_and_public_only`, `test_source_language_immutable`, `test_failed_insert_has_no_mapping`, `test_duplicate_save_creates_one_term`. Bản gốc không đổi, mapping đúng tax/trid; bảng strings không có term giả.
- [ ] Chạy `--filter String_Terms`; FAIL.
- [ ] Dùng Elements::get_translation/get_trid/set và API insert/update term. Verify taxonomy/ID/ngôn ngữ/relation. Tạo khi lưu, không khi liệt kê. Khóa ngắn theo source/tax/language lúc create, kiểm lại mapping sau lấy khóa, release cả khi lỗi; tranh chấp trả lỗi retry. Mapping lỗi không báo thành công; rollback chỉ term request vừa tạo, không xóa term có sẵn. Parent dùng bản dịch nếu có; chưa có để 0 và cảnh báo. Khi một term cha được dịch, tự động cập nhật `parent` cho các term con đã có bản dịch trong cùng ngôn ngữ nếu term con đang có `parent = 0`.
- [ ] Progress theo field: name gốc không rỗng là một đơn vị; description gốc không rỗng là một đơn vị; hoàn tất khi counterpart có giá trị. Chạy tests mới + test-elements.php; PASS. Review rồi commit `feat(strings): edit taxonomy translations in grouped browser`.

## Task 5: Ngoại lệ block trong phạm vi template/widget

**Files:** Create `lingolane/includes/class-lingolane-string-blocks.php`, `tests/phpunit/test-string-blocks.php`; Modify `lingolane/lingolane.php`.

**Interfaces:** `Lingolane_String_Blocks::extract(string $html): array`; `replace(string $html,array $translations): string`; `render(string $content,array $block): string`; `widget(array $instance,array $widget): array`. Registry expose Identity và source descriptor cho Task 6.

- [ ] Test `test_header_footer_template_parts`, `test_nested_blocks_translate_once`, `test_posts_and_navigation_untouched`, `test_widget_block_only_in_widget_area`, `test_unsafe_nodes_and_malformed_html_unchanged`. Chuỗi A không thay trong ABC, href/JSON/input/value không đổi; lỗi parse trả nguyên bản và scan diagnostic.
- [ ] Chạy `--filter String_Blocks`; FAIL.
- [ ] Chỉ output root core/template-part với area header/footer/sidebar đã xác minh và block widget trong vùng widget thực sự. Không mở quyền dựa vào CSS class hoặc tên block con. Template part lồng nhau thay một lần; exclude subtree core/navigation để không đè menu riêng. Classic widget title/text qua adapter instance. Scanner đọc template part bằng API riêng; giữ skip_types của danh sách post.
- [ ] Giải pháp parser khả thi trên PHP 7.4 / WP 6.2 (không cần WP_HTML_Text_Processor chỉ có ở WP 6.6+, không dùng DOMDocument gây méo entity Unicode): Xây dựng một state-machine tokenizer thuần PHP trong `Lingolane_String_Blocks`. Tokenizer duyệt ký tự theo luồng, bóc tách các tag HTML (`<...>` kể cả attributes, comments, CDATA) và thu thập các text node giữa `>` và `<` kèm exact byte offset `(start, length)`. Bỏ qua toàn bộ nội dung của các thẻ đặc biệt (`script`, `style`, `textarea`, `svg`, `code`, `pre`, `noscript`, `template`). Khi thay thế bản dịch: đối chiếu text node sau khi decode entity an toàn; nếu khớp chính xác 100% với Identity đã dịch, escape bản dịch theo ngữ cảnh HTML text và thực hiện substring replacement từ cuối chuỗi ngược lên đầu (reverse offset order) để giữ nguyên vị trí offset của các node phía trước. Không serialize lại DOM, không dùng regex toàn trang.
- [ ] Test `test_guest_has_no_capture_queries_or_markers`: lookup cache, không query/ghi mỗi node; untranslated giữ nguyên. Chạy tests mới + URL block rewrite; PASS. Review rồi commit `feat(strings): translate registered text in supported regions`.

## Task 6: Quét trang và tô sáng có phiên xác thực

**Files:** Create `lingolane/admin/class-lingolane-string-scan.php`, `lingolane/assets/string-scan.js`, `lingolane/assets/string-scan.css`, `tests/phpunit/test-string-scan.php`; Modify `lingolane/lingolane.php` và registry Tasks 3/5.

**Interfaces:** `Lingolane_String_Scan::pages(): array`; `start(array $paths): array{session:string,pages:array,expires:int}`; `accept(string $session,string $path,array $items): array{accepted:int,rejected:int}`; `preview_url(int $occurrence_id): string|WP_Error`. AJAX scan_start/scan_accept/scan_preview có prefix lingolane_, nonce `lingolane_scan_ajax` và quyền dịch.

- [ ] Test `test_nonce_capability_and_user_bound_session`, `test_external_admin_private_paths_rejected`, `test_forged_supported_identity_rejected`, `test_payload_limits_and_expiry`, `test_guest_has_no_manifest`. Session hết hạn/user khác → 403; URL ngoài → 400; hơn 200 items hoặc 128KB/request → lỗi rõ, không ghi một phần âm thầm.
- [ ] Chạy `--filter String_Scan`; FAIL.
- [ ] pages() trả homepage, posts page, 3 bài public mới nhất, 3 trang public mới nhất, 2 archive term có bài; khử trùng, tối đa 10. Người dùng chọn và thấy phạm vi trước chạy; không crawl vô hạn. Bỏ admin/private/draft/search/cart/account và URL có query. Session 15 phút, token ngẫu nhiên gắn user, chỉ tạo qua POST hợp lệ. Canonical path cùng origin, không redirect ngoài; không HTTP fetch phía server.
- [ ] Bảo vệ DB và giới hạn phiên quét: Mỗi phiên quét 15 phút có trần tích lũy tối đa **500 occurrences**, tối đa 5 request `accept` trong một session và rate-limit tối thiểu 1 giây giữa các request. Vượt trần sẽ bị từ chối với mã lỗi `payload_too_large` thay vì nuốt dữ liệu âm thầm.
- [ ] Mở tab bằng click theo thao tác người dùng (Ruling: mở tab thay vì iframe nền vì header `X-Frame-Options` và CSP `frame-ancestors` trên các site bảo mật chặn nạp iframe, đồng thời tránh browser throttle DOM/JS). Chạy từng source-language page, script chỉ nạp sau verify phiên. Không thu admin bar/form/input/textarea/password/script/style/hidden/account regions. Text DOM không có nguồn đáng tin chỉ thành candidate unsupported tối đa 2000 ký tự, không thành replace rule; nhãn `Cannot be translated automatically`. Supported identity phải đối chiếu manifest server từ adapter/gettext/block và source gốc, không tin provider/domain/ID client. Gettext capture chỉ scan request, không bọc gettext thành marker HTML.
- [ ] Xác định vùng theo template area hoặc semantic header/footer/aside; không coi header của article là site header. Lưu selector + exact text + node order + nearby context. Có stop/retry và progress; không xóa occurrences cũ khi scan dở/lỗi. Khi quét thành công một trang (`page_path`), cập nhật `last_seen`; đồng thời dọn dẹp các occurrences thuộc cùng `page_path` đó mà `last_seen` cũ hơn lần quét này (garbage collection) để không tích rác khi trang đổi layout. postMessage kiểm origin/source/session, không wildcard. Nếu popup bị chặn hiện link mở thủ công.
- [ ] Preview server dựng URL cùng origin từ occurrence, token gắn user, cache-control private/no-store. Không lưu token vào page_path. JS chỉ highlight khi locator/exact source hoặc translation khớp duy nhất; mất/đa nghĩa báo `Text no longer found. Scan this page again.` Không chọn match đầu âm thầm. Overlay không sửa text, tắt chế độ thì gỡ; guest không được nhận manifest.
- [ ] Chạy tests mới + `node --check lingolane/assets/string-scan.js`. Browser fixture classic/block: chọn 2 trang, stop/retry, duplicate text, DOM đổi, popup blocked và guest. PASS có bằng chứng. Review rồi commit `feat(strings): add authenticated scan and occurrence preview`.

## Task 7: Tab Chuỗi theo nhóm và bảng đa ngôn ngữ

**Files:** Modify `lingolane/admin/class-lingolane-strings-admin.php`, `lingolane/admin/class-lingolane-stats.php`, `lingolane/admin/class-lingolane-admin.php`, `lingolane/assets/admin.css`, `lingolane/assets/admin.js`; Create `lingolane/assets/string-groups.js`, `tests/phpunit/test-string-groups-ui.php`.

**Interfaces:** Consumes Locations::groups/page, Terms::page/progress/save, Scan::pages/preview_url, Sources::menu_is_language_specific. Giữ render_tab_content() và endpoint save cũ; save nhận tuple Identity `{domain, source, context, language_code, translation}`, không phụ thuộc `row_id` đã có sẵn trong bảng strings; trả kết quả để invalidate cache nhóm tương ứng.

- [ ] Test `test_groups_distinct_counts`, `test_source_and_all_target_columns`, `test_shared_identity_updates_all_groups`, `test_filters_pagination_and_no_js_save`, `test_terms_use_term_endpoint`, `test_theme_scan_keeps_page_sources`. Fixture 31 identity → phân trang 30, không nhân hàng theo ngôn ngữ/occurrence.
- [ ] Chạy `--filter String_Groups_UI`; FAIL.
- [ ] Sidebar 10 nhóm theo hợp đồng, Plugin submenu theo domain và Menu theo location. Counts/% theo ngôn ngữ đích đang chọn để lọc; bảng vẫn có bản gốc và từng ngôn ngữ đích. Giữ own/inherited/missing, giải thích inherited. Nhóm rỗng vẫn hiện 0 và Scan CTA. Terms dùng name/description và progress riêng; unsupported disable save kèm lý do.
- [ ] Giữ keyword/domain/status/pagination/CSV/Scan theme; GET không scan/ghi DB. Theme scanner chỉ xóa identity chưa dịch do chính nó sở hữu và không có occurrence provider khác. Menu riêng có nhãn `Using a separate menu for this language` và link sửa menu. View on page cho chọn occurrence; chưa quan sát trên trang thì disabled với lý do, không URL giả.
- [ ] Save JS giữ focus và lỗi tại ô; chuyển nhóm có draft chưa lưu dùng confirm inline. No-JS vẫn có POST form. Sidebar keyboard/aria-current, table scope, aria-live toast, reduced-motion, bảng cuộn riêng trên mobile. Dashboard tổng distinct identity, không cộng các nhóm hoặc trộn terms vào tổng chuỗi hiện có.
- [ ] Chạy tests mới + UI/stats/strings; node --check admin.js/string-groups.js. Browser 1440/390px, keyboard, 3 ngôn ngữ, user chỉ quyền dịch, JS tắt. PASS có evidence. Review rồi commit `feat(strings): browse translations by frontend location`.

## Task 8: i18n, hồi quy, gói nghiệm thu

**Files:** Modify `lingolane/languages/lingolane.pot`, `lingolane/languages/lingolane-vi.po`, `lingolane/languages/lingolane-vi.mo`, `lingolane/readme.txt`, `RELEASE-PLAN.md`; Create `docs/reports/2026-10-23-p3-acceptance.md`.

**Interfaces:** Không API mới. Report nối yêu cầu spec → test/lệnh/bằng chứng; thiếu môi trường ghi NOT RUN.

- [ ] Thêm catalog test dùng helper hiện có: nhãn mới có msgid/msgstr vi, placeholder nguyên vẹn; liệt kê đường ghi P3 và test quyền/nonce tương ứng. Chạy thấy FAIL trước cập nhật catalog.
- [ ] Cập nhật pot/po/mo bằng công cụ repo. Readme hướng dẫn quyền dịch, quét, giới hạn Khác, menu riêng, phạm vi block/widget; không hứa phủ 100%. RELEASE-PLAN chỉ ghi thao tác browser đã thử, không sửa chứng cứ P1 đang chờ QA.
- [ ] Chạy toàn PHPUnit PHP 7.4/8.2, phpcs, JS syntax, build. Harness phải trỏ đúng P3, không dùng script hard-code P1fix. Ghi baseline thực; không lấy 137 tests của nhánh P1fix làm baseline P3.
- [ ] Sau phê duyệt riêng cho site thử nghiệm: backup/cài build, Plugin Check 0 lỗi; QA classic/block, WP 6.2 và WP local hiện có. Guest không script/marker/write query; scan response không vào public cache; lưu/xem đúng từng adapter; scan dở không mất bản dịch. Thiếu môi trường ghi NOT RUN và để release gate mở.
- [ ] Review độc lập toàn diff, sửa blocking findings và retest. Đối chiếu spec/evidence, ghi giới hạn. Commit `docs(strings): document and verify P3 workflows` chỉ khi nội dung đúng việc thực hiện.
- [ ] Chờ phê duyệt merge/deploy theo quyền repo; cập nhật system-map khi đổi trạng thái/nhánh. Không tự xóa worktree. Rollback code bằng commit đảo sau phê duyệt; bảng additive giữ lại, không drop để rollback.

## Tự rà soát bản nháp

- Coverage: 10 nhóm → Tasks 1,3–7; Scan/View → 6; shared identity → 1,7; capability → 2; ngoại lệ block → 5; i18n/QA → 8.
- Interfaces dùng thống nhất; các hàm mới là đề xuất, chưa coi là tồn tại.
- Gate kỹ thuật Task 5 đã chốt giải pháp: state-machine tokenizer thuần PHP nhận diện boundary thẻ, ghi nhận exact byte offset, đảo ngược thứ tự thay thế để không làm lệch offset và tương thích PHP 7.4 / WP 6.2.
- Đã quyết (bản nháp): occurrences riêng giữ một bản dịch nhiều vị trí; 10 mục UI vì terms/media khác mô hình; tối đa 10 trang/lượt và trần 500 occurrences/phiên tránh phình DB. Mở tab thay vì iframe để tránh chặn CSP/X-Frame-Options. Hoàn tác bằng sửa bản nháp trước code; chưa migration hoặc sửa site.
- Đã qua review độc lập (2026-10-23, exit 0, 8 findings). Kế hoạch đã được hoàn thiện để sẵn sàng thực thi (Draft Ready for Execution). Chờ phê duyệt từ Tan để bắt đầu Task 1.
