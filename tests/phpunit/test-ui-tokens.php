<?php
/**
 * Admin UI design tokens: WCAG contrast of text pairs, CSS scoping and the page body class.
 */
class Test_Lingolane_UI_Tokens extends Lingolane_TestCase {

	public static function wpSetUpBeforeClass() {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		require_once LINGOLANE_DIR . 'admin/class-lingolane-admin.php';
	}

	private function css() {
		return (string) file_get_contents( LINGOLANE_DIR . 'assets/admin.css' );
	}

	private function tokens() {
		preg_match_all( '/--lingolane-([a-z0-9-]+):\s*(#[0-9a-fA-F]{3,6})/', $this->css(), $m, PREG_SET_ORDER );
		$tokens = array();
		foreach ( $m as $row ) {
			$tokens[ $row[1] ] = $row[2];
		}
		return $tokens;
	}

	private function luminance( $hex ) {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$out = array();
		foreach ( array( 0, 2, 4 ) as $i ) {
			$c     = hexdec( substr( $hex, $i, 2 ) ) / 255;
			$out[] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $out[0] + 0.7152 * $out[1] + 0.0722 * $out[2];
	}

	private function contrast( $fg, $bg ) {
		$a = $this->luminance( $fg );
		$b = $this->luminance( $bg );
		return ( max( $a, $b ) + 0.05 ) / ( min( $a, $b ) + 0.05 );
	}

	public function test_text_pairs_meet_wcag_aa() {
		$t     = $this->tokens();
		$pairs = array(
			array( 'ink', 'card' ),
			array( 'ink', 'bg' ),
			array( 'muted', 'card' ),
			array( 'muted', 'bg' ),
			array( 'accent-ink', 'accent-soft' ),
			array( 'ok', 'ok-bg' ),
			array( 'warn', 'warn-bg' ),
			array( 'danger', 'danger-bg' ),
			array( 'info', 'info-bg' ),
		);
		foreach ( $pairs as $pair ) {
			$this->assertArrayHasKey( $pair[0], $t, "token {$pair[0]} missing" );
			$this->assertArrayHasKey( $pair[1], $t, "token {$pair[1]} missing" );
			$this->assertGreaterThanOrEqual( 4.5, $this->contrast( $t[ $pair[0] ], $t[ $pair[1] ] ), "{$pair[0]} on {$pair[1]}" );
		}
		$this->assertArrayHasKey( 'accent', $t );
		$this->assertGreaterThanOrEqual( 4.5, $this->contrast( '#ffffff', $t['accent'] ), 'white on accent' );
	}

	public function test_every_selector_is_scoped() {
		$css = preg_replace( '#/\*.*?\*/#s', '', $this->css() );
		// Drop @keyframes bodies (nested braces).
		$css = preg_replace( '/@(?:-\w+-)?keyframes[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/s', '', $css );
		preg_match_all( '/([^{}]+)\{/', $css, $m );
		$bad = array();
		foreach ( $m[1] as $block ) {
			$block = trim( $block );
			if ( '' === $block || 0 === strpos( $block, '@' ) ) {
				continue;
			}
			foreach ( explode( ',', $block ) as $selector ) {
				$selector = trim( $selector );
				if ( '' !== $selector && ! preg_match( '/^(\.lingolane|body\.lingolane-screen|body\.lingolane-tree|\.column-lingolane_language|\.wp-list-table \.column-lingolane_language|:root)/', $selector ) ) {
					$bad[] = $selector;
				}
			}
		}
		$this->assertSame( array(), $bad, 'Unscoped selectors: ' . implode( ' | ', $bad ) );
	}

	public function test_body_class_only_on_lingolane_screen() {
		set_current_screen( 'toplevel_page_lingolane' );
		$this->assertStringContainsString( 'lingolane-screen', Lingolane_Admin::body_class( 'a' ) );
		set_current_screen( 'edit-post' );
		$this->assertSame( 'a', Lingolane_Admin::body_class( 'a' ) );
	}
}
