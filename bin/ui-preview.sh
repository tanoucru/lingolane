#!/usr/bin/env bash
# Build, install on the Local site "check" and render every admin tab to dist/ui-preview/<tab>.html.
# The pages carry copies of the WordPress core and plugin CSS/JS they use, so they open without a login
# (python3 -m http.server --directory dist/ui-preview, or straight from disk).
set -euo pipefail
cd "$(dirname "$0")/.."
wpc=/tmp/wpc.sh
[ -x "$wpc" ] || { echo "Missing $wpc (WP-CLI wrapper for the Local site check)" >&2; exit 1; }

zip=$(bin/build.sh)
"$wpc" plugin install "$zip" --force >/dev/null

out="$PWD/dist/ui-preview"
rm -rf "$out"
mkdir -p "$out/assets"
renderer=$(mktemp "${TMPDIR:-/tmp}/lingolane-preview.XXXXXX")
trap 'rm -f "$renderer"' EXIT

cat > "$renderer" <<'PHP'
<?php
// Runs inside `wp eval-file`: renders one Lingolane admin tab as a standalone HTML page.
list( $tab, $out ) = array( $args[0], $args[1] );
require_once ABSPATH . 'wp-admin/includes/admin.php';
foreach ( array( 'stats', 'ui', 'admin', 'strings-admin', 'metabox', 'columns' ) as $file ) {
	require_once LINGOLANE_DIR . 'admin/class-lingolane-' . $file . '.php';
}
set_current_screen( 'toplevel_page_lingolane' );
$_GET['page'] = 'lingolane';
$_GET['tab']  = $tab;
if ( in_array( $tab, array( 'overview', 'settings' ), true ) ) {
	Lingolane_Admin::flash( 'success', __( 'Settings saved.', 'lingolane' ) );
}

ob_start();
Lingolane_Admin::render();
$body = ob_get_clean();

Lingolane_Admin::enqueue( 'toplevel_page_lingolane' );
ob_start();
wp_print_styles( array( 'common', 'forms', 'buttons', 'list-tables', 'lingolane-admin' ) );
$head = ob_get_clean();
ob_start();
wp_print_scripts( array( 'lingolane-admin' ) );
$foot = ob_get_clean();

$html = '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8">'
	. '<meta name="viewport" content="width=device-width, initial-scale=1">'
	. '<title>Lingolane – ' . esc_html( $tab ) . '</title>' . $head . '</head>'
	. '<body class="wp-admin wp-core-ui js auto-fold lingolane-screen"><div id="wpwrap"><div id="wpcontent"><div id="wpbody"><div id="wpbody-content">'
	. $body . '</div></div></div></div>' . $foot . '</body></html>';

// Copy every local asset the page references and point the page at the copies.
$site = untrailingslashit( site_url() );
preg_match_all( '#' . preg_quote( $site, '#' ) . '/([^"\'?\s]+\.(?:css|js))(?:\?[^"\'\s]*)?#', $html, $m, PREG_SET_ORDER );
foreach ( $m as $hit ) {
	$src = ABSPATH . $hit[1];
	$dst = dirname( $out ) . '/assets/' . $hit[1];
	if ( ! is_file( $src ) ) { continue; }
	wp_mkdir_p( dirname( $dst ) );
	copy( $src, $dst );
	$html = str_replace( $hit[0], 'assets/' . $hit[1], $html );
}
file_put_contents( $out, $html );
echo $out, "\n";
PHP

for tab in overview content strings languages settings guide; do
  "$wpc" eval-file "$renderer" "$tab" "$out/$tab.html" --user=1
done
