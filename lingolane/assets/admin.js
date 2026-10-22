/* Lingolane · admin */
( function () {
	'use strict';
	var cfg = window.lingolaneAdmin || { i18n: {}, presets: {} };
	var t = function ( key ) { return cfg.i18n[ key ] || key; };

	// Confirm before consequential actions (default language, removing a language).
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( form.matches && form.matches( '[data-lingolane-confirm]' ) && ! window.confirm( form.getAttribute( 'data-lingolane-confirm' ) ) ) {
			event.preventDefault();
		}
	} );

	// Close an open "Sửa" popover with Escape or an outside click.
	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' !== event.key ) { return; }
		document.querySelectorAll( '.lingolane-popover[open]' ).forEach( function ( item ) {
			item.removeAttribute( 'open' );
			item.querySelector( 'summary' ).focus();
		} );
	} );
	document.addEventListener( 'click', function ( event ) {
		document.querySelectorAll( '.lingolane-popover[open]' ).forEach( function ( item ) {
			if ( ! item.contains( event.target ) ) { item.removeAttribute( 'open' ); }
		} );
	} );
	document.querySelectorAll( '.lingolane-popover' ).forEach( function ( item ) {
		item.addEventListener( 'toggle', function () {
			if ( item.open ) {
				var field = item.querySelector( 'input:not([type=hidden])' );
				if ( field ) { field.focus(); }
			}
		} );
	} );

	// Toasts: server-rendered ones auto-hide; window.lingolaneToast() builds the same markup for AJAX results.
	var toastTypes = [ 'success', 'info', 'warning', 'error' ];
	var iconCache = {};
	// Icon markup comes from PHP (trusted); parse each once and clone.
	var toastIcon = function ( name ) {
		if ( ! iconCache[ name ] ) {
			var tpl = document.createElement( 'template' );
			tpl.innerHTML = ( cfg.icons && cfg.icons[ name ] ) || '';
			iconCache[ name ] = tpl.content.firstElementChild;
		}
		return iconCache[ name ] ? iconCache[ name ].cloneNode( true ) : document.createComment( '' );
	};

	var closeToast = function ( toast ) {
		if ( ! toast.parentNode ) { return; }
		var region = toast.parentNode;
		var hadFocus = toast.contains( document.activeElement );
		var next = toast.nextElementSibling || toast.previousElementSibling;
		clearTimeout( toast.lingolaneTimer );
		region.removeChild( toast );
		if ( hadFocus ) {
			var target = next && next.querySelector( '.lingolane-toast__close' );
			if ( ! target ) {
				target = document.querySelector( '.lingolane-wrap h1' ) || document.querySelector( '.lingolane-wrap' );
				if ( target && ! target.hasAttribute( 'tabindex' ) ) { target.setAttribute( 'tabindex', '-1' ); }
			}
			if ( target ) { target.focus(); }
		}
		if ( ! region.children.length && region.parentNode ) { region.parentNode.removeChild( region ); }
	};

	var armToast = function ( toast ) {
		var delay = parseInt( toast.getAttribute( 'data-autohide' ), 10 ) || 0;
		var stop = function () { clearTimeout( toast.lingolaneTimer ); };
		var start = function () {
			stop();
			if ( ! delay || toast.matches( ':hover' ) || toast.contains( document.activeElement ) ) { return; }
			toast.lingolaneTimer = setTimeout( function () { closeToast( toast ); }, delay );
		};
		toast.addEventListener( 'mouseenter', stop );
		toast.addEventListener( 'focusin', stop );
		toast.addEventListener( 'mouseleave', start );
		toast.addEventListener( 'focusout', function ( event ) {
			if ( event.relatedTarget && toast.contains( event.relatedTarget ) ) { return; }
			start();
		} );
		var close = toast.querySelector( '.lingolane-toast__close' );
		if ( close ) {
			close.hidden = false;
			close.addEventListener( 'click', function () { closeToast( toast ); } );
		}
		start();
	};

	document.querySelectorAll( '.lingolane-toast' ).forEach( armToast );

	window.lingolaneToast = function ( message, type ) {
		type = -1 !== toastTypes.indexOf( type ) ? type : 'info';
		var sticky = 'error' === type || 'warning' === type;
		var region = document.querySelector( '.lingolane-toasts' );
		if ( ! region ) {
			region = document.createElement( 'div' );
			region.className = 'lingolane-toasts';
			region.setAttribute( 'role', 'region' );
			region.setAttribute( 'aria-label', t( 'notifications' ) );
			( document.querySelector( '.lingolane-wrap' ) || document.body ).appendChild( region );
		}
		var toast = document.createElement( 'div' );
		toast.className = 'lingolane-toast lingolane-toast--' + type;
		toast.setAttribute( 'role', sticky ? 'alert' : 'status' );
		toast.setAttribute( 'data-autohide', sticky ? '0' : '5000' );
		toast.appendChild( toastIcon( type ) );
		var text = document.createElement( 'p' );
		text.textContent = String( message );
		toast.appendChild( text );
		var close = document.createElement( 'button' );
		close.type = 'button';
		close.className = 'lingolane-toast__close';
		close.hidden = true;
		close.setAttribute( 'aria-label', t( 'dismiss' ) );
		close.appendChild( toastIcon( 'close' ) );
		toast.appendChild( close );
		region.appendChild( toast );
		armToast( toast );
	};

	// Language presets fill code, name and locale.
	var preset = document.querySelector( '[data-lingolane-preset]' );
	if ( preset ) {
		preset.addEventListener( 'change', function () {
			var data = cfg.presets[ preset.value ];
			if ( ! data ) { return; }
			document.getElementById( 'lang_code' ).value = preset.value;
			document.getElementById( 'lang_name' ).value = data[ 0 ];
			document.getElementById( 'lang_locale' ).value = data[ 1 ];
		} );
	}

	// Strings: save and delete in place.
	var forms = Array.prototype.slice.call( document.querySelectorAll( 'form.lingolane-string' ) );
	if ( ! forms.length || ! window.fetch || ! window.FormData ) { return; }
	var tones = { own: 'ok', inherited: 'info', missing: 'warn' };

	function message( form, text, isError ) {
		var node = form.querySelector( '.lingolane-string__msg' );
		node.textContent = text || '';
		node.classList.toggle( 'is-error', !! isError );
	}

	function setStatus( form, status ) {
		var chip = form.querySelector( '[data-lingolane-status]' );
		chip.setAttribute( 'data-lingolane-status', status );
		chip.className = 'lingolane-chip lingolane-chip--' + ( tones[ status ] || 'warn' );
		chip.textContent = t( status );
	}

	function post( action, form, extra ) {
		var data = new FormData();
		data.append( 'action', action );
		data.append( 'nonce', cfg.nonce );
		data.append( 'id', form.querySelector( '[name=id]' ).value );
		Object.keys( extra || {} ).forEach( function ( key ) { data.append( key, extra[ key ] ); } );
		return fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: data } ).then( function ( response ) {
			return response.json().catch( function () { return { success: false }; } );
		} );
	}

	function save( form, then ) {
		var area = form.querySelector( 'textarea' );
		form.classList.add( 'is-busy' );
		message( form, t( 'saving' ) );
		post( 'lingolane_save_string', form, { translation: area.value } ).then( function ( result ) {
			form.classList.remove( 'is-busy' );
			if ( ! result || ! result.success ) {
				message( form, ( result && result.data && result.data.message ) || t( 'failed' ), true );
				return;
			}
			area.value = result.data.translation;
			area.defaultValue = result.data.translation;
			form.classList.remove( 'is-dirty' );
			setStatus( form, result.data.status );
			message( form, t( 'saved' ) );
			if ( then ) { then(); }
		} ).catch( function () {
			form.classList.remove( 'is-busy' );
			message( form, t( 'failed' ), true );
		} );
	}

	function next( form ) {
		var index = forms.indexOf( form );
		for ( var i = index + 1; i < forms.length; i++ ) {
			if ( document.body.contains( forms[ i ] ) ) {
				var area = forms[ i ].querySelector( 'textarea' );
				area.focus();
				area.scrollIntoView( { block: 'center', behavior: 'smooth' } );
				return;
			}
		}
	}

	forms.forEach( function ( form ) {
		var area = form.querySelector( 'textarea' );
		area.addEventListener( 'input', function () {
			var dirty = area.value !== area.defaultValue;
			form.classList.toggle( 'is-dirty', dirty );
			message( form, dirty ? t( 'unsaved' ) : '' );
		} );
		area.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key && ( event.metaKey || event.ctrlKey ) ) {
				event.preventDefault();
				save( form, function () { next( form ); } );
			}
		} );
		var copy = form.querySelector( '[data-lingolane-copy]' );
		if ( copy ) {
			copy.addEventListener( 'click', function () {
				area.value = area.getAttribute( 'data-inherited' ) || '';
				area.dispatchEvent( new Event( 'input' ) );
				area.focus();
			} );
		}
		var same = form.querySelector( '[data-lingolane-same]' );
		if ( same ) {
			same.addEventListener( 'click', function () {
				var source = document.getElementById( 'lingolane-src-' + form.querySelector( '[name=id]' ).value );
				area.value = source ? source.textContent : '';
				save( form, function () { same.remove(); next( form ); } );
			} );
		}
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var submitter = event.submitter || document.activeElement;
			if ( submitter && submitter.hasAttribute( 'data-lingolane-delete' ) ) {
				if ( ! window.confirm( t( 'deleteAsk' ) ) ) { return; }
				form.classList.add( 'is-busy' );
				post( 'lingolane_delete_string', form ).then( function ( result ) {
					if ( ! result || ! result.success ) {
						form.classList.remove( 'is-busy' );
						message( form, ( result && result.data && result.data.message ) || t( 'failed' ), true );
						return;
					}
					var row = form.closest( 'tr' );
					var target = row.nextElementSibling || row.previousElementSibling;
					row.remove();
					if ( window.wp && wp.a11y ) { wp.a11y.speak( t( 'deleted' ) ); }
					if ( target ) { var field = target.querySelector( 'textarea' ); if ( field ) { field.focus(); } }
				} );
				return;
			}
			save( form );
		} );
	} );

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( document.querySelector( 'form.lingolane-string.is-dirty' ) ) {
			event.preventDefault();
			event.returnValue = t( 'leave' );
		}
	} );
} )();
