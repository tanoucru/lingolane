=== Lingolane ===
Contributors: tannguyen
Tags: multilingual, translation, hreflang, language switcher
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2
License: Proprietary

Plugin đa ngôn ngữ nhẹ cho WordPress: bản dịch bài viết và trang, chữ trên giao diện, đường dẫn theo thư mục ngôn ngữ, hreflang và nút chuyển ngôn ngữ.

== Description ==

* Bản dịch bài viết, trang và phân loại, liên kết theo nhóm. Tạo bản dịch bằng một nút: nội dung, ảnh, trường tuỳ chỉnh và phân loại được sao chép sang bản nháp mới.
* Ngôn ngữ mặc định không có tiền tố; ngôn ngữ khác nằm trong thư mục riêng (/en/).
* Hồ sơ gia sư, chuyên mục, khu vực và trang tài khoản dùng chung cho mọi ngôn ngữ.
* Trang chưa dịch mở bản gốc kèm ghi chú, không báo lỗi 404.
* Thẻ hreflang, x-default, canonical và thuộc tính lang tự động; tương thích Yoast SEO.
* Liên kết nội bộ trong bài tự chuyển sang bản dịch tương ứng.
* Chữ trên giao diện: quét theme, dịch và lưu ngay trên bảng, nhập/xuất CSV.
* Nút chuyển ngôn ngữ: shortcode [lingolane_switcher], widget, hoặc mục menu #lingolane-switcher.

== Usage ==

Quản trị › Đa ngôn ngữ. Tab Hướng dẫn trong plugin mô tả từng bước.

== Changelog ==

= 1.2 =
* Ngôn ngữ mặc định (không tiền tố URL) tách khỏi ngôn ngữ gốc của nội dung: có thể đặt English làm mặc định trong khi bài viết và chữ giao diện gốc vẫn là tiếng Việt. Trang chủ/trang blog tự dùng bản dịch đúng ngôn ngữ; địa chỉ cũ không tiền tố chuyển 301 sang trang tương ứng.
* og:url và dữ liệu schema của Yoast dùng đúng địa chỉ theo ngôn ngữ.
* Sửa: trang ngôn ngữ phụ (/en/) không còn trỏ jQuery, CSS thanh quản trị và tài nguyên lõi WordPress sang /en/wp-includes/… (404).
* Bảng điều khiển mới: Tổng quan tiến độ theo ngôn ngữ, Bài & trang, Chuỗi giao diện, Ngôn ngữ, Cài đặt, Hướng dẫn.
* Chuỗi giao diện lưu tức thì (Ctrl/⌘ + Enter), lọc Chưa dịch/Đã dịch/Giao diện có sẵn, nút Giữ nguyên.
* Quét giao diện bỏ qua nhãn chỉ dùng trong trang quản trị và dọn chuỗi chưa dịch không còn dùng.
* Ngôn ngữ: sửa tên/locale, đặt mặc định có xác nhận, gỡ an toàn, chọn nhanh ngôn ngữ phổ biến.
* Tuỳ chọn "Hiện bài chưa dịch trong danh sách" hoạt động thật; bỏ tuỳ chọn định dạng đường dẫn không được hỗ trợ.
* Lưu hồ sơ gia sư trong trình soạn thảo không còn làm hồ sơ biến mất ở bản tiếng Anh.
* Nhẹ hơn: mã quản trị chỉ nạp trong trang quản trị, chuỗi dịch chỉ nạp bản đã dịch, gộp truy vấn trang dùng chung, CSS nút chuyển chỉ in khi dùng.
* Thông báo lỗi hiển thị ngay trên bảng điều khiển thay cho trang lỗi trắng.
