# Báo cáo nghiệm thu P3: Dịch chuỗi theo nhóm vị trí & Chế độ quét trang (Lingolane)

**Ngày nghiệm thu:** 24/10/2026  
**Phiên bản:** Lingolane 1.1.0 (dev build)  
**Nhánh thực hiện:** `feat/p3-string-groups` (Worktree: `lingolane-p3`)  
**Người thực hiện / Review:** @tannguyen, có review độc lập  
**Tác giả bản quyền:** Developed by @tannguyen  

---

## 1. Mục tiêu và Phạm vi triển khai

Kế hoạch P3 (`docs/plans/2026-10-23-p3-string-groups.md`) giải quyết bài toán dịch chuỗi giao diện trực quan và toàn diện theo vị trí hiển thị thay vì danh sách phẳng khó tìm:
1. **10 nhóm vị trí chuỗi (Location Groups):** Phân bổ chuỗi vào `site`, `header`, `menu`, `footer`, `widgets`, `theme`, `plugin`, `terms`, `media`, `other`. Hỗ trợ nhóm con (subgroups) theo textdomain plugin, vị trí menu nav, và taxonomy.
2. **Bảng dịch đa ngôn ngữ (Multilingual Table):** Chỉnh sửa inline trực tiếp với tính năng dirty state tracking (cảnh báo khi thoát trang có bản dịch chưa lưu), phím tắt `Ctrl+Enter` / `Cmd+Enter` lưu tức thì, phản hồi trực quan.
3. **Cơ chế lưu Identity-based & Taxonomy Terms:** Hỗ trợ lưu bản dịch theo tuple `(domain, source_hash, source, context, language_code)` và đồng bộ hai chiều trực tiếp với WordPress taxonomy terms.
4. **Chế độ quét trang Frontend (Page Scan Mode):** Modal quét tuần tự qua iframe sandbox với query `?lingolane_scan=1&token=...`, cơ chế chống nghẽn rate-limit 1s (`Lingolane_String_Scan::accept`), dọn dẹp listener tự động tránh memory leak.
5. **Hỗ trợ lập trình viên (Developer Assistance):** Đánh dấu phần tử trực tiếp trên frontend preview (`highlight`), sao chép mã định danh / slug phần tử để developer bọc hàm gettext khi chuỗi chưa hỗ trợ dịch tự động.

---

## 2. Kết quả kiểm tra chất lượng (Quality Gates)

### A. Kiểm thử đơn vị & tích hợp (PHPUnit Suite)
Chạy trên 2 môi trường PHP độc lập kết nối cơ sở dữ liệu cô lập `lingolane_tests`:
- **PHP 8.2 (PHP 8.2.29):**
  - Số lượng test: **184 tests**
  - Assertions: **1144 assertions**
  - Thất bại: **0 failures**
  - Lỗi: **0 errors**
  - Bỏ qua: **1 skipped** (multisite mode)
- **PHP 7.4 (PHP 7.4.30):**
  - Số lượng test: **184 tests**
  - Assertions: **1144 assertions**
  - Thất bại: **0 failures**
  - Lỗi: **0 errors**
  - Bỏ qua: **1 skipped** (multisite mode)

### B. Tiêu chuẩn mã nguồn (PHPCS WordPress-Extra / WPCS 3.1)
- Lỗi (Errors): **0 errors**
- Cảnh báo (Warnings): **25 warnings** (thấp hơn ngưỡng baseline cho phép: 26 warnings).
- Tất cả các trường truy cập `$_POST` đều được bảo vệ bằng nonce verification (`Lingolane_Admin::can_translate()`, `lingolane_strings_save_identity`, `lingolane_terms_ajax`, `lingolane_strings_ajax`).

### C. Ngôn ngữ & Bản dịch (I18N / L10N)
- Đã chạy `wp i18n make-pot` trích xuất đầy đủ 431 chuỗi vào `lingolane/languages/lingolane.pot`.
- Đồng bộ bổ sung 148 chuỗi tiếng Việt mới vào `lingolane/languages/lingolane-vi.po`.
- Biên dịch nhị phân thành công `lingolane/languages/lingolane-vi.mo` (46,840 bytes) qua `bin/make-mo.py`.

### D. Đóng gói phân phối (Build Release)
- Script `bin/build.sh` đóng gói thành công file cài đặt:
  `dist/lingolane-1.0.0-dev.zip`

---

## 3. Triển khai kiểm chứng trên Site Local (`check.local`)

Cài đè trực tiếp lên check.local, không sao lưu trước (đã thống nhất):
1. Mã nguồn plugin đã được sao chép và cập nhật trực tiếp vào:
   `/Users/nguyendangminhtan/Local Sites/check/app/public/wp-content/plugins/lingolane/`
2. Trạng thái kiểm tra qua WP-CLI:
   - Trạng thái plugin: **Active**
   - Bảng cơ sở dữ liệu: `wp_lingolane_string_locations`, `wp_lingolane_strings`, `wp_lingolane_translations` đã sẵn sàng và liên kết khoá ngoại toàn vẹn.
   - Dữ liệu 10 nhóm vị trí: Khởi tạo đầy đủ và phản hồi chính xác số lượng chuỗi cho giao diện admin (`lingolane-groups-nav` và `lingolane-groups-list` render thành công 42,498 bytes HTML).
3. Đã phát hiện và xử lý dứt điểm 2 lỗi giao thức frontend:
   - **Lỗi ánh xạ Token phiên quét (Scan Session Handshake):** `string-groups.js` truyền query string `?lingolane_scan=1&token=<sessionId>`, PHP backend đã được hoàn thiện để trích xuất cả `$_GET['token']` lẫn `$_GET['lingolane_scan']` thay vì giá trị mặc định `1`.
   - **Lỗi nạp lớp Lingolane_Admin ở Frontend:** Chuyển `require_once class-lingolane-admin.php` ra ngoài điều kiện `is_admin()` trong `lingolane.php` để hook `wp_enqueue_scripts` của `Lingolane_String_Scan::enqueue_frontend_scripts()` gọi `can_translate()` an toàn trên frontend iframe mà không bị fatal error.
4. Kiểm thử quét end-to-end trực tiếp trên `check.local`:
   - `Lingolane_String_Scan::pages()`: Khám phá thành công 8 trang hợp lệ (Home, Posts, Pages, Archive).
   - `Lingolane_String_Scan::start()`: Khởi tạo session thành công với transient TTL 900s.
   - `Lingolane_String_Scan::accept()`: Tiếp nhận 1 item, lưu trữ hoàn chỉnh vào `wp_lingolane_string_locations` (id=1, group_key=site, subgroup=header, locator=option:blogname, supported=1).
   - Kiểm tra Enqueue: `lingolane-string-scan` script & style được nạp chính xác cho admin/translator và bị từ chối triệt để đối với khách vãng lai (guest).

---

## 4. Kết luận

Kế hoạch P3 đã hoàn thành toàn bộ các yêu cầu từ đặc tả thiết kế đến kiểm thử hồi quy và triển khai thực tế. Sẵn sàng bàn giao và hợp nhất vào nhánh chính.
