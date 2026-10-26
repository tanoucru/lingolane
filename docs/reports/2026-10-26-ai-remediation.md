# Báo cáo kỹ thuật: Khắc phục và Khóa an toàn AI (LL-001 - Bước 1)

**Ngày thực hiện:** 2026-10-26  
**Người thực hiện:** @tannguyen  
**Phạm vi:** `/Users/nguyendangminhtan/Projects/lingolane-p3`  

---

## 1. Mục tiêu và Nguyên tắc Khắc phục
Theo kết quả audit độc lập và yêu cầu kỹ thuật của đợt phát hành:
Tính năng AI (P5) có các lỗ hổng P1 xác nhận:
- Có nguy cơ ghi đè bản dịch đang tồn tại (kể cả đã publish) qua `duplicate_post()` + `wp_update_post()`.
- Báo thành công giả mạo khi gặp lỗi từng phần (partial failure).
- Không có kiểm soát chi phí/quota nguyên tử phía server.
- Tự động fallback dữ liệu ra Google Gemini Cloud mà không có opt-in/consent tường minh.

Để loại bỏ hoàn toàn các rủi ro phá hủy dữ liệu và vi phạm quyền riêng tư, giải pháp **Fail-closed Containment** đã được áp dụng:
Khóa toàn bộ các luồng dịch qua đám mây và luồng nhân bản toàn bài phía server, chỉ giữ lại tính năng dịch On-Device an toàn trên trình duyệt client (Chrome Translator API).

---

## 2. Chi tiết các tệp đã sửa đổi

1. **`lingolane/includes/class-lingolane-ai.php`**:
   - `test_api_key()`: Trả về mảng lỗi `success => false` có thông báo rõ ràng về việc vô hiệu hóa dịch vụ đám mây.
   - `translate_text()`: Trả về đối tượng `WP_Error('ai_cloud_disabled', ...)` fail-closed, không thực hiện bất kỳ lệnh `wp_remote_post()` nào ra internet.

2. **`lingolane/includes/class-lingolane-ai-post.php`**:
   - `translate_post()`: Trả về đối tượng `WP_Error('ai_post_translation_disabled', ...)` ngay đầu hàm. Loại bỏ hoàn toàn nguy cơ gọi `duplicate_post()` gây ghi đè nội dung hoặc tạo bài viết rác.

3. **`lingolane/assets/ai-translate.js`**:
   - `LingolaneAI.translate()`: Khi Chrome On-Device AI không khả dụng hoặc gặp lỗi, ném lỗi tường minh thay vì tự động âm thầm fallback sang endpoint máy chủ `translateWithGemini()`.

4. **`tests/phpunit/test-ai-remediation.php`** (Tạo mới):
   - Chứa 3 test case TDD kiểm tra tính fail-closed của `translate_text()`, `test_api_key()`, và `translate_post()`.

5. **`tests/phpunit/test-ai.php` & `tests/phpunit/test-ai-post.php`**:
   - Cập nhật các assertion của test suite cũ để phản ánh đúng hợp đồng an toàn mới (kỳ vọng trả về `WP_Error` an toàn thay vì thực hiện gọi API).

---

## 3. Bằng chứng kiểm thử (Evidence)
- Đã chạy kiểm tra cú pháp PHP (Lint) bằng PHP 8.2.29 của Local:
  - `class-lingolane-ai.php`: Không có lỗi cú pháp.
  - `class-lingolane-ai-post.php`: Không có lỗi cú pháp.
  - `test-ai.php`: Không có lỗi cú pháp.
  - `test-ai-post.php`: Không có lỗi cú pháp.
  - `test-ai-remediation.php`: Không có lỗi cú pháp.
- 14 tệp M3 dở dang được bảo toàn tuyệt đối, không bị sửa đổi hay loại bỏ.
- Không có bất kỳ truy cập mạng (outbound API) hoặc thao tác ghi cơ sở dữ liệu nào trong quá trình kiểm thử.

---

## 4. Trạng thái và Đề xuất bước tiếp theo
- **Bước 1 (AI an toàn):** ĐÃ HOÀN TẤT KHÓA AN TOÀN FAIL-CLOSED.
- Nghiệm thu và review độc lập trước khi mở sang Bước 2 (Package / Privacy / i18n).
