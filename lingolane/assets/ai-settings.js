/**
 * Lingolane AI Settings Screen Controller
 *
 * Handles Chrome On-Device Translator API detection and Gemini API key connectivity test.
 */
( function( $ ) {
	'use strict';

	$( function() {
		var $badge = $( '#lingolane-chrome-ai-badge' );
		var $status = $( '#lingolane-chrome-ai-status' );
		var $testBtn = $( '#lingolane-test-gemini-btn' );
		var $keyInput = $( '#gemini_api_key' );
		var $resultSpan = $( '#lingolane-gemini-test-result' );

		// 1. Check Chrome On-Device AI support.
		function checkChromeAI() {
			var hasTranslation = ( 'translation' in window && typeof window.translation.canDetect === 'function' ) ||
								 ( 'ai' in window && 'translator' in window.ai );

			if ( hasTranslation ) {
				$badge.removeClass( 'lingolane-chip--neutral' )
					  .addClass( 'lingolane-chip--positive' )
					  .text( 'Khả dụng (On-Device)' );
				$status.html( '<span style="color:#059669; font-weight:600;">✓ Trình duyệt của bạn hỗ trợ mô hình dịch Chrome On-Device.</span> Bản dịch chuỗi giao diện sẽ được xử lý ngay lập tức trên máy tính của bạn.' );
			} else {
				$badge.removeClass( 'lingolane-chip--positive' )
					  .addClass( 'lingolane-chip--neutral' )
					  .text( 'Chưa khả dụng' );
				$status.html( '<span>Trình duyệt hiện tại chưa kích hoạt Chrome Built-in AI Translation API. Hệ thống sẽ tự động dùng Google Gemini Flash API cho các thao tác dịch.</span> (Có thể bật tại <code>chrome://flags/#translation-api</code> trên Chrome 138+).' );
			}
		}

		checkChromeAI();

		// 2. Test Gemini API Key.
		$testBtn.on( 'click', function( e ) {
			e.preventDefault();
			var key = $.trim( $keyInput.val() );

			$resultSpan.text( '' ).removeAttr( 'style' );

			if ( ! key ) {
				$resultSpan.css( 'color', '#dc2626' ).text( ( window.lingolaneAISettings && window.lingolaneAISettings.i18n && window.lingolaneAISettings.i18n.emptyKey ) || 'Vui lòng nhập API Key.' );
				return;
			}

			$testBtn.prop( 'disabled', true ).text( ( window.lingolaneAISettings && window.lingolaneAISettings.i18n && window.lingolaneAISettings.i18n.testing ) || 'Đang kiểm tra...' );

			$.ajax( {
				url: ( window.lingolaneAISettings && window.lingolaneAISettings.ajax ) || ajaxurl,
				type: 'POST',
				data: {
					action: 'lingolane_ai_test_key',
					nonce: ( window.lingolaneAISettings && window.lingolaneAISettings.nonce ) || '',
					api_key: key
				},
				success: function( response ) {
					$testBtn.prop( 'disabled', false ).text( 'Kiểm tra kết nối' );
					if ( response && response.success ) {
						$resultSpan.css( 'color', '#059669' ).text( '✓ ' + ( response.data && response.data.message ? response.data.message : 'Kết nối thành công!' ) );
					} else {
						var msg = ( response && response.data && response.data.message ) ? response.data.message : 'Lỗi không xác định.';
						$resultSpan.css( 'color', '#dc2626' ).text( '✗ ' + msg );
					}
				},
				error: function( xhr ) {
					$testBtn.prop( 'disabled', false ).text( 'Kiểm tra kết nối' );
					var msg = 'Lỗi kết nối máy chủ.';
					if ( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
						msg = xhr.responseJSON.data.message;
					}
					$resultSpan.css( 'color', '#dc2626' ).text( '✗ ' + msg );
				}
			} );
		} );
	} );
} )( jQuery );
