<?php
/**
 * Regression & Containment Tests for AI Safety Remediation (LL-001 Step 1).
 *
 * Verifies that:
 * 1. Cloud Gemini calls are disabled fail-closed until server quota/atomic race/opt-in are fully implemented.
 * 2. translate_post fails closed with explicit error, preventing duplicate_post overwrite / partial translation corruption.
 * 3. Client does not silently route data to Cloud API without consent.
 */
class Test_Lingolane_AI_Remediation extends Lingolane_TestCase {

	public static function wpSetUpBeforeClass() {
		require_once LINGOLANE_DIR . 'includes/class-lingolane-ai.php';
		require_once LINGOLANE_DIR . 'includes/class-lingolane-ai-post.php';
	}

	public function test_gemini_translation_is_disabled_fail_closed() {
		$res = Lingolane_AI::translate_text( 'Hello world', 'en', 'vi' );
		$this->assertInstanceOf( 'WP_Error', $res );
		$this->assertSame( 'ai_cloud_disabled', $res->get_error_code() );
	}

	public function test_gemini_test_api_key_is_disabled_fail_closed() {
		$res = Lingolane_AI::test_api_key( 'AIzaSyFakeKeyForTest' );
		$this->assertIsArray( $res );
		$this->assertFalse( $res['success'] );
		$this->assertStringContainsString( 'tạm thời bị vô hiệu hóa', $res['message'] );
	}

	public function test_translate_post_fails_closed_preventing_overwrite() {
		$post_id = $this->factory->post->create( array( 'post_title' => 'Original Post' ) );
		$res     = Lingolane_AI_Post::translate_post( $post_id, 'vi' );

		$this->assertInstanceOf( 'WP_Error', $res );
		$this->assertSame( 'ai_post_translation_disabled', $res->get_error_code() );
	}
}
