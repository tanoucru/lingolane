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

        // 1. Check ID or special option syntax.
        if (locator.indexOf('option:') === 0) {
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

    /* ------------------------------------------------------------------ Scan Mode */

    function getCssPath(el) {
        if (!(el instanceof Element)) { return ''; }
        var path = [];
        while (el && el.nodeType === Node.ELEMENT_NODE) {
            var selector = el.nodeName.toLowerCase();
            if (el.id) {
                selector += '#' + el.id;
                path.unshift(selector);
                break;
            } else {
                var sib = el, nth = 1;
                while ((sib = sib.previousElementSibling)) {
                    if (sib.nodeName.toLowerCase() === selector) { nth++; }
                }
                if (nth !== 1) { selector += ':nth-of-type(' + nth + ')'; }
            }
            path.unshift(selector);
            el = el.parentElement;
            if (path.length >= 4) { break; } // limit depth
        }
        return path.join(' > ');
    }

    function scanPageStrings() {
        if (!data.scan_token) {
            return;
        }

        var items = [];
        var seenTexts = new Set();

        // 1. Site Title & Tagline
        var titleEl = document.querySelector('.site-title, h1.site-title');
        if (titleEl && titleEl.textContent.trim()) {
            var titleTxt = titleEl.textContent.trim();
            seenTexts.add(titleTxt);
            items.push({
                identity: { domain: 'lingolane-site', source: titleTxt, context: 'blogname' },
                occurrence: { group_key: 'site', subgroup: 'header', provider: 'site', locator: 'option:blogname', supported: 1 }
            });
        }

        var descEl = document.querySelector('.site-description, p.site-description');
        if (descEl && descEl.textContent.trim()) {
            var descTxt = descEl.textContent.trim();
            seenTexts.add(descTxt);
            items.push({
                identity: { domain: 'lingolane-site', source: descTxt, context: 'blogdescription' },
                occurrence: { group_key: 'site', subgroup: 'header', provider: 'site', locator: 'option:blogdescription', supported: 1 }
            });
        }

        // 2. Navigation Menus
        var menuLinks = document.querySelectorAll('nav a, .nav-menu a, .wp-block-navigation a, #site-navigation a');
        menuLinks.forEach(function(link) {
            var text = link.textContent.trim();
            if (text && text.length > 1 && !seenTexts.has(text) && !/^\d+$/.test(text)) {
                seenTexts.add(text);
                var loc = getCssPath(link);
                items.push({
                    identity: { domain: 'lingolane-menu', source: text, context: '' },
                    occurrence: { group_key: 'menu', subgroup: '', provider: 'menu', locator: loc, supported: 1 }
                });
            }
        });

        // 3. Images with Alt text
        var images = document.querySelectorAll('img[alt]');
        images.forEach(function(img) {
            var alt = img.getAttribute('alt') ? img.getAttribute('alt').trim() : '';
            if (alt && alt.length > 1 && !seenTexts.has(alt) && !/^\d+$/.test(alt)) {
                seenTexts.add(alt);
                var loc = getCssPath(img);
                items.push({
                    identity: { domain: 'lingolane-media', source: alt, context: 'alt' },
                    occurrence: { group_key: 'media', subgroup: '', provider: 'media', locator: loc, supported: 1 }
                });
            }
        });

        // 4. Content Elements (Headings, Buttons, Labels, Paragraphs)
        var elements = document.querySelectorAll('header h1, header h2, header p, footer p, footer a, footer div, .widget, aside, h1, h2, h3, button, .wp-block-button__link');
        elements.forEach(function(el) {
            // Only direct or short leaf text
            var text = el.textContent.trim();
            if (!text || text.length < 2 || text.length > 200 || seenTexts.has(text) || /^\d+$/.test(text)) {
                return;
            }

            var group = 'other';
            var provider = 'other';
            if (el.closest('header')) {
                group = 'header';
                provider = 'site';
            } else if (el.closest('footer')) {
                group = 'footer';
                provider = 'site';
            } else if (el.closest('.widget, aside')) {
                group = 'widgets';
                provider = 'site';
            }

            seenTexts.add(text);
            var loc = getCssPath(el);
            items.push({
                identity: { domain: 'lingolane-dom', source: text, context: '' },
                occurrence: { group_key: group, subgroup: '', provider: provider, locator: loc, supported: provider === 'site' ? 1 : 0 }
            });
        });

        // Slice to MAX_ITEMS_PER_REQUEST (200)
        if (items.length > 200) {
            items = items.slice(0, 200);
        }

        // Post to lingolane_scan_accept
        var formData = new FormData();
        formData.set('action', 'lingolane_scan_accept');
        formData.set('nonce', data.nonce || '');
        formData.set('session', data.scan_token);
        formData.set('path', window.location.pathname || '/');

        items.forEach(function(it, i) {
            formData.append('items[' + i + '][identity][domain]', it.identity.domain);
            formData.append('items[' + i + '][identity][source]', it.identity.source);
            formData.append('items[' + i + '][identity][context]', it.identity.context);
            formData.append('items[' + i + '][occurrence][group_key]', it.occurrence.group_key);
            formData.append('items[' + i + '][occurrence][subgroup]', it.occurrence.subgroup);
            formData.append('items[' + i + '][occurrence][provider]', it.occurrence.provider);
            formData.append('items[' + i + '][occurrence][locator]', it.occurrence.locator);
            formData.append('items[' + i + '][occurrence][supported]', it.occurrence.supported);
        });

        fetch(data.ajax_url || '/wp-admin/admin-ajax.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function() {
            notifyParentDone();
        })
        .catch(function() {
            notifyParentDone();
        });
    }

    function notifyParentDone() {
        if (window.parent && window.parent !== window) {
            window.parent.postMessage({ type: 'lingolane_scan_page_done', path: window.location.pathname }, '*');
        }
    }

    function init() {
        highlightLocator();
        if (data.scan_token) {
            scanPageStrings();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
