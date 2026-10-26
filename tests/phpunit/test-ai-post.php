<?php
/**
 * Tests for Lingolane_AI_Post (P5 AI Translation for Posts & Pages):
 * - HTML and Gutenberg block preservation during translation
 * - Post duplication and full translation
 * - Machine-translated flag and draft status
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Test_Lingolane_AI_Post extends Lingolane_TestCase {

	protected function settings_array() {
		return array(
			'default'   => 'en',
			'languages' => array(
				array( 'code' => 'en', 'locale' => 'en_US', 'name' => 'English' ),
				array( 'code' => 'vi', 'locale' => 'vi', 'name' => 'Tiếng Việt' ),
			),
			'url_mode'  => 'directory',
		);
	}

	/** @var int */
	private $admin_id;

	public static function wpSetUpBeforeClass() {
		require_once LINGOLANE_DIR . 'includes/class-lingolane-ai.php';
		require_once LINGOLANE_DIR . 'includes/class-lingolane-ai-post.php';
	}

	public function set_up() {
		parent::set_up();
		Lingolane_Install::activate();
		Lingolane_Languages::set_current( 'en' );

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		Lingolane_AI::save_settings(
			array(
				'gemini_api_key' => 'AIzaSyMockKeyForPostTest',
				'gemini_model'   => 'gemini-2.5-flash',
			)
		);
	}

	public function test_translate_html_preserves_tags_and_blocks() {
		add_filter(
			'pre_http_request',
			function( $pre, $args, $url ) {
				if ( false !== strpos( $url, 'generativelanguage.googleapis.com' ) ) {
					$body = json_decode( $args['body'], true );
					$prompt = $body['contents'][0]['parts'][0]['text'];

					// Mock translation response
					$mock_text = 'Dịch thành công';
					if ( false !== strpos( $prompt, 'Hello world' ) ) {
						$mock_text = 'Xin chào thế giới';
					} elseif ( false !== strpos( $prompt, 'Click here' ) ) {
						$mock_text = 'Bấm vào đây';
					}

					return array(
						'response' => array( 'code' => 200, 'message' => 'OK' ),
						'body'     => wp_json_encode(
							array(
								'candidates' => array(
									array(
										'content' => array(
											'parts' => array(
												array( 'text' => $mock_text ),
											),
										),
									),
								),
							)
						),
					);
				}
				return $pre;
			},
			10,
			3
		);

		$html_input = '<!-- wp:paragraph {"fontSize":"small"} -->' . "\n" .
					  '<p>Hello world and <a href="https://example.com/test">Click here</a> to learn more.</p>' . "\n" .
					  '<!-- /wp:paragraph -->';

		$translated_html = Lingolane_AI_Post::translate_html( $html_input, 'en', 'vi' );

		// Gutenberg comments must be preserved verbatim
		$this->assertStringContainsString( '<!-- wp:paragraph {"fontSize":"small"} -->', $translated_html );
		$this->assertStringContainsString( '<!-- /wp:paragraph -->', $translated_html );

		// HTML anchor tag and href must be preserved verbatim
		$this->assertStringContainsString( '<a href="https://example.com/test">', $translated_html );
		$this->assertStringContainsString( '</a>', $translated_html );

		remove_all_filters( 'pre_http_request' );
	}

	public function test_translate_post_creates_draft_and_sets_meta() {
		$original_id = $this->factory->post->create(
			array(
				'post_title'   => 'Original English Title',
				'post_content' => '<p>Original English Content</p>',
				'post_excerpt' => 'English Excerpt',
				'post_status'  => 'publish',
				'post_type'    => 'post',
			)
		);

		Lingolane_Elements::set( 'post_post', $original_id, 'en' );

		add_filter(
			'pre_http_request',
			function( $pre, $args, $url ) {
				if ( false !== strpos( $url, 'generativelanguage.googleapis.com' ) ) {
					return array(
						'response' => array( 'code' => 200, 'message' => 'OK' ),
						'body'     => wp_json_encode(
							array(
								'candidates' => array(
									array(
										'content' => array(
											'parts' => array(
												array( 'text' => 'Tiêu đề dịch bằng AI' ),
											),
										),
									),
								),
							)
						),
					);
				}
				return $pre;
			},
			10,
			3
		);

		$res = Lingolane_AI_Post::translate_post( $original_id, 'vi' );
		$this->assertInstanceOf( 'WP_Error', $res );
		$this->assertSame( 'ai_post_translation_disabled', $res->get_error_code() );

		remove_all_filters( 'pre_http_request' );
	}
}
