/**
 * Lingolane Dual-Engine Client-Side AI Translation Controller (P5)
 *
 * Provides a unified window.LingolaneAI translation interface:
 * 1. Checks Chrome On-Device Translator API (Chrome 138+ desktop or flags enabled).
 *    If available, translates instantly on device without server requests, 100% free & private.
 * 2. If Chrome AI is not available or encounters errors, falls back transparently to
 *    the server-side Google Gemini Flash API via WordPress AJAX.
 *
 * @package Lingolane
 */
( function( $, window ) {
	'use strict';

	var LingolaneAI = window.LingolaneAI || {};

	/**
	 * Normalize language codes to ISO 639-1 (e.g. 'en_US' -> 'en', 'vi_VN' -> 'vi').
	 *
	 * @param {string} code Language code or locale.
	 * @return {string} 2-letter code or original if shorter.
	 */
	function normalizeLang( code ) {
		if ( ! code || typeof code !== 'string' ) {
			return 'auto';
		}
		var clean = code.toLowerCase().trim().replace( '_', '-' );
		if ( clean.indexOf( '-' ) !== -1 ) {
			clean = clean.split( '-' )[0];
		}
		return clean;
	}

	/**
	 * Detect if Chrome On-Device Translator API is supported and ready for a language pair.
	 *
	 * @param {string} sourceLang Source language (e.g. 'en').
	 * @param {string} targetLang Target language (e.g. 'vi').
	 * @return {Promise<boolean>}
	 */
	LingolaneAI.isChromeAvailable = async function( sourceLang, targetLang ) {
		sourceLang = normalizeLang( sourceLang );
		targetLang = normalizeLang( targetLang );

		// Standard Chrome 138+ translation API: window.translation
		if ( 'translation' in window && typeof window.translation.canTranslate === 'function' ) {
			try {
				var status = await window.translation.canTranslate( {
					sourceLanguage: sourceLang,
					targetLanguage: targetLang
				} );
				return status === 'readily' || status === 'after-download';
			} catch ( e ) {
				return false;
			}
		}

		// Early experimental API: window.ai.translator
		if ( 'ai' in window && 'translator' in window.ai && typeof window.ai.translator.capabilities === 'function' ) {
			try {
				var caps = await window.ai.translator.capabilities();
				if ( caps && typeof caps.languagePairAvailable === 'function' ) {
					var avail = caps.languagePairAvailable( sourceLang, targetLang );
					return avail === 'readily' || avail === 'after-download';
				}
			} catch ( e ) {
				return false;
			}
		}

		return false;
	};

	/**
	 * Translate text using Chrome On-Device Translator API.
	 *
	 * @param {string} text Text to translate.
	 * @param {string} sourceLang Source language.
	 * @param {string} targetLang Target language.
	 * @return {Promise<string>}
	 */
	async function translateWithChrome( text, sourceLang, targetLang ) {
		sourceLang = normalizeLang( sourceLang );
		targetLang = normalizeLang( targetLang );

		if ( 'translation' in window && typeof window.translation.createTranslator === 'function' ) {
			var translator = await window.translation.createTranslator( {
				sourceLanguage: sourceLang,
				targetLanguage: targetLang
			} );
			return await translator.translate( text );
		}

		if ( 'ai' in window && 'translator' in window.ai && typeof window.ai.translator.create === 'function' ) {
			var altTranslator = await window.ai.translator.create( {
				sourceLanguage: sourceLang,
				targetLanguage: targetLang
			} );
			return await altTranslator.translate( text );
		}

		throw new Error( 'Chrome on-device translation API not available.' );
	}

	/**
	 * Translate text using server-side Gemini Flash API via WordPress AJAX.
	 *
	 * @param {string} text Text to translate.
	 * @param {string} sourceLang Source language.
	 * @param {string} targetLang Target language.
	 * @param {string} context Optional context hint.
	 * @return {Promise<object>}
	 */
	function translateWithGemini( text, sourceLang, targetLang, context ) {
		return new Promise( function( resolve, reject ) {
			var ajaxUrl = ( window.lingolaneAIConfig && window.lingolaneAIConfig.ajax ) ||
						  ( window.lingolaneAdmin && window.lingolaneAdmin.ajax ) ||
						  ( window.lingolaneInContextData && window.lingolaneInContextData.ajaxUrl ) ||
						  ( typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php' );

			var nonce = ( window.lingolaneAIConfig && window.lingolaneAIConfig.nonce ) ||
						( window.lingolaneAISettings && window.lingolaneAISettings.nonce ) ||
						'';

			$.ajax( {
				url: ajaxUrl,
				type: 'POST',
				data: {
					action: 'lingolane_ai_translate',
					nonce: nonce,
					text: text,
					source_lang: sourceLang || 'auto',
					target_lang: targetLang,
					context: context || ''
				},
				success: function( res ) {
					if ( res && res.success && res.data && typeof res.data.translation === 'string' ) {
						resolve( {
							translation: res.data.translation,
							engine: 'gemini'
						} );
					} else {
						var msg = ( res && res.data && res.data.message ) ? res.data.message : 'Máy chủ không trả về bản dịch.';
						reject( new Error( msg ) );
					}
				},
				error: function( xhr ) {
					var msg = 'Lỗi kết nối máy chủ dịch AI.';
					if ( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
						msg = xhr.responseJSON.data.message;
					}
					reject( new Error( msg ) );
				}
			} );
		} );
	}

	/**
	 * Primary translation entry point.
	 * Checks Chrome On-Device first; automatically falls back to Gemini server.
	 *
	 * @param {string} text Text to translate.
	 * @param {string} sourceLang Source language code (e.g. 'en').
	 * @param {string} targetLang Target language code (e.g. 'vi').
	 * @param {string} context Optional context note.
	 * @return {Promise<object>} Object with { translation: string, engine: 'chrome-ondevice' | 'gemini' }.
	 */
	LingolaneAI.translate = async function( text, sourceLang, targetLang, context ) {
		text = ( text || '' ).toString().trim();
		if ( ! text ) {
			return { translation: '', engine: 'none' };
		}

		sourceLang = normalizeLang( sourceLang || 'auto' );
		targetLang = normalizeLang( targetLang || 'vi' );

		// 1. Try Chrome On-Device AI if pair is supported
		if ( sourceLang !== 'auto' && sourceLang !== targetLang ) {
			try {
				var chromeSupported = await LingolaneAI.isChromeAvailable( sourceLang, targetLang );
				if ( chromeSupported ) {
					var chromeResult = await translateWithChrome( text, sourceLang, targetLang );
					if ( chromeResult && typeof chromeResult === 'string' ) {
						return {
							translation: chromeResult.trim(),
							engine: 'chrome-ondevice'
						};
					}
				}
			} catch ( err ) {
				// Chrome translation failed or pair download pending; gracefully continue to Gemini
				if ( window.console && console.warn ) {
					console.warn( '[LingolaneAI] Chrome on-device translation failed, using Gemini:', err );
				}
			}
		}

		// 2. Fall back to Server Gemini Flash
		return await translateWithGemini( text, sourceLang, targetLang, context );
	};

	/**
	 * Batch translate an array of items sequentially with progress callbacks.
	 *
	 * @param {Array<object>} items Array of items: [{ id, text, sourceLang, targetLang, context }]
	 * @param {object} options Callbacks: { onProgress, onComplete, onError }
	 * @return {object} Controller with cancel() method.
	 */
	LingolaneAI.batchTranslate = function( items, options ) {
		options = options || {};
		var cancelled = false;
		var results = [];

		var controller = {
			cancel: function() {
				cancelled = true;
			}
		};

		( async function() {
			for ( var i = 0; i < items.length; i++ ) {
				if ( cancelled ) {
					break;
				}
				var item = items[i];
				try {
					var res = await LingolaneAI.translate( item.text, item.sourceLang, item.targetLang, item.context );
					var entry = {
						id: item.id,
						translation: res.translation,
						engine: res.engine,
						success: true
					};
					results.push( entry );
					if ( typeof options.onProgress === 'function' ) {
						options.onProgress( i + 1, items.length, entry );
					}
				} catch ( err ) {
					var errorEntry = {
						id: item.id,
						error: err.message,
						success: false
					};
					results.push( errorEntry );
					if ( typeof options.onError === 'function' ) {
						options.onError( err, item );
					}
					if ( typeof options.onProgress === 'function' ) {
						options.onProgress( i + 1, items.length, errorEntry );
					}
				}
			}

			if ( typeof options.onComplete === 'function' ) {
				options.onComplete( results, cancelled );
			}
		} )();

		return controller;
	};

	window.LingolaneAI = LingolaneAI;

} )( jQuery, window );
