(function () {
	'use strict';

	var cfg = window.DoxFunctions || { ajaxUrl: '', nonce: '', starter: '', i18n: {} };

	function t(key) {
		return (cfg.i18n && cfg.i18n[key]) ? cfg.i18n[key] : key;
	}

	function ajax(action, params, nonce) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', nonce || cfg.nonce);
		Object.keys(params || {}).forEach(function (k) { body.set(k, params[k]); });
		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); });
	}

	// ¿El foco está en un campo de texto? Entonces los atajos no actúan.
	function typing(el) {
		if (!el) return false;
		var tag = (el.tagName || '').toLowerCase();
		return tag === 'input' || tag === 'textarea' || tag === 'select' || el.isContentEditable
			|| !!(el.closest && el.closest('.CodeMirror'));
	}

	// Modo de CodeMirror por tipo. text/x-php es PHP desde la primera línea
	// (los snippets no llevan <?php); application/x-httpd-php empezaría en HTML.
	var MODES = { php: 'text/x-php', html: 'htmlmixed' };

	document.addEventListener('DOMContentLoaded', function () {

		/* ------------------------------------------------------------------
		 * Editor
		 * ------------------------------------------------------------------ */
		var ta = document.getElementById('dxf-code');
		var form = ta ? ta.closest('form') : null;
		var editor = null;
		var dirty = false;
		var dirtyPill = form ? form.querySelector('.dxf-dirty') : null;

		function setDirty(on) {
			dirty = on;
			if (dirtyPill) dirtyPill.hidden = !on;
		}

		function currentType() {
			var checked = form ? form.querySelector('input[name="type"]:checked') : null;
			return checked ? checked.value : 'php';
		}

		function syncEditor() {
			if (editor) editor.save();
		}

		function saveForm() {
			if (!form) return;
			syncEditor();
			setDirty(false);
			if (form.requestSubmit) form.requestSubmit(); else form.submit();
		}

		if (ta && window.wp && wp.codeEditor) {
			var settings = wp.codeEditor.initialize(ta, {
				codemirror: {
					mode: MODES[currentType()] || MODES.php,
					lineNumbers: true,
					indentUnit: 4,
					tabSize: 4,
					indentWithTabs: true,
					lineWrapping: true,
					styleActiveLine: true,
					matchBrackets: true,
					autoCloseBrackets: true,
					lint: false,
					gutters: ['CodeMirror-linenumbers'],
					extraKeys: {
						'Ctrl-S': saveForm,
						'Cmd-S': saveForm
					}
				}
			});
			editor = settings.codemirror;
			editor.on('change', function () { setDirty(true); });
		}

		if (form) {
			// Cambiar de tipo: se ven las opciones de ese tipo, el editor
			// cambia de resaltado y, si el código es aún el de ejemplo de
			// PHP, se vacía (un comentario de PHP saldría como texto en la web).
			form.querySelectorAll('input[name="type"]').forEach(function (radio) {
				radio.addEventListener('change', function () {
					var type = currentType();
					form.setAttribute('data-type', type);
					var result = document.getElementById('dxf-validate-result');
					if (result) { result.textContent = ''; result.className = 'dxf-validate'; }
					if (!editor) return;
					editor.setOption('mode', MODES[type] || MODES.php);
					var code = editor.getValue();
					if (type === 'html' && code.trim() === String(cfg.starter).trim()) editor.setValue('');
					if (type === 'php' && code.trim() === '') editor.setValue(cfg.starter);
					editor.refresh();
				});
			});

			// Unsaved-changes guard: typing anywhere in the form (or in
			// CodeMirror, above) marks it dirty; submitting clears it.
			form.addEventListener('input', function () { setDirty(true); });
			form.addEventListener('change', function () { setDirty(true); });
			form.addEventListener('submit', function () {
				syncEditor();
				dirty = false;
				var btn = form.querySelector('button[type="submit"]');
				if (btn) btn.setAttribute('aria-busy', 'true');
			});
			window.addEventListener('beforeunload', function (e) {
				if (!dirty) return;
				e.preventDefault();
				e.returnValue = '';
			});

			// Ctrl/Cmd+S also saves when focus is outside CodeMirror
			// (CodeMirror handles its own keystroke via extraKeys).
			document.addEventListener('keydown', function (e) {
				if ((e.ctrlKey || e.metaKey) && String(e.key).toLowerCase() === 's') {
					if (e.target && e.target.closest && e.target.closest('.CodeMirror')) return;
					e.preventDefault();
					saveForm();
				}
			});

			// Keep the visual switch in sync with its (visually hidden) checkbox.
			form.querySelectorAll('.dxf-switch-inline input[type=checkbox]').forEach(function (input) {
				input.addEventListener('change', function () {
					var sw = input.parentElement.querySelector('.dxf-switch');
					if (sw) sw.classList.toggle('is-on', input.checked);
				});
			});

			// "Check syntax" button: validates server-side without saving.
			var validateBtn = document.getElementById('dxf-validate');
			var result = document.getElementById('dxf-validate-result');
			if (validateBtn) {
				validateBtn.addEventListener('click', function () {
					syncEditor();
					validateBtn.setAttribute('aria-busy', 'true');
					if (result) {
						result.textContent = t('validating');
						result.className = 'dxf-validate';
					}
					ajax('dox_functions_validate', { code: ta.value, type: currentType() })
						.then(function (res) {
							if (!result) return;
							var msg = (res && res.data && res.data.message) ? res.data.message : t('request_error');
							result.textContent = msg;
							result.className = 'dxf-validate ' + (res && res.success ? 'is-ok' : 'is-err');
						})
						.catch(function () {
							if (!result) return;
							result.textContent = t('request_error');
							result.className = 'dxf-validate is-err';
						})
						.then(function () { validateBtn.removeAttribute('aria-busy'); });
				});
			}
		}

		/* ------------------------------------------------------------------
		 * Lista
		 * ------------------------------------------------------------------ */

		// Toggle snippets in place; on any failure fall back to the plain
		// nonce'd link (full page load), which also covers no-JS.
		document.querySelectorAll('a.dxf-switch[data-id]').forEach(function (sw) {
			sw.addEventListener('click', function (e) {
				if (!cfg.ajaxUrl || !sw.dataset.nonce) return;
				e.preventDefault();
				if (sw.getAttribute('aria-busy')) return;
				sw.setAttribute('aria-busy', 'true');
				ajax('dox_functions_toggle', { id: sw.dataset.id }, sw.dataset.nonce)
					.then(function (res) {
						if (res && res.success) {
							var on = !!(res.data && res.data.active);
							sw.classList.toggle('is-on', on);
							sw.setAttribute('aria-checked', on ? 'true' : 'false');
							var row = sw.closest('tr');
							if (row) row.setAttribute('data-active', on ? '1' : '0');
							sw.removeAttribute('aria-busy');
						} else {
							window.location.href = sw.href;
						}
					})
					.catch(function () {
						window.location.href = sw.href;
					});
			});
		});

		// Filtros con contador y buscador, sin recargar.
		var table = document.querySelector('.dxf-table');
		var search = document.getElementById('dxf-search');
		var noMatch = document.querySelector('.dxf-nomatch');
		var filter = 'all';

		function matches(row) {
			switch (filter) {
				case 'on': return row.dataset.active === '1';
				case 'off': return row.dataset.active === '0';
				case 'error': return row.dataset.error === '1';
				case 'php': case 'html': return row.dataset.type === filter;
				default: return true;
			}
		}

		function apply() {
			if (!table) return;
			var q = search ? search.value.trim().toLowerCase() : '';
			var shown = 0;
			table.querySelectorAll('tbody tr').forEach(function (row) {
				var ok = matches(row) && (!q || (row.dataset.search || '').indexOf(q) !== -1);
				row.hidden = !ok;
				if (ok) shown++;
			});
			table.hidden = shown === 0;
			if (noMatch) noMatch.hidden = shown !== 0;
		}

		function setFilter(name) {
			filter = name;
			document.querySelectorAll('.dxf-filter').forEach(function (b) {
				b.setAttribute('aria-pressed', b.dataset.filter === name ? 'true' : 'false');
			});
			if (name === 'all' && search) search.value = '';
			apply();
		}

		document.querySelectorAll('.dxf-filter').forEach(function (b) {
			b.addEventListener('click', function () { setFilter(b.dataset.filter); });
		});
		document.querySelectorAll('[data-dxf-filter-go]').forEach(function (b) {
			b.addEventListener('click', function () {
				setFilter(b.getAttribute('data-dxf-filter-go'));
				if (table) table.scrollIntoView({ behavior: 'instant', block: 'nearest' });
			});
		});
		if (search) {
			search.addEventListener('input', apply);
			document.addEventListener('keydown', function (e) {
				if (e.key === '/' && !typing(document.activeElement)) {
					e.preventDefault();
					search.focus();
				} else if (e.key === 'Escape' && document.activeElement === search) {
					search.value = '';
					apply();
					search.blur();
				}
			});
		}

		// Import: choosing a file submits the form right away.
		var importInput = document.getElementById('dxf-import-file');
		if (importInput) {
			importInput.addEventListener('change', function () {
				if (importInput.files.length && importInput.form) importInput.form.submit();
			});
		}
	});
})();
