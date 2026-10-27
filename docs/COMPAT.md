# Lingolane 1.0 Compatibility Matrix

Updated: 2026-10-26 (After the release audit).

Strict status definitions:
- **VERIFIED:** Verified through the test suite or in a Local by Flywheel environment.
- **CONTAINED / FAIL-CLOSED:** Safety mechanisms have been added (blocking activation or refusing execution to protect data).
- **NOT YET TESTED:** Not yet tested on an actual site with these plugins installed. Must not be advertised as fully compatible.

---

## 1. SEO & Sitemaps

| Component | Status | Verification Evidence |
|---|---|---|
| Yoast SEO (`WPSEO_VERSION`) | VERIFIED (Adapter hooks) | Adapter unit test, canonical filter, hreflang, sitemap entry filter. |
| Rank Math SEO (`RANK_MATH_VERSION`) | VERIFIED (Adapter hooks) | Canonical filter, sitemap entry filter through `register_seo_adapters()`. |
| All in One SEO (`AIOSEO_VERSION`) | VERIFIED (Adapter hooks) | Canonical filter through `register_seo_adapters()`. |
| SEOPress (`SEOPRESS_VERSION`) | VERIFIED (Adapter hooks) | Canonical filter, single entry sitemap filter. |
| WordPress Core XML Sitemaps | VERIFIED | `wp_sitemaps_posts_entry`, `wp_sitemaps_taxonomies_entry`. |

---

## 2. WPML Ecosystem Shim

| Component | Status | Verification Evidence |
|---|---|---|
| WPML API Shim (`wpml_object_id`, `wpml_default_language`) | VERIFIED | `class-lingolane-wpml-shim.php`, `test-wpml-shim.php`. |
| `wpml-config.xml` Parser | VERIFIED (Active parsing) | Reads custom-fields and admin-texts from parent and child themes. |
| WPML Taxonomy filter runtime | NOT YET TESTED | Requires a real environment to verify hook timing. |

---

## 3. System Architecture & Environment

| Component | Status | Verification Evidence |
|---|---|---|
| Single Site (Subdirectory URLs) | VERIFIED | Works correctly on `check.local` (`/`, `/vi/`, `/fr/`). |
| Multisite Network Activation | CONTAINED (FAIL-CLOSED) | Blocks network-wide activation in `Lingolane_Install::activate($network_wide)` with `wp_die`. |
| Page Builders (Elementor, Divi, Bricks) | NOT YET TESTED | No runtime QA suite has been run on a site with a builder. |
| WooCommerce Store & Checkout | NOT YET TESTED | Bilingual shopping cart testing has not been performed. |
| Cloud AI Integration (Gemini) | CONTAINED (FAIL-CLOSED) | The server endpoint and auto-fallback are temporarily disabled to protect data and control costs. |
