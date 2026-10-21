=== Lingolane ===
Contributors: tannguyen
Tags: multilingual, translation, hreflang, language switcher, i18n
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0-dev
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight multilingual for WordPress: translate posts, pages and terms, translate interface strings, language URLs, hreflang and a language switcher.

== Description ==

Lingolane lets you run one WordPress site in several languages without a heavy toolchain.

* Translation groups for posts, pages and terms. Create a translation with one click: content, images, custom fields and terms are copied to a new draft.
* The default language has no URL prefix; other languages live in their own directory (for example /vi/).
* Untranslated pages open the original with a notice instead of a 404.
* hreflang, x-default, canonical and the html lang attribute are added automatically. Works with Yoast SEO.
* Internal links inside content switch to the matching translation.
* Interface strings: scan your theme and plugins, translate in a table, import and export CSV.
* Language switcher: the [lingolane_switcher] shortcode, a widget, or a menu item.
* Developer filters let you share pages, post types and taxonomies across all languages.

== Installation ==

1. Upload the `lingolane` folder to `/wp-content/plugins/` or install it from the Plugins screen.
2. Activate the plugin.
3. Go to Lingolane in the admin menu, choose your languages and start translating.

== Frequently Asked Questions ==

= Does it work with multisite? =
Not yet. Version 1.0 supports single sites.

= Does it work with page builders and WooCommerce? =
Content created with the block editor and the classic editor is supported. Other integrations are being tested; see the compatibility notes in the project repository.

= What happens to my data if I uninstall? =
Translation relationships and settings are kept so nothing is lost by accident.

== Changelog ==

= 1.0.0 =
* First public release. Based on the multilingual module of the Teachme project, rebuilt as a standalone plugin.
