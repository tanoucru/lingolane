/* Lingolane · admin support tab: builds the forum ticket, copies and downloads the system report. Nothing is sent. */
( function () {
	'use strict';
	var form = document.querySelector( '[data-lingolane-ticket]' );
	var preview = document.getElementById( 'lingolane-ticket-preview' );
	if ( ! form || ! preview ) { return; }
	var cfg = window.lingolaneSupport || { i18n: {} };
	var t = function ( key ) { return cfg.i18n[ key ] || key; };
	var field = function ( name ) { return form.elements[ name ]; };
	var value = function ( name ) { return field( name ) ? field( name ).value.trim() : ''; };
	var report = document.getElementById( 'lingolane-report' );
	var msg = form.querySelector( '[data-lingolane-ticket-msg]' );

	var notify = function ( message, type ) {
		if ( window.lingolaneToast ) { window.lingolaneToast( message, type ); return; }
		if ( window.wp && wp.a11y && wp.a11y.speak ) { wp.a11y.speak( message ); }
	};
	var say = function ( text ) { if ( msg ) { msg.textContent = text; } };

	// Forum-ready text; empty sections are left out.
	var build = function () {
		var topic = field( 'topic' );
		var parts = [];
		if ( topic && topic.options && topic.selectedIndex >= 0 ) {
			parts.push( '**' + t( 'topic' ) + ':** ' + topic.options[ topic.selectedIndex ].text );
		}
		if ( value( 'title' ) ) { parts.push( '**' + t( 'summary' ) + ':** ' + value( 'title' ) ); }
		[ 'what', 'steps', 'expected' ].forEach( function ( name ) {
			if ( value( name ) ) { parts.push( '**' + t( name ) + ':**\n' + value( name ) ); }
		} );
		if ( value( 'page' ) ) { parts.push( '**' + t( 'page' ) + ':** ' + value( 'page' ) ); }
		if ( field( 'report' ) && field( 'report' ).checked && report ) { parts.push( '**' + t( 'report' ) + ':**\n`\n' + report.value + '\n`' ); }
		preview.value = parts.join( '\n\n' );
	};

	// Title and description are required before copying or opening the forum.
	var check = function () {
		var missing = [ 'title', 'what' ].filter( function ( name ) { return field( name ) && ! value( name ); } );
		[ 'title', 'what' ].forEach( function ( name ) {
			if ( field( name ) ) { field( name ).setAttribute( 'aria-invalid', -1 !== missing.indexOf( name ) ? 'true' : 'false' ); }
		} );
		say( missing.length ? t( 'required' ) : '' );
		if ( missing.length ) { field( missing[ 0 ] ).focus(); }
		return ! missing.length;
	};

	// Resolves true once the text is on the clipboard; on failure the text is left selected for a manual copy.
	var copy = function ( source ) {
		var fallback = function () {
			source.focus();
			source.select();
			try {
				return document.execCommand( 'copy' );
			} catch ( e ) {
				return false;
			}
		};
		var attempt = navigator.clipboard && window.isSecureContext
			? navigator.clipboard.writeText( source.value ).then( function () { return true; }, fallback )
			: Promise.resolve( fallback() );
		return attempt.then( function ( ok ) {
			if ( ok ) {
				notify( t( 'copied' ), 'success' );
			} else {
				source.focus();
				source.select();
				notify( t( 'copyFailed' ), 'error' );
			}
			return ok;
		} );
	};

	form.addEventListener( 'input', build );
	form.addEventListener( 'change', build );
	form.addEventListener( 'submit', function ( event ) { event.preventDefault(); } );
	build();

	document.querySelectorAll( '[data-lingolane-copy]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var source = document.getElementById( button.getAttribute( 'data-lingolane-copy' ) );
			if ( ! source || ( source === preview && ! check() ) ) { return; }
			copy( source );
		} );
	} );

	// The forum opens only after the ticket is on the clipboard, so the user never lands there empty-handed.
	var forum = form.querySelector( '[data-lingolane-forum]' );
	if ( forum ) {
		forum.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			if ( ! check() ) { return; }
			copy( preview ).then( function ( ok ) {
				if ( ! ok ) { say( t( 'copyFailedForum' ) ); return; }
				var tab = window.open( forum.href, '_blank' );
				if ( tab ) { tab.opener = null; } else { window.location.href = forum.href; }
			} );
		} );
	}

	document.querySelectorAll( '[data-lingolane-download]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var source = document.getElementById( button.getAttribute( 'data-lingolane-download' ) );
			if ( ! source ) { return; }
			var url = URL.createObjectURL( new Blob( [ source.value + '\n' ], { type: 'text/plain;charset=utf-8' } ) );
			var link = document.createElement( 'a' );
			link.href = url;
			link.download = 'lingolane-system-report.txt';
			document.body.appendChild( link );
			link.click();
			document.body.removeChild( link );
			setTimeout( function () { URL.revokeObjectURL( url ); }, 0 );
		} );
	} );
}() );
