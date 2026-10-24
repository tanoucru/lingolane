/* Lingolane · tree list */
( function () {
	'use strict';
	var cfg = window.lingolaneTree;
	var list = document.getElementById( 'the-list' );
	var templates = document.querySelectorAll( 'template.lingolane-tree-tpl' );
	if ( ! cfg || ! list ) { return; }

	var groups = Object.create( null );
	var children = Object.create( null );
	var collapsed = [];
	var storageKey = 'lingolane-tree:' + cfg.postType;
	try {
		var stored = JSON.parse( window.localStorage.getItem( storageKey ) );
		if ( Array.isArray( stored ) ) {
			collapsed = stored.filter( function ( id ) {
				return /^\d+$/.test( String( id ) );
			} ).map( String );
		}
	} catch ( error ) { collapsed = []; }

	function save() {
		try { window.localStorage.setItem( storageKey, JSON.stringify( collapsed ) ); } catch ( error ) { /* Storage may be disabled. */ }
	}

	function attachFlag( row, cell, anchor ) {
		if ( ! cell || cell.querySelector( '.lingolane-title-flag' ) ) { return; }
		var match = row.className.match( /\blingolane-lang-([a-z0-9_-]+)\b/ );
		if ( ! match || ! match[1] ) { return; }
		var code = match[1];
		var wrap = document.createElement( 'span' );
		wrap.className = 'lingolane-title-flag';
		if ( 'shared' === code ) {
			wrap.className += ' lingolane-title-flag--shared';
			wrap.title = ( cfg.i18n && cfg.i18n.shared ) || 'Shared';
			wrap.innerHTML = '<span class="lingolane-chip lingolane-chip--muted">' + ( ( cfg.i18n && cfg.i18n.shared ) || 'Shared' ) + '</span>';
		} else {
			var flagInfo = cfg.flags && cfg.flags[ code ];
			if ( ! flagInfo || ! flagInfo.svg ) { return; }
			wrap.title = flagInfo.title || code.toUpperCase();
			wrap.innerHTML = flagInfo.svg;
		}
		if ( anchor && anchor.nextSibling ) {
			cell.insertBefore( wrap, anchor.nextSibling );
		} else if ( cell.firstChild ) {
			cell.insertBefore( wrap, cell.firstChild );
		} else {
			cell.appendChild( wrap );
		}
	}

	function restoreChild( row, group ) {
		row.classList.add( 'lingolane-tree-child' );
		row.classList.toggle( 'is-last', row.id === group.rows[ group.rows.length - 1 ] );
		row.hidden = ! group.open;
		var cell = row.querySelector( '.column-title' );
		if ( cell && ! row.classList.contains( 'lingolane-tree-missing' ) ) {
			attachFlag( row, cell, null );
		}
	}

	function updateButton( group ) {
		group.button.setAttribute( 'aria-expanded', String( group.open ) );
		group.button.setAttribute( 'aria-label', ( group.open ? cfg.i18n.hide : cfg.i18n.show ).replace( '%s', function () { return group.title; } ) );
		// Only trusted SVG strings localized by PHP are used as markup.
		group.button.innerHTML = group.open ? cfg.icons.open : cfg.icons.closed;
	}

	function uncheckRow( row ) {
		var checkboxes = row.querySelectorAll( 'input[type="checkbox"]' );
		var hadChecked = false;
		checkboxes.forEach( function ( cb ) {
			if ( cb.checked ) {
				cb.checked = false;
				cb.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				hadChecked = true;
			}
		} );
		if ( hadChecked ) {
			var selectAll = document.querySelectorAll( '#cb-select-all-1, #cb-select-all-2' );
			selectAll.forEach( function ( el ) {
				el.checked = false;
			} );
		}
	}

	function render( group ) {
		group.rows.forEach( function ( id ) {
			var row = document.getElementById( id );
			if ( row && row.parentNode === list ) {
				restoreChild( row, group );
				if ( ! group.open ) {
					uncheckRow( row );
				}
			}
			// An open Quick Edit form folds with the row it edits.
			var form = id.indexOf( 'post-' ) === 0 ? document.getElementById( 'edit-' + id.slice( 5 ) ) : null;
			if ( form && form.parentNode === list ) { form.hidden = ! group.open; }
		} );
		updateButton( group );
	}

	function setOpen( group, open ) {
		group.open = open;
		render( group );
		collapsed = collapsed.filter( function ( id ) { return id !== group.id; } );
		if ( ! open ) { collapsed.push( group.id ); }
	}

	function restoreLeader( row, group ) {
		row.classList.add( 'lingolane-tree-leader' );
		var cell = row.querySelector( '.column-title' );
		if ( ! cell ) { return; }
		var title = cell.querySelector( 'a.row-title' );
		group.title = title ? title.textContent : '';
		var button = cell.querySelector( '.lingolane-tree-toggle' );
		if ( ! button ) {
			button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'lingolane-tree-toggle';
			button.addEventListener( 'click', function () {
				setOpen( group, ! group.open );
				save();
			} );
			cell.insertBefore( button, cell.firstChild );
		}
		group.button = button;
		button.setAttribute( 'aria-controls', group.rows.join( ' ' ) );
		updateButton( group );
		attachFlag( row, cell, button );
	}

	templates.forEach( function ( tpl ) {
		var id = tpl.getAttribute( 'data-lingolane-leader' );
		var leader = document.getElementById( 'post-' + id );
		if ( ! leader || leader.parentNode !== list || ! leader.querySelector( '.column-title' ) || groups[ id ] ) { return; }
		var fragment = document.importNode( tpl.content, true );
		var rows = Array.prototype.filter.call( fragment.children, function ( el ) { return el.tagName === 'TR'; } );
		if ( ! rows.length ) { tpl.remove(); return; }
		var group = { id: id, rows: [], open: collapsed.indexOf( id ) === -1 };
		if ( leader.classList.contains( 'lingolane-tree-match' ) || fragment.querySelector( '.lingolane-tree-match' ) ) { group.open = true; }
		var previous = leader;
		rows.forEach( function ( row ) {
			if ( row.classList.contains( 'lingolane-tree-missing' ) ) {
				row.id = 'lingolane-missing-' + id + '-' + row.getAttribute( 'data-lingolane-lang' );
			}
			group.rows.push( row.id );
			children[ row.id ] = id;
			list.insertBefore( row, previous.nextSibling );
			previous = row;
		} );
		groups[ id ] = group;
		restoreLeader( leader, group );
		// A group opened for a search match keeps its stored state for later visits.
		render( group );
		tpl.remove();
	} );

	function decorateAllRows() {
		var rows = list.querySelectorAll( 'tr' );
		rows.forEach( function ( row ) {
			if ( row.classList.contains( 'lingolane-tree-missing' ) ) { return; }
			var cell = row.querySelector( '.column-title' );
			var button = row.querySelector( '.lingolane-tree-toggle' );
			if ( cell ) {
				attachFlag( row, cell, button );
			}
		} );
	}
	decorateAllRows();

	if ( Object.keys( groups ).length ) {
		// Pagination follows the filters in the top bar, so the last actions box is the filters one.
		var boxes = document.querySelectorAll( '.tablenav.top .alignleft.actions' );
		var actions = boxes[ boxes.length - 1 ] || document.querySelector( '.tablenav.top' );
		if ( actions ) {
			var bulk = document.createElement( 'div' );
			bulk.className = 'lingolane-tree-bulk';
			[ true, false ].forEach( function ( open ) {
				var button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'button';
				button.textContent = open ? cfg.i18n.expandAll : cfg.i18n.collapseAll;
				button.addEventListener( 'click', function () {
					Object.keys( groups ).forEach( function ( id ) { setOpen( groups[ id ], open ); } );
					save();
				} );
				bulk.appendChild( button );
			} );
			actions.appendChild( bulk );
		}
	}

	// Core replaces Quick Edit rows in place; restore decoration without moving them.
	var observer = new MutationObserver( function ( mutations ) {
		mutations.forEach( function ( mutation ) {
			mutation.addedNodes.forEach( function ( row ) {
				if ( row.nodeType !== 1 || row.tagName !== 'TR' || row.parentNode !== list ) { return; }
				if ( children[ row.id ] ) {
					restoreChild( row, groups[ children[ row.id ] ] );
				} else if ( row.id.indexOf( 'post-' ) === 0 && groups[ row.id.slice( 5 ) ] ) {
					restoreLeader( row, groups[ row.id.slice( 5 ) ] );
				} else {
					var cell = row.querySelector( '.column-title' );
					if ( cell ) { attachFlag( row, cell, null ); }
				}
			} );
		} );
	} );
	observer.observe( list, { childList: true } );
} )();
