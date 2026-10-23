/**
 * Lingolane Language Switcher: Dropdown interactions & Untranslated Notice Modal.
 */
( function () {
    'use strict';

    function initSwitcher() {
        var switchers = document.querySelectorAll( '.lingolane-switcher--dropdown' );
        switchers.forEach( function ( switcher ) {
            var toggle = switcher.querySelector( '.lingolane-switcher__toggle' );
            var menu = switcher.querySelector( '.lingolane-switcher__menu' );
            if ( ! toggle || ! menu ) { return; }

            toggle.addEventListener( 'click', function ( e ) {
                e.stopPropagation();
                var isOpen = switcher.classList.contains( 'is-open' );
                closeAllDropdowns();
                if ( ! isOpen ) {
                    switcher.classList.add( 'is-open' );
                    toggle.setAttribute( 'aria-expanded', 'true' );
                }
            } );
        } );

        // Close dropdowns on outside click or escape.
        document.addEventListener( 'click', function ( e ) {
            if ( ! e.target.closest( '.lingolane-switcher--dropdown' ) ) {
                closeAllDropdowns();
            }
        } );

        document.addEventListener( 'keydown', function ( e ) {
            if ( 'Escape' === e.key ) {
                closeAllDropdowns();
                closeNoticeDialog();
            }
        } );

        // Handle clicks on switcher items for untranslated content.
        document.addEventListener( 'click', function ( e ) {
            var link = e.target.closest( 'a[data-lingolane-missing="1"]' );
            if ( ! link ) { return; }

            var action = link.getAttribute( 'data-action' ) || 'notice';
            var redirectUrl = link.getAttribute( 'data-redirect' );
            var message = link.getAttribute( 'data-message' );
            var langName = link.getAttribute( 'data-lang-name' ) || '';

            if ( 'redirect' === action && redirectUrl ) {
                // Allow direct navigation or window redirect.
                return;
            }

            // Default 'notice': prevent navigation and open the dialog.
            e.preventDefault();
            e.stopPropagation();
            closeAllDropdowns();

            if ( message && langName && message.indexOf( '%s' ) !== -1 ) {
                message = message.replace( '%s', langName );
            }

            openNoticeDialog( message );
        } );

        // Check if current URL has untranslated query parameter (fallback for no-js redirect).
        var params = new URLSearchParams( window.location.search );
        if ( params.has( 'lingolane_untranslated' ) ) {
            var dialog = document.getElementById( 'lingolane-notice-dialog' );
            if ( dialog ) {
                openNoticeDialog();
                // Clean up query param from URL without reload.
                if ( window.history && window.history.replaceState ) {
                    params.delete( 'lingolane_untranslated' );
                    var newSearch = params.toString() ? '?' + params.toString() : '';
                    var cleanUrl = window.location.pathname + newSearch + window.location.hash;
                    window.history.replaceState( null, '', cleanUrl );
                }
            }
        }

        // Close handlers for dialog.
        var dialogEl = document.getElementById( 'lingolane-notice-dialog' );
        if ( dialogEl ) {
            var closeButtons = dialogEl.querySelectorAll( '.lingolane-dialog__close, .lingolane-dialog__btn' );
            closeButtons.forEach( function ( btn ) {
                btn.addEventListener( 'click', function ( e ) {
                    e.preventDefault();
                    closeNoticeDialog();
                } );
            } );

            dialogEl.addEventListener( 'click', function ( e ) {
                if ( e.target === dialogEl ) {
                    closeNoticeDialog();
                }
            } );
        }
    }

    function closeAllDropdowns() {
        var openSwitchers = document.querySelectorAll( '.lingolane-switcher--dropdown.is-open' );
        openSwitchers.forEach( function ( s ) {
            s.classList.remove( 'is-open' );
            var toggle = s.querySelector( '.lingolane-switcher__toggle' );
            if ( toggle ) { toggle.setAttribute( 'aria-expanded', 'false' ); }
        } );
    }

    function openNoticeDialog( customMessage ) {
        var dialog = document.getElementById( 'lingolane-notice-dialog' );
        if ( ! dialog ) {
            alert( customMessage || 'Ngôn ngữ bạn chọn hiện chưa có bản dịch.' );
            return;
        }

        if ( customMessage ) {
            var desc = dialog.querySelector( '#lingolane-dialog-desc' );
            if ( desc ) { desc.textContent = customMessage; }
        }

        dialog.removeAttribute( 'hidden' );
        dialog.classList.add( 'is-open' );
        document.body.classList.add( 'lingolane-dialog-open' );

        var btn = dialog.querySelector( '.lingolane-dialog__btn' );
        if ( btn ) { btn.focus(); }
    }

    function closeNoticeDialog() {
        var dialog = document.getElementById( 'lingolane-notice-dialog' );
        if ( ! dialog ) { return; }
        dialog.classList.remove( 'is-open' );
        dialog.setAttribute( 'hidden', '' );
        document.body.classList.remove( 'lingolane-dialog-open' );
    }

    if ( 'loading' === document.readyState ) {
        document.addEventListener( 'DOMContentLoaded', initSwitcher );
    } else {
        initSwitcher();
    }
} )();
