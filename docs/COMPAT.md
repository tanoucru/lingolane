# Ma trận tương thích Lingolane 1.0 (Compatibility Matrix)

Cập nhật: 2026-10-26 (Sau audit phát hành).

Phân định nghiêm ngặt:
- **VERIFIED:** Đã kiểm chứng thực tế bằng test suite hoặc trên môi trường Local by Flywheel.
- **CONTAINED / FAIL-CLOSED:** Đã bổ sung cơ chế bảo vệ an toàn (chặn kích hoạt hoặc từ chối thực thi để bảo vệ dữ liệu).
- **NOT YET TESTED:** Chưa chạy thử nghiệm trên site thật có cài đặt các plugin này. Tuyệt đối không quảng bá là đã tương thích hoàn toàn.

---

## 1. SEO & Sitemaps

| Thành phần | Trạng thái | Bằng chứng kiểm chứng |
|---|---|---|
| Yoast SEO (`WPSEO_VERSION`) | VERIFIED (Adapter hooks) | Unit test adapter, canonical filter, hreflang, sitemap entry filter. |
| Rank Math SEO (`RANK_MATH_VERSION`) | VERIFIED (Adapter hooks) | Canonical filter, sitemap entry filter qua `register_seo_adapters()`. |
| All in One SEO (`AIOSEO_VERSION`) | VERIFIED (Adapter hooks) | Canonical filter qua `register_seo_adapters()`. |
| SEOPress (`SEOPRESS_VERSION`) | VERIFIED (Adapter hooks) | Canonical filter, single entry sitemap filter. |
| WordPress Core XML Sitemaps | VERIFIED | `wp_sitemaps_posts_entry`, `wp_sitemaps_taxonomies_entry`. |

---

## 2. WPML Ecosystem Shim

| Thành phần | Trạng thái | Bằng chứng kiểm chứng |
|---|---|---|
| WPML API Shim (`wpml_object_id`, `wpml_default_language`) | VERIFIED | `class-lingolane-wpml-shim.php`, `test-wpml-shim.php`. |
| `wpml-config.xml` Parser | VERIFIED (Chủ động parse) | Đọc custom-fields, admin-texts từ theme cha và con. |
| WPML Taxonomy filter runtime | NOT YET TESTED | Cần môi trường thực tế để xác minh độ trễ hook. |

---

## 3. Kiến trúc hệ thống & Môi trường

| Thành phần | Trạng thái | Bằng chứng kiểm chứng |
|---|---|---|
| Single Site (Subdirectory URLs) | VERIFIED | Hoạt động chuẩn xác trên `check.local` (`/`, `/vi/`, `/fr/`). |
| Multisite Network Activation | CONTAINED (FAIL-CLOSED) | Chặn kích hoạt toàn mạng tại `Lingolane_Install::activate($network_wide)` kèm `wp_die`. |
| Page Builders (Elementor, Divi, Bricks) | NOT YET TESTED | Chưa có runtime QA suite trên site có builder. |
| WooCommerce Store & Checkout | NOT YET TESTED | Chưa có kiểm thử giỏ hàng song ngữ. |
| Cloud AI Integration (Gemini) | CONTAINED (FAIL-CLOSED) | Tạm khóa server endpoint và auto-fallback để bảo vệ dữ liệu và chi phí. |
