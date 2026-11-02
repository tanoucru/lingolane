# Changelog
## 1.0.1
- WordPress.org review: SVG flags, icons and switcher output escaped with `wp_kses()` at the point of output (`Lingolane_Kses`); request input sanitized on read (`wp_kses_post()` for translations, `Lingolane_Strings::clean_source()` for source keys).
- Switcher CSS/JS enqueued; inline `onchange` handlers moved to `switcher.js` / `admin.js`.
- Removed bundled `.po/.mo` and `load_plugin_textdomain()`; translations come from translate.wordpress.org.
- `lingolane__()` reads the domain catalog and the string table directly instead of calling `__()` with variables.
- Router notices limited to the Plugins, Permalinks and Lingolane screens.
## 1.0.0
- Split out from Teachme (teachme-multilingual 1.2). Renamed to Lingolane, text domain `lingolane`.
- Removed Teachme dependencies: page slugs, CPTs, and taxonomies are now filters; `is_tutor` → `is_gated`.
- Default languages are en + vi.
- Support tab: compose a ticket for the wordpress.org forum together with a system report (copy/download .txt, no data is sent externally), a warning that multilingual/cache plugins may conflict, a link for reporting security issues privately; "Support" link in the plugin list.
- Support tab (after an independent review round): masks emails/URLs/domain names in the report, includes network-activated plugins (multisite), opens the forum only after the ticket has been copied; the security report button points to the WordPress.org guidelines; added `SECURITY.md`.
- Security reports go through GitHub private vulnerability reporting instead of email.
