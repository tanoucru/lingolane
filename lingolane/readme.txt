=== Lingolane ===
Contributors: tannguyen
Tags: multilingual, translation, hreflang, language switcher, i18n
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Run your WordPress site in many languages: one-click translation drafts, clean /vi/ URLs, automatic hreflang, string translation and a switcher.

== Description ==

Lingolane turns one WordPress site into a multilingual site without a second install, a translation service or a heavy toolchain. Each language gets its own lane: its own URL, its own content and its own interface strings, all managed from the screens you already use.

= Translate content =

* **One-click translation drafts.** Create a translation of any post or page. Content, images, custom fields and terms are copied to a new draft, ready to translate.
* **Translation groups at a glance.** The Pages and Posts lists group each original with its translations, show progress and offer a row to create the ones that are missing.
* **Translate on the page itself.** Turn on translation mode from the admin bar, click any text on the front end and type the translation in place.
* **On-device suggestions.** In browsers that ship a built-in translator (such as recent Chrome), Lingolane suggests a translation locally. The text never leaves the browser.

= Translate the rest of the site =

* **Find every string.** Scan your theme and plugins, or scan a live page, to collect site title, menus, widgets, footer text and other interface strings.
* **Grouped by where they appear.** Strings are sorted into Header, Menus, Footer, Widgets, Categories & Tags and other groups, so you translate what you can see.
* **A fast translation table.** Edit inline with keyboard shortcuts, and import or export CSV to work with a translator.

= Get found in every language =

* **Clean language URLs.** The default language has no prefix; other languages live in their own directory, for example /vi/ or /fr/.
* **SEO handled for you.** hreflang, x-default, canonical and the html lang attribute are added automatically. With Yoast SEO, canonicals, Open Graph, schema and sitemaps follow the language too.
* **Links follow the reader.** Internal links inside content point to the matching translation.
* **No dead ends.** A page that is not translated yet opens the original with a notice, never a 404.

= Light to run =

* **130+ languages.** Any language WordPress is translated into. Only the languages you add are downloaded, and WordPress keeps their translations up to date.
* **A switcher anywhere.** Use the block, the [lingolane_switcher] shortcode, a widget or a menu item.
* **Works with your theme.** Lingolane reads the wpml-config.xml files that many themes and plugins ship, and answers the common multilingual hooks they call.
* **Nothing extra to load.** No external fonts, no CDN, no tracking. The only outside connection is to WordPress.org, for language packs.
* **Built for developers too.** Filters let you choose translatable post types and share pages, post types and taxonomies across all languages.

== Installation ==

1. Upload the `lingolane` folder to `/wp-content/plugins/` or install it from the Plugins screen.
2. Activate the plugin.
3. Go to Lingolane in the admin menu, choose your languages and start translating.

== Frequently Asked Questions ==

= Does it work with multisite? =
Not yet. Version 1.0 supports single sites.

= Does it work with page builders and WooCommerce? =
Content created with the block editor and the classic editor is supported. Other integrations are being tested; see the compatibility notes in the project repository.

= Does the plugin connect to external services? =
By default, Lingolane only connects to WordPress.org: the Languages screen queries api.wordpress.org for available translation packs, and downloads them from downloads.wordpress.org. No personal content is sent.
AI Translation features prioritize Chrome on-device translation APIs (running locally inside your browser with no external server requests). Third-party cloud AI integrations (such as Google Gemini) are disabled by default and fail-closed until an explicit opt-in policy and atomic quota safeguards are enabled. No translation text is sent to third-party cloud services without explicit administrator consent.

= Where can I get help? =
Open Multilingual > Support in the admin. The screen helps you write a ticket and copies a system report (versions, theme, Lingolane settings and active plugins; site address, emails and links are left out or masked) that you review and paste into the [support forum](https://wordpress.org/support/plugin/lingolane/). Nothing is sent from your site. Please report security issues privately using the link on that screen, not in the public forum.

= What happens to my data if I uninstall? =
Translation relationships and settings are kept so nothing is lost by accident.

== Changelog ==

= 1.0.0 =
* Location-based string translation: 10 logical location groups (Site Info, Header, Menus, Footer, Widgets, Theme, Plugins, Categories & Tags, Media, Other) for intuitive string discovery.
* Frontend page scan mode: automatically discover translatable strings and record exact locations on live pages.
* Multilingual translation table: inline editing with dirty state tracking, keyboard shortcuts, and instant saving.
* Taxonomy terms integration: direct editing and synchronization of categories and tags within string translation.
* Developer assistance: copy element identifiers and highlight frontend strings directly in context.
* First public release. Based on the multilingual module of the Teachme project, rebuilt as a standalone plugin.
