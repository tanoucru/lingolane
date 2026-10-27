/**
 * Lingolane In-Context Translation Frontend Controller (P4)
 *
 * Implements seamless visual inline editing directly on the frontend:
 * - Live hovering highlight frames with status indicators (translated vs missing)
 * - Clickable pen icon and modal popover editor
 * - Instant DOM text replacement without page reload
 * - Dynamic manifest fetching and stats progress calculation
 * - Entry-content protection with direct editor redirect
 *
 * @package Lingolane
 */

( function ( $ ) {
	'use strict';

	if ( typeof window.lingolaneInContextData === 'undefined' ) {
		return;
	}

	var data = window.lingolaneInContextData;
	var state = {
		targetLang: data.currentLang || 'en',
		filter: 'all', // 'all' or 'missing'
		manifest: {},
		totalCount: 0,
		translatedCount: 0,
		activeElement: null,
		activeString: null,
		isEntryContent: false,
	};

	var $toolbar, $highlightBox, $penBtn, $modal;

	/**
	 * SVG Icons helper
	 */
	var icons = {
		brand: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
		pen: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>',
		sparkles: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3L12 3z"/></svg>',
		external: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>',
		check: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
	};

	/**
	 * Initialize In-Context editor
	 */
	function init() {
		buildToolbar();
		createHighlightOverlays();
		bindGlobalEvents();
		fetchManifest();
	}

	/**
	 * Build and insert floating toolbar
	 */
	function buildToolbar() {
		var i18n = data.i18n;
		var langOptions = '';

		if ( data.languages && data.languages.length ) {
			$.each( data.languages, function ( _, lang ) {
				var isSelected = lang.code === state.targetLang ? ' selected' : '';
				langOptions += '<option value="' + escapeHtml( lang.code ) + '"' + isSelected + '>' + escapeHtml( lang.name ) + '</option>';
			} );
		}

		var flagHtml = getFlagSvg( state.targetLang );

		var toolbarHtml = [
			'<div id="lingolane-incontext-toolbar">',
			'  <div class="lingolane-ic-toolbar__brand">',
			'    ' + icons.brand,
			'    <span>' + escapeHtml( i18n.toolbarTitle ) + '</span>',
			'  </div>',
			'  <div class="lingolane-ic-toolbar__divider"></div>',
			'  <div class="lingolane-ic-toolbar__target">',
			'    <span class="lingolane-ic-toolbar__label">' + escapeHtml( i18n.translatingTo ) + '</span>',
			'    <div class="lingolane-ic-toolbar__lang-select">',
			'      <span class="lingolane-ic-toolbar__flag">' + flagHtml + '</span>',
			'      <select id="lingolane-ic-lang-switcher">',
			'        ' + langOptions,
			'      </select>',
			'    </div>',
			'  </div>',
			'  <div class="lingolane-ic-toolbar__counter" id="lingolane-ic-counter">0/0</div>',
			'  <div class="lingolane-ic-toolbar__filters">',
			'    <button type="button" class="lingolane-ic-toolbar__filter-btn is-active" data-filter="all">' + escapeHtml( i18n.filterAll ) + '</button>',
			'    <button type="button" class="lingolane-ic-toolbar__filter-btn" data-filter="missing">' + escapeHtml( i18n.filterMissing ) + '</button>',
			'  </div>',
			'  <div class="lingolane-ic-toolbar__divider"></div>',
			'  <a href="' + escapeHtml( data.exitUrl ) + '" class="lingolane-ic-toolbar__exit">',
			'    <span>' + escapeHtml( i18n.exit ) + '</span>',
			'  </a>',
			'</div>',
		].join( '\n' );

		$toolbar = $( toolbarHtml ).appendTo( 'body' );

		// Switch target language
		$toolbar.on( 'change', '#lingolane-ic-lang-switcher', function () {
			state.targetLang = $( this ).val();
			$toolbar.find( '.lingolane-ic-toolbar__flag' ).html( getFlagSvg( state.targetLang ) );
			hideHighlight();
			fetchManifest();
		} );

		// Toggle filters
		$toolbar.on( 'click', '.lingolane-ic-toolbar__filter-btn', function () {
			var filter = $( this ).data( 'filter' );
			state.filter = filter;
			$toolbar.find( '.lingolane-ic-toolbar__filter-btn' ).removeClass( 'is-active' );
			$( this ).addClass( 'is-active' );
			hideHighlight();
		} );
	}

	/**
	 * Create highlight frame and pen button overlays
	 */
	function createHighlightOverlays() {
		$highlightBox = $( '<div class="lingolane-ic-highlight-box" style="display:none;"></div>' ).appendTo( 'body' );
		$penBtn = $( '<button type="button" class="lingolane-ic-pen-btn" style="display:none;" title="' + escapeHtml( data.i18n.penTitle ) + '">' + icons.pen + '</button>' ).appendTo( 'body' );

		$penBtn.on( 'click', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			if ( state.activeElement ) {
				openEditor( state.activeElement );
			}
		} );
	}

	/**
	 * Fetch manifest of known strings for current page
	 */
	function fetchManifest() {
		$.ajax( {
			url: data.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'lingolane_incontext_manifest',
				nonce: data.nonce,
				target_lang: state.targetLang,
				page_path: data.pagePath,
			},
			success: function ( response ) {
				if ( response.success && response.data ) {
					state.manifest = {};
					var strings = response.data.strings || [];
					var transCount = 0;

					$.each( strings, function ( _, item ) {
						var normSource = normalizeText( item.source );
						state.manifest[ normSource ] = item;
						if ( item.source_hash ) {
							state.manifest[ item.source_hash ] = item;
						}
						if ( item.translation && item.translation.trim() !== '' ) {
							transCount++;
						}
					} );

					state.totalCount = strings.length;
					state.translatedCount = transCount;
					updateCounter();
				}
			},
			error: function () {
				showToast( data.i18n.saveFailed, true );
			},
		} );
	}

	/**
	 * Update toolbar progress counter badge
	 */
	function updateCounter() {
		var text = data.i18n.progressFormat
			.replace( '%1$d', state.translatedCount )
			.replace( '%2$d', state.totalCount );
		$( '#lingolane-ic-counter' ).text( text );
	}

	/**
	 * Bind global hover and click events for interactive visual translation
	 */
	function bindGlobalEvents() {
		var selector = 'h1, h2, h3, h4, h5, h6, p, a, button, span, label, li, th, td, blockquote, figcaption, .entry-content, article';

		$( document ).on( 'mouseenter', selector, function ( e ) {
			var $el = $( this );

			// Skip toolbar and editor modal elements
			if ( $el.closest( '#lingolane-incontext-toolbar, .lingolane-ic-modal-backdrop, #wpadminbar' ).length ) {
				return;
			}

			// Special handling for article / entry-content post container
			if ( $el.is( '.entry-content, article' ) || $el.hasClass( 'entry-content' ) ) {
				if ( data.postData && data.postData.editUrl ) {
					e.stopPropagation();
					showHighlight( $el, null, true );
					return;
				}
			}

			// Check for direct textual leaf content
			var text = getDirectText( $el );
			if ( ! text || text.length < 1 ) {
				return;
			}

			var norm = normalizeText( text );
			var manifestEntry = state.manifest[ norm ] || null;

			// Filter missing mode check
			var isTranslated = manifestEntry && manifestEntry.translation && manifestEntry.translation.trim() !== '';
			if ( 'missing' === state.filter && isTranslated ) {
				return;
			}

			e.stopPropagation();
			showHighlight( $el, manifestEntry, false );
		} );

		$( document ).on( 'mouseleave', selector, function ( e ) {
			var $to = $( e.relatedTarget );
			if ( $to.closest( '.lingolane-ic-pen-btn' ).length || $to.closest( '.lingolane-ic-highlight-box' ).length ) {
				return;
			}
			hideHighlight();
		} );

		// Double-click or Ctrl+click on translatable element opens editor directly
		$( document ).on( 'dblclick', selector, function ( e ) {
			if ( $( this ).closest( '#lingolane-incontext-toolbar, .lingolane-ic-modal-backdrop, #wpadminbar' ).length ) {
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			openEditor( $( this ) );
		} );
	}

	/**
	 * Show highlight outline around hovered element
	 */
	function showHighlight( $el, manifestEntry, isEntryContent ) {
		state.activeElement = $el;
		state.activeString = manifestEntry;
		state.isEntryContent = isEntryContent;

		var offset = $el.offset();
		var width  = $el.outerWidth();
		var height = $el.outerHeight();

		$highlightBox
			.removeClass( 'lingolane-ic-highlight-box--translated lingolane-ic-highlight-box--missing lingolane-ic-highlight-box--entry-content' );

		$penBtn
			.removeClass( 'lingolane-ic-pen-btn--ok lingolane-ic-pen-btn--missing' );

		if ( isEntryContent ) {
			$highlightBox.addClass( 'lingolane-ic-highlight-box--entry-content' );
		} else if ( manifestEntry && manifestEntry.translation && manifestEntry.translation.trim() !== '' ) {
			$highlightBox.addClass( 'lingolane-ic-highlight-box--translated' );
			$penBtn.addClass( 'lingolane-ic-pen-btn--ok' );
		} else {
			$highlightBox.addClass( 'lingolane-ic-highlight-box--missing' );
			$penBtn.addClass( 'lingolane-ic-pen-btn--missing' );
		}

		$highlightBox.css( {
			top: offset.top - 2,
			left: offset.left - 2,
			width: width + 4,
			height: height + 4,
			display: 'block',
		} );

		$penBtn.css( {
			top: Math.max( 10, offset.top - 14 ),
			left: offset.left + width - 14,
			display: 'flex',
		} );
	}

	/**
	 * Hide highlight and pen button
	 */
	function hideHighlight() {
		$highlightBox.hide();
		$penBtn.hide();
	}

	/**
	 * Open popover modal editor
	 */
	function openEditor( $el ) {
		hideHighlight();

		// Case 1: Post content redirect prompt
		if ( state.isEntryContent && data.postData && data.postData.editUrl ) {
			var postModalHtml = [
				'<div class="lingolane-ic-modal-backdrop" id="lingolane-ic-modal">',
				'  <div class="lingolane-ic-popover">',
				'    <div class="lingolane-ic-popover__header">',
				'      <div class="lingolane-ic-popover__title-wrap">',
				'        <h3 class="lingolane-ic-popover__title">' + escapeHtml( data.postData.title || data.i18n.toolbarTitle ) + '</h3>',
				'        <span class="lingolane-ic-popover__badge">Post Content</span>',
				'      </div>',
				'      <button type="button" class="lingolane-ic-popover__close">&times;</button>',
				'    </div>',
				'    <div class="lingolane-ic-popover__body">',
				'      <div class="lingolane-ic-post-callout">',
				'        ' + icons.external,
				'        <div class="lingolane-ic-post-callout__text">',
				'          <strong>' + escapeHtml( data.i18n.editPostMsg ) + '</strong><br>',
				'          <span>' + escapeHtml( data.i18n.postNotice ) + '</span>',
				'        </div>',
				'      </div>',
				'    </div>',
				'    <div class="lingolane-ic-popover__footer">',
				'      <span class="lingolane-ic-popover__hint"></span>',
				'      <div class="lingolane-ic-popover__actions">',
				'        <button type="button" class="lingolane-ic-btn lingolane-ic-btn--secondary" id="lingolane-ic-close-btn">' + escapeHtml( data.i18n.cancel ) + '</button>',
				'        <a href="' + escapeHtml( data.postData.editUrl ) + '" target="_blank" class="lingolane-ic-btn lingolane-ic-btn--primary">',
				'          ' + icons.external + ' <span>' + escapeHtml( data.i18n.editPostBtn ) + '</span>',
				'        </a>',
				'      </div>',
				'    </div>',
				'  </div>',
				'</div>',
			].join( '\n' );

			$modal = $( postModalHtml ).appendTo( 'body' );
			bindModalEvents( $modal );
			return;
		}

		// Case 2: In-context string editor
		var rawSource = getDirectText( $el );
		var entry = state.activeString || {};
		var sourceText = entry.source || rawSource;
		var currentTranslation = entry.translation || '';
		var domain = entry.domain || 'lingolane-site';
		var context = entry.context || '';
		var groupKey = entry.group_key || detectGroupKey( $el );

		var modalHtml = [
			'<div class="lingolane-ic-modal-backdrop" id="lingolane-ic-modal">',
			'  <div class="lingolane-ic-popover">',
			'    <div class="lingolane-ic-popover__header">',
			'      <div class="lingolane-ic-popover__title-wrap">',
			'        <h3 class="lingolane-ic-popover__title">' + escapeHtml( data.i18n.translation ) + '</h3>',
			'        <span class="lingolane-ic-popover__badge">' + escapeHtml( groupKey ) + '</span>',
			'      </div>',
			'      <button type="button" class="lingolane-ic-popover__close">&times;</button>',
			'    </div>',
			'    <div class="lingolane-ic-popover__body">',
			'      <div class="lingolane-ic-field">',
			'        <div class="lingolane-ic-field__label">',
			'          <span>' + escapeHtml( data.i18n.originalText ) + '</span>',
			'        </div>',
			'        <div class="lingolane-ic-field__source">' + escapeHtml( sourceText ) + '</div>',
			'      </div>',
			'      <div class="lingolane-ic-field">',
			'        <div class="lingolane-ic-field__label">',
			'          <span>' + escapeHtml( data.i18n.translation ) + ' (' + escapeHtml( state.targetLang.toUpperCase() ) + ')</span>',
			'          <button type="button" class="lingolane-ic-field__ai-btn" id="lingolane-ic-ai-suggest">' + icons.sparkles + ' <span>' + escapeHtml( data.i18n.aiSuggest ) + '</span></button>',
			'        </div>',
			'        <textarea class="lingolane-ic-field__textarea" id="lingolane-ic-translation-input" placeholder="' + escapeHtml( data.i18n.placeholder ) + '">' + escapeHtml( currentTranslation ) + '</textarea>',
			'      </div>',
			'    </div>',
			'    <div class="lingolane-ic-popover__footer">',
			'      <span class="lingolane-ic-popover__hint">' + escapeHtml( data.i18n.hint ) + '</span>',
			'      <div class="lingolane-ic-popover__actions">',
			'        <button type="button" class="lingolane-ic-btn lingolane-ic-btn--secondary" id="lingolane-ic-close-btn">' + escapeHtml( data.i18n.cancel ) + '</button>',
			'        <button type="button" class="lingolane-ic-btn lingolane-ic-btn--primary" id="lingolane-ic-save-btn">' + escapeHtml( data.i18n.save ) + '</button>',
			'      </div>',
			'    </div>',
			'  </div>',
			'</div>',
		].join( '\n' );

		$modal = $( modalHtml ).appendTo( 'body' );
		var $textarea = $modal.find( '#lingolane-ic-translation-input' );
		$textarea.focus().select();

		bindModalEvents( $modal, function () {
			var newTranslation = $textarea.val();
			saveTranslation( {
				$element: $el,
				source: sourceText,
				domain: domain,
				context: context,
				group_key: groupKey,
				translation: newTranslation,
			} );
		} );
	}

	/**
	 * Save translation via AJAX and instantly update live DOM
	 */
	function saveTranslation( payload ) {
		var $saveBtn = $modal.find( '#lingolane-ic-save-btn' );
		$saveBtn.prop( 'disabled', true ).text( data.i18n.saving );

		$.ajax( {
			url: data.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'lingolane_incontext_save',
				nonce: data.nonce,
				domain: payload.domain,
				source: payload.source,
				context: payload.context,
				language_code: state.targetLang,
				translation: payload.translation,
				group_key: payload.group_key,
				page_path: data.pagePath,
				locator: 'incontext',
			},
			success: function ( response ) {
				if ( response.success ) {
					// 1. Instant live DOM text update!
					updateDomElementText( payload.$element, payload.translation || payload.source );

					// 2. Update local manifest cache
					var norm = normalizeText( payload.source );
					var existing = state.manifest[ norm ] || {};
					var wasEmpty = ! existing.translation || existing.translation.trim() === '';
					existing.source = payload.source;
					existing.translation = payload.translation;
					existing.group_key = payload.group_key;
					state.manifest[ norm ] = existing;

					if ( wasEmpty && payload.translation && payload.translation.trim() !== '' ) {
						state.translatedCount++;
					} else if ( ! wasEmpty && ( ! payload.translation || payload.translation.trim() === '' ) ) {
						state.translatedCount = Math.max( 0, state.translatedCount - 1 );
					}
					updateCounter();

					// 3. Close modal & show toast
					closeModal();
					showToast( data.i18n.savedSuccess );
				} else {
					$saveBtn.prop( 'disabled', false ).text( data.i18n.save );
					showToast( response.data && response.data.message ? response.data.message : data.i18n.saveFailed, true );
				}
			},
			error: function ( jqXHR ) {
				$saveBtn.prop( 'disabled', false ).text( data.i18n.save );
				var msg = data.i18n.saveFailed;
				if ( jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message ) {
					msg = jqXHR.responseJSON.data.message;
				}
				showToast( msg, true );
			},
		} );
	}

	/**
	 * Update DOM element text seamlessly
	 */
	function updateDomElementText( $el, text ) {
		// If element has only one child text node, replace it directly preserving other tags
		var childNodes = $el[0].childNodes;
		if ( childNodes.length === 1 && childNodes[0].nodeType === Node.TEXT_NODE ) {
			childNodes[0].nodeValue = text;
		} else {
			// Find text node inside element that matches
			var replaced = false;
			$el.contents().each( function () {
				if ( this.nodeType === Node.TEXT_NODE && this.nodeValue.trim().length > 0 ) {
					this.nodeValue = text;
					replaced = true;
					return false;
				}
			} );
			if ( ! replaced ) {
				$el.text( text );
			}
		}
	}

	/**
	 * Modal event bindings (Esc, close button, backdrop click, Ctrl+Enter)
	 */
	function bindModalEvents( $m, onSaveCallback ) {
		$m.on( 'click', '.lingolane-ic-popover__close, #lingolane-ic-close-btn', function () {
			closeModal();
		} );

		$m.on( 'click', function ( e ) {
			if ( $( e.target ).is( $m ) ) {
				closeModal();
			}
		} );

		if ( onSaveCallback ) {
			$m.on( 'click', '#lingolane-ic-save-btn', function () {
				onSaveCallback();
			} );

			$m.on( 'keydown', '#lingolane-ic-translation-input', function ( e ) {
				if ( ( e.ctrlKey || e.metaKey ) && 13 === e.keyCode ) {
					e.preventDefault();
					onSaveCallback();
				}
			} );

			// AI translation suggestion
			$m.on( 'click', '#lingolane-ic-ai-suggest', async function ( e ) {
				e.preventDefault();
				var $btn = $( this );
				var $input = $( '#lingolane-ic-translation-input' );
				var sourceText = $( '#lingolane-ic-source-text' ).text() || '';

				if ( ! sourceText.trim() ) {
					return;
				}

				var origHtml = $btn.html();
				$btn.prop( 'disabled', true ).html( '<span>' + escapeHtml( data.i18n.aiWorking ) + '</span>' );

				try {
					if ( window.LingolaneAI && typeof window.LingolaneAI.translate === 'function' ) {
						var res = await window.LingolaneAI.translate( sourceText, 'auto', state.targetLang );
						if ( res && res.translation ) {
							$input.val( res.translation ).trigger( 'input' ).focus();
							var engineLabel = res.engine === 'chrome-ondevice' ? 'Chrome On-Device AI' : 'Gemini AI';
							showToast( data.i18n.aiSuggested.replace( '%s', engineLabel ), false );
						} else {
							showToast( data.i18n.aiEmpty, true );
						}
					} else {
						showToast( data.i18n.aiMissing, true );
					}
				} catch ( err ) {
					showToast( data.i18n.aiError + ( err.message || err ), true );
				} finally {
					$btn.prop( 'disabled', false ).html( origHtml );
				}
			} );
		}

		$( document ).on( 'keydown.lingolaneModal', function ( e ) {
			if ( 27 === e.keyCode ) {
				closeModal();
			}
		} );
	}

	/**
	 * Close popover modal
	 */
	function closeModal() {
		if ( $modal ) {
			$( document ).off( 'keydown.lingolaneModal' );
			$modal.remove();
			$modal = null;
		}
	}

	/**
	 * Show Toast notification at bottom-right
	 */
	function showToast( message, isError ) {
		var className = 'lingolane-ic-toast' + ( isError ? ' lingolane-ic-toast--error' : '' );
		var icon = isError ? '!' : icons.check;
		var $toast = $( '<div class="' + className + '">' + icon + ' <span>' + escapeHtml( message ) + '</span></div>' )
			.appendTo( 'body' );

		setTimeout( function () {
			$toast.fadeOut( 200, function () {
				$( this ).remove();
			} );
		}, 3000 );
	}

	/**
	 * Detect group key (header, footer, menu, widget, etc.) based on DOM hierarchy
	 */
	function detectGroupKey( $el ) {
		if ( $el.closest( 'header, .site-header, #masthead, .header' ).length ) {
			return 'header';
		}
		if ( $el.closest( 'footer, .site-footer, #colophon, .footer' ).length ) {
			return 'footer';
		}
		if ( $el.closest( 'nav, .nav, .menu, .navigation' ).length ) {
			return 'menu';
		}
		if ( $el.closest( 'aside, .sidebar, .widget' ).length ) {
			return 'widget';
		}
		return 'other';
	}

	/**
	 * Get direct text node content of an element without nested elements
	 */
	function getDirectText( $el ) {
		var direct = '';
		$el.contents().each( function () {
			if ( this.nodeType === Node.TEXT_NODE ) {
				direct += this.nodeValue;
			}
		} );
		var trimmed = direct.trim();
		if ( trimmed.length > 0 ) {
			return trimmed;
		}
		return $el.text().trim();
	}

	/**
	 * Normalize text for string lookup
	 */
	function normalizeText( str ) {
		return ( str || '' ).replace( /\s+/g, ' ' ).trim();
	}

	/**
	 * Escape HTML entities
	 */
	function escapeHtml( str ) {
		if ( ! str ) {
			return '';
		}
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	/**
	 * Get SVG flag markup for language code
	 */
	function getFlagSvg( code ) {
		if ( data.flags && data.flags[ code ] && data.flags[ code ].svg ) {
			return data.flags[ code ].svg;
		}
		return '<span style="font-size:11px;font-weight:700;">' + escapeHtml( code.toUpperCase() ) + '</span>';
	}

	// Bootstrap when document is ready
	$( function () {
		init();
	} );

} )( jQuery );
