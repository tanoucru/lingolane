/* Lingolane · String Groups and Page Scan Management */
(function() {
    'use strict';

    var cfg = window.lingolaneStringGroups || { i18n: {} };
    var t = function(key) {
        return (cfg.i18n && cfg.i18n[key]) ? cfg.i18n[key] : key;
    };
    var escapeHtml = function(str) {
        var div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    };

    /* ------------------------------------------------------------------ Inline Editing & Dirty State */

    var dirtyForms = new Set();

    function markDirty(form, isDirty) {
        if (isDirty) {
            dirtyForms.add(form);
            form.classList.add('is-dirty');
        } else {
            dirtyForms.delete(form);
            form.classList.remove('is-dirty');
        }
    }

    window.addEventListener('beforeunload', function(event) {
        if (dirtyForms.size > 0) {
            var msg = t('unsaved') || 'You have unsaved translations. Leave anyway?';
            event.returnValue = msg;
            return msg;
        }
    });

    // Track input dirty state
    document.addEventListener('input', function(event) {
        var target = event.target;
        if (!target.classList.contains('lingolane-translation-input') &&
            !target.classList.contains('lingolane-term-name-input') &&
            !target.classList.contains('lingolane-term-desc-input')) {
            return;
        }

        var form = target.closest('form');
        if (!form) { return; }

        var orig = target.getAttribute('data-orig') || '';
        var isChanged = (target.value !== orig);
        markDirty(form, isChanged);
    });

    // Keyboard shortcut: Ctrl+Enter or Cmd+Enter to save immediately
    document.addEventListener('keydown', function(event) {
        if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
            var activeEl = document.activeElement;
            if (activeEl && (
                activeEl.classList.contains('lingolane-translation-input') ||
                activeEl.classList.contains('lingolane-term-name-input') ||
                activeEl.classList.contains('lingolane-term-desc-input')
            )) {
                var form = activeEl.closest('form');
                if (form) {
                    event.preventDefault();
                    saveForm(form);
                }
            }
        }
    });

    // Form submit handlers
    document.addEventListener('submit', function(event) {
        var form = event.target;
        if (form.classList.contains('lingolane-identity-form') || form.classList.contains('lingolane-term-form')) {
            event.preventDefault();
            saveForm(form);
        }
    });

    function saveForm(form) {
        var isTerm = form.classList.contains('lingolane-term-form');
        var statusEl = form.querySelector('.lingolane-save-status');
        var submitBtn = form.querySelector('button[type="submit"]');

        if (statusEl) {
            statusEl.textContent = t('saving') || 'Saving…';
            statusEl.className = 'lingolane-save-status is-saving';
        }
        if (submitBtn) {
            submitBtn.disabled = true;
        }

        var postData = new FormData(form);

        if (isTerm) {
            postData.set('action', 'lingolane_save_term_translation');
            postData.set('nonce', cfg.nonce_save_term || '');
        } else {
            postData.set('action', 'lingolane_save_identity');
            postData.set('nonce', cfg.nonce_save_identity || '');
        }

        fetch(cfg.ajax_url || '/wp-admin/admin-ajax.php', {
            method: 'POST',
            body: postData,
            credentials: 'same-origin'
        })
        .then(function(res) {
            return res.json();
        })
        .then(function(res) {
            if (submitBtn) { submitBtn.disabled = false; }
            if (res && res.success) {
                if (statusEl) {
                    statusEl.textContent = t('saved') || 'Saved';
                    statusEl.className = 'lingolane-save-status is-saved';
                    setTimeout(function() {
                        statusEl.textContent = '';
                        statusEl.className = 'lingolane-save-status';
                    }, 2500);
                }
                markDirty(form, false);

                // Update data-orig on inputs
                var textareas = form.querySelectorAll('textarea, input[type="text"]');
                textareas.forEach(function(input) {
                    input.setAttribute('data-orig', input.value);
                });
            } else {
                var err = (res && res.data && res.data.message) ? res.data.message : (t('failed') || 'Could not save.');
                if (statusEl) {
                    statusEl.textContent = err;
                    statusEl.className = 'lingolane-save-status is-error';
                }
            }
        })
        .catch(function(err) {
            if (submitBtn) { submitBtn.disabled = false; }
            if (statusEl) {
                statusEl.textContent = t('failed') || 'Could not save.';
                statusEl.className = 'lingolane-save-status is-error';
            }
        });
    }

    /* ------------------------------------------------------------------ Copy Slug */

    document.addEventListener('click', function(event) {
        var btn = event.target.closest('.lingolane-copy-slug');
        if (!btn) { return; }

        var slug = btn.getAttribute('data-slug') || '';
        if (!slug) { return; }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(slug).then(function() {
                var originalText = btn.textContent;
                btn.textContent = t('copiedShort');
                btn.classList.add('is-copied');
                setTimeout(function() {
                    btn.textContent = originalText;
                    btn.classList.remove('is-copied');
                }, 2000);
            }).catch(function() {
                prompt('Copy identifier:', slug);
            });
        } else {
            prompt('Copy identifier:', slug);
        }
    });

    /* ------------------------------------------------------------------ Page Scan Modal */

    var modal = document.getElementById('lingolane-scan-modal');
    if (!modal) { return; }

    var closeBtn    = document.getElementById('lingolane-modal-close-btn');
    var cancelBtn   = document.getElementById('lingolane-scan-cancel-btn');
    var startBtn    = document.getElementById('lingolane-start-scan-btn');
    var doneBtn     = document.getElementById('lingolane-scan-done-btn');
    var selectAll   = document.getElementById('lingolane-select-all-pages');
    var pageChecks  = modal.querySelectorAll('.lingolane-page-check');
    var pagesSelect = document.getElementById('lingolane-pages-selection');
    var progressSec = document.getElementById('lingolane-scan-progress');
    var progressBar = document.getElementById('lingolane-scan-progress-bar');
    var statusText  = document.getElementById('lingolane-scan-status-text');
    var iframeWrap  = document.getElementById('lingolane-scan-iframe-container');

    function openModal() {
        modal.removeAttribute('hidden');
        if (pagesSelect) { pagesSelect.style.display = ''; }
        if (progressSec) { progressSec.hidden = true; }
        if (startBtn) { startBtn.style.display = ''; startBtn.disabled = false; }
        if (doneBtn) { doneBtn.style.display = 'none'; }
        if (cancelBtn) { cancelBtn.disabled = false; }
        if (closeBtn) { closeBtn.focus(); }
    }

    function closeModal() {
        modal.setAttribute('hidden', '');
        if (iframeWrap) { iframeWrap.innerHTML = ''; }
    }

    document.querySelectorAll('.lingolane-scan-pages-btn').forEach(function(btn) {
        btn.addEventListener('click', openModal);
    });

    if (closeBtn) { closeBtn.addEventListener('click', closeModal); }
    if (cancelBtn) { cancelBtn.addEventListener('click', closeModal); }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            var checked = selectAll.checked;
            pageChecks.forEach(function(ck) {
                ck.checked = checked;
            });
        });
    }

    if (doneBtn) {
        doneBtn.addEventListener('click', function() {
            window.location.reload();
        });
    }

    if (startBtn) {
        startBtn.addEventListener('click', function() {
            var selectedPaths = [];
            pageChecks.forEach(function(ck) {
                if (ck.checked) {
                    selectedPaths.push(ck.value);
                }
            });

            if (selectedPaths.length === 0) {
                alert(t('selectPages') || 'Please select at least one page to scan.');
                return;
            }

            startBtn.disabled = true;
            cancelBtn.disabled = true;
            if (pagesSelect) { pagesSelect.style.display = 'none'; }
            if (progressSec) { progressSec.hidden = false; }
            progressBar.style.width = '5%';
            statusText.textContent = t('scanning') || 'Starting scan session…';

            var formData = new FormData();
            formData.set('action', 'lingolane_scan_start');
            formData.set('nonce', cfg.nonce_scan || '');
            selectedPaths.forEach(function(p) {
                formData.append('paths[]', p);
            });

            fetch(cfg.ajax_url || '/wp-admin/admin-ajax.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(function(res) { return res.json(); })
            .then(function(res) {
                if (!res || !res.success || !res.data) {
                    throw new Error((res && res.data && res.data.message) ? res.data.message : 'Failed to start scan.');
                }
                var sessionId = res.data.session_id || res.data.session;
                var pages = res.data.pages || selectedPaths;
                scanPagesSequential(sessionId, pages);
            })
            .catch(function(err) {
                statusText.textContent = err.message || 'Scan error occurred.';
                cancelBtn.disabled = false;
                startBtn.disabled = false;
            });
        });
    }

    function scanPagesSequential(sessionId, pages) {
        var currentIndex = 0;
        var total = pages.length;

        function scanNext() {
            if (currentIndex >= total) {
                progressBar.style.width = '100%';
                statusText.textContent = t('scanComplete') || 'Scan complete! Strings updated.';
                if (startBtn) { startBtn.style.display = 'none'; }
                if (doneBtn) { doneBtn.style.display = ''; doneBtn.focus(); }
                if (iframeWrap) { iframeWrap.innerHTML = ''; }
                return;
            }

            var path = pages[currentIndex];
            var percent = Math.round(((currentIndex + 1) / total) * 100);
            progressBar.style.width = percent + '%';
            statusText.textContent = 'Scanning page ' + (currentIndex + 1) + ' of ' + total + ': ' + path;

            // Load iframe with ?lingolane_scan=1&token=...
            if (iframeWrap) { iframeWrap.innerHTML = ''; }
            var iframe = document.createElement('iframe');
            iframe.style.display = 'none';
            var url = (cfg.home_url || '/') + path.replace(/^\//, '');
            url += (url.indexOf('?') === -1 ? '?' : '&') + 'lingolane_scan=1&token=' + encodeURIComponent(sessionId);
            iframe.src = url;

            var done = false;
            function onMsg(ev) {
                if (ev && ev.data && ev.data.type === 'lingolane_scan_page_done') {
                    if (!done) {
                        done = true;
                        clearTimeout(timer);
                        window.removeEventListener('message', onMsg);
                        currentIndex++;
                        setTimeout(scanNext, 1100);
                    }
                }
            }

            var timer = setTimeout(function() {
                if (!done) {
                    done = true;
                    window.removeEventListener('message', onMsg);
                    currentIndex++;
                    scanNext();
                }
            }, 6000); // 6s fallback per page

            window.addEventListener('message', onMsg);

            if (iframeWrap) {
                iframeWrap.appendChild(iframe);
            }
        }

        scanNext();
    }

    /* ------------------------------------------------------------------ AI Translation Integration (P5) */

    // 1. Single string AI suggest
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.lingolane-ai-btn');
        if (!btn) { return; }
        e.preventDefault();

        var row = btn.closest('tr');
        var form = btn.closest('form');
        if (!row || !form) { return; }

        var sourceEl = row.querySelector('.lingolane-source-text');
        var sourceText = sourceEl ? sourceEl.innerText.trim() : '';
        var textarea = form.querySelector('.lingolane-translation-input');
        var targetLang = (textarea && textarea.getAttribute('data-lang')) || 'vi';
        var context = (textarea && textarea.getAttribute('data-context')) || '';
        var statusEl = form.querySelector('.lingolane-save-status');

        if (!sourceText || !textarea) { return; }

        btn.disabled = true;
        var origHtml = btn.innerHTML;
        btn.innerHTML = '<span>...</span>';

        if (window.LingolaneAI && typeof window.LingolaneAI.translate === 'function') {
            window.LingolaneAI.translate(sourceText, 'auto', targetLang, context)
                .then(function(res) {
                    btn.disabled = false;
                    btn.innerHTML = origHtml;
                    if (res && res.translation) {
                        textarea.value = res.translation;
                        markDirty(form, true);
                        if (statusEl) {
                            statusEl.textContent = t('aiSuggested') + (res.engine === 'chrome-ondevice' ? 'Chrome AI' : 'Gemini AI');
                            statusEl.className = 'lingolane-save-status is-saved';
                        }
                    }
                })
                .catch(function(err) {
                    btn.disabled = false;
                    btn.innerHTML = origHtml;
                    alert(t('aiError') + (err.message || err));
                });
        } else {
            btn.disabled = false;
            btn.innerHTML = origHtml;
            alert(t('aiMissing'));
        }
    });

    // 2. Term AI suggest
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.lingolane-ai-term-btn');
        if (!btn) { return; }
        e.preventDefault();

        var row = btn.closest('tr');
        var form = btn.closest('form');
        if (!row || !form) { return; }

        var sourceEl = row.querySelector('.lingolane-source-text');
        var sourceText = sourceEl ? sourceEl.innerText.trim() : '';
        var nameInput = form.querySelector('.lingolane-term-name-input');
        var descInput = form.querySelector('.lingolane-term-desc-input');
        var targetLang = form.querySelector('input[name="language"]') ? form.querySelector('input[name="language"]').value : 'vi';
        var statusEl = form.querySelector('.lingolane-save-status');

        if (!sourceText || !nameInput) { return; }

        btn.disabled = true;
        var origHtml = btn.innerHTML;
        btn.innerHTML = '<span>...</span>';

        if (window.LingolaneAI && typeof window.LingolaneAI.translate === 'function') {
            window.LingolaneAI.translate(sourceText, 'auto', targetLang, 'taxonomy term name')
                .then(function(res) {
                    btn.disabled = false;
                    btn.innerHTML = origHtml;
                    if (res && res.translation) {
                        nameInput.value = res.translation;
                        markDirty(form, true);
                        if (statusEl) {
                            statusEl.textContent = t('aiSuggested') + (res.engine === 'chrome-ondevice' ? 'Chrome AI' : 'Gemini AI');
                            statusEl.className = 'lingolane-save-status is-saved';
                        }
                    }
                })
                .catch(function(err) {
                    btn.disabled = false;
                    btn.innerHTML = origHtml;
                    alert(t('aiError') + (err.message || err));
                });
        } else {
            btn.disabled = false;
            btn.innerHTML = origHtml;
            alert(t('aiMissing'));
        }
    });

    // 3. Batch AI translation on current page
    document.addEventListener('click', function(e) {
        var batchBtn = e.target.closest('.lingolane-batch-ai-btn');
        if (!batchBtn) { return; }
        e.preventDefault();

        var textareas = document.querySelectorAll('.lingolane-translation-input');
        var missingItems = [];

        textareas.forEach(function(ta) {
            if (!ta.value || !ta.value.trim()) {
                var row = ta.closest('tr');
                var sourceEl = row ? row.querySelector('.lingolane-source-text') : null;
                var sourceText = sourceEl ? sourceEl.innerText.trim() : '';
                if (sourceText) {
                    missingItems.push({
                        textarea: ta,
                        form: ta.closest('form'),
                        sourceText: sourceText,
                        targetLang: ta.getAttribute('data-lang') || 'vi',
                        context: ta.getAttribute('data-context') || ''
                    });
                }
            }
        });

        if (missingItems.length === 0) {
            alert(t('allTranslated'));
            return;
        }

        if (!confirm(t('batchConfirm').replace('%d', missingItems.length))) {
            return;
        }

        batchBtn.disabled = true;
        var origHtml = batchBtn.innerHTML;
        batchBtn.innerHTML = '<span>' + escapeHtml(t('batchProgress').replace('%1$d', 0).replace('%2$d', missingItems.length)) + '</span>';

        var currentIndex = 0;
        function processNext() {
            if (currentIndex >= missingItems.length) {
                batchBtn.disabled = false;
                batchBtn.innerHTML = origHtml;
                alert(t('batchDone').replace('%d', missingItems.length));
                return;
            }

            var item = missingItems[currentIndex];
            batchBtn.innerHTML = '<span>' + escapeHtml(t('batchProgress').replace('%1$d', currentIndex + 1).replace('%2$d', missingItems.length)) + '</span>';

            if (window.LingolaneAI && typeof window.LingolaneAI.translate === 'function') {
                window.LingolaneAI.translate(item.sourceText, 'auto', item.targetLang, item.context)
                    .then(function(res) {
                        if (res && res.translation) {
                            item.textarea.value = res.translation;
                            if (item.form) {
                                markDirty(item.form, true);
                                var statusEl = item.form.querySelector('.lingolane-save-status');
                                if (statusEl) {
                                    statusEl.textContent = t('aiSuggested') + (res.engine === 'chrome-ondevice' ? 'Chrome' : 'Gemini');
                                    statusEl.className = 'lingolane-save-status is-saved';
                                }
                            }
                        }
                        currentIndex++;
                        setTimeout(processNext, 150);
                    })
                    .catch(function(err) {
                        console.warn('[LingolaneAI Batch] Error on item ' + currentIndex + ':', err);
                        currentIndex++;
                        setTimeout(processNext, 150);
                    });
            } else {
                batchBtn.disabled = false;
                batchBtn.innerHTML = origHtml;
                alert(t('aiMissing'));
            }
        }

        processNext();
    });

})();
