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
		var i18n = ( window.lingolaneAISettings && window.lingolaneAISettings.i18n ) || {};
		var t = function( key, fallback ) {
			return i18n[ key ] || fallback;
		};

		// 1. Check Chrome On-Device AI support.
		function checkChromeAI() {
			var hasTranslation = ( 'translation' in window && typeof window.translation.canDetect === 'function' ) ||
								 ( 'ai' in window && 'translator' in window.ai );

			if ( hasTranslation ) {
				$badge.removeClass( 'lingolane-chip--neutral' )
					  .addClass( 'lingolane-chip--positive' )
					  .text( t( 'available', 'Available (on-device)' ) );
				$status.empty().append( $( '<span style="color:#059669; font-weight:600;"></span>' ).text( '✓ ' + t( 'chromeReady', 'This browser supports the Chrome on-device Translator API.' ) ) );
			} else {
				$badge.removeClass( 'lingolane-chip--positive' )
					  .addClass( 'lingolane-chip--neutral' )
					  .text( t( 'unavailable', 'Not available' ) );
				$status.text( t( 'chromeMissing', 'This browser does not have the Chrome built-in Translator API enabled.' ) );
			}
		}

		checkChromeAI();

		// 2. Test Gemini API Key.
		$testBtn.on( 'click', function( e ) {
			e.preventDefault();
			var key = $.trim( $keyInput.val() );

			$resultSpan.text( '' ).removeAttr( 'style' );

			if ( ! key ) {
				$resultSpan.css( 'color', '#dc2626' ).text( t( 'emptyKey', 'Enter an API key before testing.' ) );
				return;
			}

			$testBtn.prop( 'disabled', true ).text( t( 'testing', 'Testing connection…' ) );

			$.ajax( {
				url: ( window.lingolaneAISettings && window.lingolaneAISettings.ajax ) || ajaxurl,
				type: 'POST',
				data: {
					action: 'lingolane_ai_test_key',
					nonce: ( window.lingolaneAISettings && window.lingolaneAISettings.nonce ) || '',
					api_key: key
				},
				success: function( response ) {
					$testBtn.prop( 'disabled', false ).text( t( 'testButton', 'Test connection' ) );
					if ( response && response.success ) {
						$resultSpan.css( 'color', '#059669' ).text( '✓ ' + ( response.data && response.data.message ? response.data.message : t( 'testSuccess', 'Connection successful.' ) ) );
					} else {
						var msg = ( response && response.data && response.data.message ) ? response.data.message : t( 'unknownError', 'Unknown error.' );
						$resultSpan.css( 'color', '#dc2626' ).text( '✗ ' + msg );
					}
				},
				error: function( xhr ) {
					$testBtn.prop( 'disabled', false ).text( t( 'testButton', 'Test connection' ) );
					var msg = t( 'serverError', 'Could not reach the server.' );
					if ( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
						msg = xhr.responseJSON.data.message;
					}
					$resultSpan.css( 'color', '#dc2626' ).text( '✗ ' + msg );
				}
			} );
		} );
	} );
} )( jQuery );
