/* Lingolane · admin support tab: builds the forum ticket, copies and downloads the system report. Nothing is sent. */
( function () {
	'use strict';
	var form = document.querySelector( '[data-lingolane-ticket]' );
	if ( ! form ) { return; }
	var cfg = window.lingolaneSupport || { i18n: {} };
	var t = function ( key ) { return cfg.i18n[ key ] || key; };
	var field = function ( name ) { return form.elements[ name ]; };
	var value = function ( name ) { return field( name ) ? field( name ).value.trim() : ''; };
	var preview = document.getElementById( 'lingolane-ticket-preview' );
	var report = document.getElementById( 'lingolane-report' );
	var msg = form.querySelector( '[data-lingolane-ticket-msg]' );

	var notify = function ( message, type ) {
		if ( window.lingolaneToast ) { window.lingolaneToast( message, type ); return; }
		if ( window.wp && wp.a11y && wp.a11y.speak ) { wp.a11y.speak( message ); }
	};

	// Forum-ready text; empty sections are left out.
	var build = function () {
		var topic = field( 'topic' );
		var parts = [ '**' + t( 'topic' ) + ':** ' + topic.options[ topic.selectedIndex ].text ];
		if ( value( 'title' ) ) { parts.push( '**' + t( 'summary' ) + ':** ' + value( 'title' ) ); }
		[ 'what', 'steps', 'expected' ].forEach( function ( name ) {
			if ( value( name ) ) { parts.push( '**' + t( name ) + ':**\n' + value( name ) ); }
		} );
		if ( value( 'page' ) ) { parts.push( '**' + t( 'page' ) + ':** ' + value( 'page' ) ); }
		if ( field( 'report' ).checked && report ) { parts.push( '**' + t( 'report' ) + ':**\n`\n' + report.value + '\n`' ); }
		preview.value = parts.join( '\n\n' );
	};

	// Title and description are required before copying or opening the forum.
	var check = function () {
		var missing = [ 'title', 'what' ].filter( function ( name ) { return ! value( name ); } );
		[ 'title', 'what' ].forEach( function ( name ) {
			field( name ).setAttribute( 'aria-invalid', -1 !== missing.indexOf( name ) ? 'true' : 'false' );
		} );
		msg.textContent = missing.length ? t( 'required' ) : '';
		if ( missing.length ) { field( missing[ 0 ] ).focus(); }
		return ! missing.length;
	};

	var copy = function ( source ) {
		var done = function () { notify( t( 'copied' ), 'success' ); };
		var fallback = function () {
			source.focus();
			source.select();
			try {
				if ( document.execCommand( 'copy' ) ) { done(); return; }
			} catch ( e ) { /* falls through to the error message */ }
			notify( t( 'copyFailed' ), 'error' );
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( source.value ).then( done, fallback );
		} else {
			fallback();
		}
	};

	form.addEventListener( 'input', build );
	form.addEventListener( 'change', build );
	form.addEventListener( 'submit', function ( event ) { event.preventDefault(); } );
	build();

	document.querySelectorAll( '[data-lingolane-copy]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var source = document.getElementById( button.getAttribute( 'data-lingolane-copy' ) );
			if ( source === preview && ! check() ) { return; }
			copy( source );
		} );
	} );

	var forum = form.querySelector( '[data-lingolane-forum]' );
	forum.addEventListener( 'click', function ( event ) {
		if ( ! check() ) { event.preventDefault(); return; }
		copy( preview );
	} );

	document.querySelectorAll( '[data-lingolane-download]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var source = document.getElementById( button.getAttribute( 'data-lingolane-download' ) );
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
