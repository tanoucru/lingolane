/**
 * Lingolane - Frontend string scan and locator highlight script
 */
(function() {
    'use strict';

    if (typeof window.lingolaneScanData === 'undefined') {
        return;
    }

    var data = window.lingolaneScanData;

    function showBadge(text, isWarning) {
        var badge = document.createElement('div');
        badge.className = 'lingolane-preview-badge' + (isWarning ? ' is-warning' : '');
        badge.textContent = text;

        var closeBtn = document.createElement('button');
        closeBtn.innerHTML = '&times;';
        closeBtn.setAttribute('aria-label', 'Close');
        closeBtn.onclick = function() {
            badge.remove();
        };

        badge.appendChild(closeBtn);
        document.body.appendChild(badge);
    }

    function locateElement(locator) {
        if (!locator || typeof locator !== 'string') {
            return null;
        }

        // 1. Check ID.
        if (locator.indexOf('option:') === 0) {
            // Site title or tagline.
            return document.querySelector('.site-title, .site-description, header h1, header p');
        }

        if (locator.indexOf('site-logo') === 0) {
            return document.querySelector('.custom-logo, .site-logo img');
        }

        // Try direct query selector.
        try {
            var el = document.querySelector(locator);
            if (el) {
                return el;
            }
        } catch (e) {
            // Invalid selector syntax.
        }

        return null;
    }

    function highlightLocator() {
        if (!data.locator) {
            return;
        }

        var target = locateElement(data.locator);
        if (target) {
            target.classList.add('lingolane-highlight-target');
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            showBadge(data.i18n.highlight || 'Highlighted element', false);
        } else {
            showBadge(data.i18n.notFound || 'Text no longer found. Scan this page again.', true);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', highlightLocator);
    } else {
        highlightLocator();
    }
})();
