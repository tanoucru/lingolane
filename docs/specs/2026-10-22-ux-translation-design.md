# Thiết kế: giao diện mới, dịch theo vị trí trên trang, dịch tại chỗ và gợi ý AI

Ngày: 2026-10-22. Người quyết định: Tan. Người viết: @tannguyen.
Phạm vi: plugin `lingolane/` (WP ≥ 6.2, PHP ≥ 7.4, GPL-2.0-or-later). Mốc mã nguồn: `cfa5a49`.

## 1. Vì sao làm

Lingolane đã đủ chức năng dịch cơ bản. Khi Tan dùng thử thì gặp bốn chỗ khó chịu:
1. Trong danh sách Trang/Bài viết của WordPress, bản gốc và bản dịch nằm lẫn với nhau thành các dòng rời, nên không biết bài nào đi với bài nào. Cột "Languages" lại nằm tận cuối bảng.
2. Giao diện quản trị trông như WordPress gốc: cũ, không có hiệu ứng, màu cơ bản, chưa hoàn chỉnh, icon lệch, ít minh hoạ.
3. Nhiều chữ trên frontend không có chỗ dịch rõ ràng, nhất là ở các vị trí cố định như header, footer, menu và những vùng theme hoặc plugin tự thêm vào. Tab "Chuỗi" hiện chỉ quét chữ trong mã theme và gom theo text-domain.
4. Dịch thủ công từng chữ mất thời gian. Tan muốn có gợi ý dịch bằng AI, ưu tiên loại miễn phí và chất lượng cao, mà **người dùng không phải cài đặt gì thêm**, đồng thời **có hướng dẫn cụ thể**.

Thành công nghĩa là:
- Người mới mở danh sách Trang thì thấy ngay quan hệ giữa bản gốc và bản dịch.
- Màn hình Lingolane trông như một sản phẩm SaaS hiện đại.
- Mọi chữ nhìn thấy trên trang đều tìm được chỗ dịch, theo đúng vị trí của nó, hoặc sửa được ngay trên trang.
- Bấm một nút là có bản dịch nháp từ AI.

## 2. Mục tiêu, ngoài phạm vi, điều cấm

**Mục tiêu:** 5 phần ở mục 4 (P1 danh sách cây, P2 hệ giao diện, P3 dịch theo nhóm vị trí, P4 dịch tại chỗ trên frontend, P5 gợi ý AI).

**Ngoài phạm vi (lần này):**
- Dịch tại chỗ cho ảnh, link và thẻ meta SEO (chỉ chữ).
- Máy chủ trung gian giữ key AI chung cho mọi site. Hướng này để dành cho bản trả phí và cần Tan quyết chi phí.
- Chế độ tối cho trang quản trị.
- Multisite.

**Điều cấm (làm vậy là hỏng dù "chạy được"):**
- Làm chậm frontend của khách. Mọi cơ chế ghi nhận, đánh dấu chữ, nạp script của chế độ sửa chỉ chạy khi quản trị viên bật chế độ đó.
- Đổi kiến trúc sang dịch HTML sau khi render (kiểu TranslatePress). Bản dịch bài viết vẫn là một bài riêng; chuỗi vẫn đi qua gettext/option/menu như hiện nay.
- CSS/JS của Lingolane làm lệch các màn hình WordPress khác. Style phải gói trong phạm vi màn hình Lingolane và các phần Lingolane tự chèn vào.
- Bản dịch máy hiện cho khách khi chưa được duyệt, trừ khi quản trị viên bật "tự duyệt".
- Lộ API key: đưa ra trình duyệt, ghi log, xuất CSV, hay hiện nguyên văn trên màn hình.
- Dùng đường dịch không chính thức (vd endpoint `translate_a` của Google không cần key), gọi máy chủ ngoài khi chưa được bật, hay nạp tài nguyên từ CDN/Google Fonts. Các điều này vi phạm điều khoản hoặc hướng dẫn plugin của WordPress.org.

## 3. Ràng buộc

- Tương thích WP 6.2 trở lên, PHP 7.4 trở lên (CI hiện chạy 7.4/8.1/8.4), cả theme cổ điển lẫn theme khối.
- Giấy phép GPL-2.0+. Thư viện hoặc icon đưa vào phải tương thích GPL. Lucide (ISC) dùng được, nhưng phải chép SVG vào plugin, không tải qua CDN.
- Chuỗi giao diện viết bằng tiếng Anh, bọc hàm dịch, cập nhật `.pot` và bản `vi` (theo quy ước M1).
- Mọi thao tác ghi kiểm quyền và nonce như `Lingolane_Admin::guard()`/`ajax_guard()` hiện có. SQL luôn qua `prepare()`. Plugin Check 0 lỗi, phpcs 0 lỗi.
- Test PHPUnit cho logic PHP mới, không gọi mạng thật (dùng filter cắt ngắn như `lingolane_pre_install_language_pack`).
- Credit: `@tannguyen`.

## 4. Những gì Tan đã quyết

### P1. Danh sách Trang/Bài viết dạng cây (màn hình `edit.php` của WordPress)
Tan chọn gộp A và B trong mockup.
- Mỗi bản gốc là một dòng nhóm. Các bản dịch nằm ngay bên dưới, nối với nhau bằng đường kẻ kiểu cây thư mục.
- Mỗi nhóm có mũi tên gập/mở. Mặc định mở hết, có nút "Mở tất cả / Gập tất cả", và trình duyệt nhớ trạng thái gập/mở cho từng người.
- Cột Ngôn ngữ đứng ngay sau cột Tiêu đề (hiện cột `lingolane_language` được thêm vào cuối trong `admin/class-lingolane-columns.php`).
- Ngôn ngữ chưa có bản dịch hiện thành một dòng mờ trong cây, kèm nút "+ Tạo" (dùng `Lingolane_Admin::translate_url()`). Dòng bản gốc có nhãn tiến độ, ví dụ "2/3 ngôn ngữ".
- Bản dịch luôn đi liền bản gốc, không bị tách sang trang khác. Phân trang tính theo nhóm.
- Khi lọc theo một ngôn ngữ cụ thể (bộ lọc `lingolane_lang` có sẵn), danh sách trở về dạng phẳng.
- Quan hệ trang cha/con của WordPress giữ nguyên. Cây chỉ nối bản gốc với bản dịch, không trộn với quan hệ cha/con.
- Tìm kiếm khớp với một bản dịch thì hiện cả nhóm, tô nổi bản khớp.
- Bài "dùng chung" (shared) và bài chưa gán ngôn ngữ hiện như một dòng thường, không có cây.

### P2. Hệ giao diện mới, hướng "SaaS sáng, hiện đại" (mockup A)
- Nền xám nhạt, thẻ trắng bo góc với bóng nhẹ, màu nhấn tím–chàm. Chữ có thứ bậc rõ: số liệu lớn, nhãn nhỏ màu xám.
- Áp dụng cho mọi màn hình Lingolane (Tổng quan, Bài & trang, Chuỗi, Ngôn ngữ, Cài đặt, Hướng dẫn), hộp "Bản dịch" trong trình soạn thảo, cột và cây ở P1, và thanh công cụ của P4.
- Thay dashicons bằng một bộ icon SVG nét đều (Lucide), căn theo lưới 20px, cùng độ dày nét.
- Minh hoạ nhỏ cho màn hình trống ("chưa có ngôn ngữ thứ hai", "đã dịch hết") và cho trình hướng dẫn lần đầu.
- Hiệu ứng nhẹ: rê chuột, thanh tiến độ chạy, thông báo lưu nổi lên rồi tự tắt (toast). Mọi hiệu ứng tắt khi người dùng bật `prefers-reduced-motion`.
- Font hệ thống, không tải font ngoài. Tương phản đạt WCAG 2.2 AA, dùng được bằng bàn phím, xem tốt trên điện thoại.
- Màu, khoảng cách, bo góc và bóng khai báo một lần thành biến CSS, mọi màn hình dùng chung.

### P3. Dịch mọi chữ trên frontend, chia theo nhóm vị trí
Tab "Chuỗi" có cột nhóm bên trái. Mỗi nhóm hiện số chữ và % đã dịch. Bấm vào nhóm thì mở bảng dịch (bản gốc | từng ngôn ngữ). Các nhóm:
1. **Thông tin site:** tên site, khẩu hiệu, dòng bản quyền, alt của logo.
2. **Header:** chữ ở thanh trên cùng, nút kêu gọi, ô tìm kiếm.
3. **Menu:** từng mục của từng menu (tên, và link tuỳ chỉnh nếu có), nhóm theo vị trí menu của theme.
4. **Footer:** các cột footer, widget footer, dòng bản quyền.
5. **Sidebar & widget:** tiêu đề và nội dung widget, khối widget.
6. **Theme:** chữ cài sẵn trong mã theme (phần quét theme hiện có).
7. **Plugin:** mỗi plugin một nhóm con (vd Contact Form 7, WooCommerce).
8. **Danh mục & thẻ** (tên, mô tả) và **Media** (alt, chú thích).
9. **Khác:** chữ không xác định được vị trí.

Cách gom chữ: thêm chế độ **"Quét trang"**. Plugin mở các trang chính của site, ghi lại mọi chữ thực sự hiện ra và vị trí của nó. Theme khối lấy vị trí từ vùng của template part (header/footer). Theme cổ điển lấy vị trí theo các mốc header/footer/sidebar. Nút "Quét theme" hiện có vẫn giữ.

Mỗi chữ có nút **"Xem trên trang"**: mở frontend và tô sáng đúng chữ đó.

### P4. Dịch tại chỗ trên frontend
- Thanh admin có nút **"Dịch trang này"**. Bấm vào thì mở chính trang thật, ở ngôn ngữ đang chọn, trong chế độ sửa bản dịch, kèm một thanh nhỏ để đổi ngôn ngữ và nút "Thoát".
- Rê chuột lên chữ dịch được thì chữ đó có viền và icon bút. Bấm vào thì mở ô sửa ngay tại chỗ: bản gốc ở trên, ô bản dịch ở dưới, nút **Lưu**, nút **Gợi ý AI**, và dòng ghi nhóm của chữ (vd "Footer · Theme Astra"). Lưu xong, chữ trên trang đổi ngay mà không phải tải lại.
- Áp dụng cho mọi chữ thuộc các nhóm ở P3.
- Thân bài viết/trang là một bài dịch riêng, nên không sửa từng câu tại chỗ. Bấm vào vùng nội dung hiện "Sửa bản dịch bài này" và mở trình soạn thảo. Riêng **tiêu đề bài** thì sửa được tại chỗ.
- Chỉ người có quyền quản trị bản dịch mới thấy chế độ này. Khách không bị ảnh hưởng gì. Trang ở chế độ sửa không được để plugin cache lưu lại.

### P5. Gợi ý dịch bằng AI, kết hợp hai nguồn
- **Mặc định: AI dịch có sẵn trong trình duyệt** (Translator API của Chrome bản máy tính, từ 138). Chạy ngay trên máy quản trị viên: không cần key, không tốn tiền, nội dung không rời khỏi máy.
- **Tuỳ chọn: Gemini.** Quản trị viên dán API key miễn phí từ Google AI Studio để có chất lượng cao hơn hoặc khi không dùng Chrome. Có nút "Kiểm tra key". Key lưu trên site, chỉ quản trị viên dùng được, luôn bị che khi hiển thị, và chỉ máy chủ WordPress gọi Gemini (key không bao giờ xuống trình duyệt).
- Hai cách dùng:
  - nút **"Gợi ý AI"** cạnh từng chữ (bảng dịch ở P3 và ô sửa ở P4);
  - nút **"Dịch nháp tất cả phần còn thiếu"** theo nhóm hoặc theo trang. Nút này hiện tiến độ, dừng được giữa chừng và không làm hỏng phần đã lưu.
- **Dịch nháp cả bài viết/trang** (Tan chốt làm ngay đợt này). Ở hộp "Bản dịch" trong trình soạn thảo, ở dòng "+ Tạo" trong danh sách cây (P1) và ở tab "Bài & trang", cạnh "Tạo bản dịch" có thêm **"Tạo bằng AI"**. Nút này tạo bản dịch nháp như hiện nay (`Lingolane_Elements::duplicate_post`), rồi để AI dịch tiêu đề, tóm tắt và thân bài.
  - Thân bài phải giữ nguyên cấu trúc: chỉ dịch phần chữ, không đổi chú thích khối `<!-- wp:… -->`, thẻ HTML, link, ảnh hay shortcode. Chữ trong thuộc tính khối dùng để hiển thị (vd `alt` của ảnh, nhãn nút) cũng được dịch. Nội dung bị cắt thành các đoạn nhỏ để gửi đi, rồi ráp lại đúng chỗ.
  - Đợt này hỗ trợ trình soạn thảo khối và trình soạn thảo cổ điển. Với Elementor và các page builder khác, bản nháp vẫn được tạo nhưng thân bài giữ nguyên, kèm thông báo rõ "chưa hỗ trợ dịch tự động cho trình dựng trang này" *(đã chốt; hỗ trợ builder để đợt sau)*.
  - Với AI trong Chrome, việc dịch chạy trong trình duyệt (tab phải mở, có thanh tiến độ). Với Gemini, máy chủ chia đoạn và gửi theo lô, tôn trọng giới hạn lượt gọi.
  - Bài tạo bằng AI ở trạng thái nháp, gắn nhãn "Máy dịch – chờ duyệt" trong danh sách cây và hộp "Bản dịch" cho tới khi được xuất bản.
- Bản dịch máy gắn trạng thái **"Máy dịch – chờ duyệt"** và không hiện cho khách cho tới khi được duyệt. Có nút duyệt từng cái và duyệt cả loạt, và một tuỳ chọn "Tự duyệt bản dịch máy" (mặc định tắt).
- **Hướng dẫn cụ thể** (Tan yêu cầu bắt buộc):
  - Thẻ "Dịch bằng AI" trong Cài đặt tự kiểm tra và báo trạng thái bằng lời dễ hiểu, ví dụ "Trình duyệt của bạn dịch được Anh → Việt, sẵn sàng", "Chrome đang tải mô hình…", "Chrome của bạn cũ hơn bản 138, hãy cập nhật", "Trình duyệt này không có AI dịch, hãy dùng Chrome hoặc thêm key Gemini".
  - Hướng dẫn từng bước có minh hoạ: (a) dùng AI có sẵn của Chrome; (b) lấy key Gemini: vào Google AI Studio → đăng nhập → Get API key → sao chép → dán vào Lingolane → Kiểm tra.
  - Ghi chú rõ: ở gói miễn phí của Gemini, Google được dùng nội dung gửi lên để cải tiến sản phẩm, và có giới hạn số lượt gọi.
  - Khi nút "Gợi ý AI" không dùng được, nó hiện lý do cụ thể và dẫn thẳng tới hướng dẫn, thay vì báo lỗi chung chung.
  - Cùng nội dung đưa vào `readme.txt` (mục dịch vụ bên ngoài, theo yêu cầu của WordPress.org) và tài liệu dự án.

## 5. Thứ tự làm (đề xuất)

Năm phần này tách được, mỗi phần có kế hoạch, nhánh và nghiệm thu riêng:
1. **P2 Hệ giao diện**: nền tảng chung (biến CSS, icon, thẻ, toast, minh hoạ), restyle các màn hình hiện có.
2. **P1 Danh sách cây**: dùng P2.
3. **P3 Nhóm vị trí + Quét trang**: cần trước P4 và P5 vì hai phần sau dùng chung kho chữ có nhóm.
4. **P5 Gợi ý AI**: gắn trước vào bảng dịch của P3.
5. **P4 Dịch tại chỗ**: dùng P3 và P5.

Phần này chen vào `RELEASE-PLAN.md`, giữa M2 và M3 (tương thích). Lý do: M3 và M4 kiểm thử trên giao diện cuối cùng.

## 6. Quyết định sau vòng review (đã chốt)

Một reviewer độc lập đọc bản nháp với vai người lập kế hoạch và nêu các chỗ phải đoán. Chốt như sau:

1. **Chữ gõ thẳng vào template part, khối widget, HTML tuỳ chỉnh** (không qua gettext/option/menu). Để giữ mục tiêu "mọi chữ trên trang", cho phép một ngoại lệ hẹp: thay chữ theo từng khối trong `render_block`, **chỉ** với khối thuộc template part (vùng header/footer/sidebar) và khu vực widget, **chỉ** thay những đoạn chữ đã được đăng ký và có bản dịch. Không đụng vào nội dung bài viết và không xử lý cả trang HTML, nên vẫn khác kiến trúc TranslatePress. `wp_template_part` hiện bị bỏ qua khi quét (`admin/class-lingolane-admin.php:955`), nên P3 phải mở lại cho phần quét chữ, không mở cho việc tạo bản dịch bài. Biểu mẫu của plugin (vd Contact Form 7) được phủ khi plugin in chữ qua gettext hoặc khối. Phần còn lại vào nhóm "Khác" kèm ghi chú "không dịch được tự động" thay vì hứa phủ 100%.
2. **Menu, danh mục và media giữ mô hình hiện có, cộng thêm lối tắt.**
   - Menu: mặc định dịch nhãn từng mục như một chuỗi trên cùng một menu. Nếu site đã có menu riêng cho từng ngôn ngữ (đặt tên theo hậu tố, `class-lingolane-switcher.php:71`) thì menu riêng được ưu tiên, nhóm Menu ghi rõ "đang dùng menu riêng cho ngôn ngữ này". Không chuyển dữ liệu, không bỏ tính năng menu riêng.
   - Danh mục/thẻ: vẫn là term dịch riêng (`tax_*`). Nhóm "Danh mục & thẻ" hiện tên và mô tả để sửa nhanh, và lưu vào term bản dịch (tự tạo nếu chưa có).
   - Media alt/chú thích: là chuỗi mới.
3. **Một chữ có một bản dịch dùng chung mọi vị trí**, giống ngữ nghĩa gettext hiện nay (khoá `domain + context + source`). Nhóm chỉ để duyệt và tính tiến độ. Một chữ xuất hiện ở nhiều vị trí thì hiện trong từng nhóm, sửa ở đâu cũng là cùng một bản.
4. **Quyền:** thêm capability `lingolane_translate`, mặc định cấp cho Administrator. Người có quyền này được dịch, dùng chế độ dịch tại chỗ, bấm gợi ý AI và duyệt bản máy dịch. Nhập hoặc đổi key Gemini và các cài đặt khác vẫn cần `manage_options`. Cách này mở đường cho vai "Người dịch" sau mà không làm ngay.
5. **Duyệt bản dịch tiêu đề bài** dùng chính trạng thái bài: bản dịch tạo từ AI là bản nháp cho tới khi xuất bản.
6. **AI trong Chrome làm được cả "dịch nháp hàng loạt"**, với điều kiện tab quản trị phải mở. Mỗi chữ dịch xong được lưu ngay, nên đóng tab thì phần đã dịch vẫn còn và bấm lại sẽ chạy tiếp phần thiếu. Giao diện nhắc "giữ tab này mở".

## 7. Để người làm tự quyết

- Cách sắp xếp và phân trang theo nhóm trên `edit.php` (vd sửa `posts_clauses`/`the_posts` hay dựng lại thứ tự ở PHP), miễn giữ được các hành vi ở P1 và chạy ổn với 10k bài.
- Lưu trạng thái gập/mở ở `localStorage` hay user meta.
- Schema mở rộng cho chuỗi: nhóm, nguồn (theme/plugin/option/menu/widget), trang thấy chữ, trạng thái dịch máy/người. Cách nâng `DB_VERSION` và chuyển dữ liệu cũ (chuỗi cũ vào nhóm Theme hoặc Khác).
- Cơ chế đánh dấu chữ trong chế độ sửa (vd bọc marker ở gettext/option/menu rồi JS gắn vào DOM), miễn không rò marker ra trang của khách hay ra thuộc tính HTML, `<title>`, JSON.
- Cách "Quét trang" chọn trang để quét (trang chủ, vài bài mới, trang lưu trữ…) và chạy theo lô.
- Chọn model Gemini cụ thể (bản Flash mới nhất còn hỗ trợ ở thời điểm làm) và cách xử lý giới hạn lượt gọi.
- Cách nhận ra trình duyệt có Translator API (feature detection) và cách xử lý cặp ngôn ngữ chưa hỗ trợ.
- Dùng JS thuần như hiện nay hay thêm bước build. Mặc định giữ JS/CSS thuần, chỉ thêm build khi có lý do rõ.
- Nội dung và hình minh hoạ cụ thể (SVG tự vẽ, gọn).
