(function () {
	'use strict';

	var cfg = window.DoxFunctions || { ajaxUrl: '', nonce: '', i18n: {} };

	function t(key) {
		return (cfg.i18n && cfg.i18n[key]) ? cfg.i18n[key] : key;
	}

	function ajax(action, params) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', cfg.nonce);
		Object.keys(params || {}).forEach(function (k) { body.set(k, params[k]); });
		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); });
	}

	document.addEventListener('DOMContentLoaded', function () {

		/* ------------------------------------------------------------------
		 * Editor page
		 * ------------------------------------------------------------------ */
		var ta = document.getElementById('dox-code');
		var form = ta ? ta.closest('form') : null;
		var editor = null;
		var dirty = false;

		function syncEditor() {
			if (editor) editor.save();
		}

		function saveForm() {
			if (!form) return;
			syncEditor();
			dirty = false;
			if (form.requestSubmit) form.requestSubmit(); else form.submit();
		}

		if (ta && window.wp && wp.codeEditor) {
			var settings = wp.codeEditor.initialize(ta, {
				codemirror: {
					mode: 'application/x-httpd-php',
					lineNumbers: true,
					indentUnit: 4,
					tabSize: 4,
					indentWithTabs: true,
					lineWrapping: true,
					styleActiveLine: true,
					matchBrackets: true,
					autoCloseBrackets: true,
					extraKeys: {
						'Ctrl-S': saveForm,
						'Cmd-S': saveForm
					}
				}
			});
			editor = settings.codemirror;
			editor.on('change', function () { dirty = true; });
		}

		if (form) {
			// Unsaved-changes guard: typing anywhere in the form (or in
			// CodeMirror, above) marks it dirty; submitting clears it.
			form.addEventListener('input', function () { dirty = true; });
			form.addEventListener('submit', function () {
				syncEditor();
				dirty = false;
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
			form.querySelectorAll('.dox-switch-inline input[type=checkbox]').forEach(function (input) {
				input.addEventListener('change', function () {
					var sw = input.parentElement.querySelector('.dox-switch');
					if (sw) sw.classList.toggle('is-on', input.checked);
				});
			});

			// "Check syntax" button: validates server-side without saving.
			var validateBtn = document.getElementById('dox-validate');
			var result = document.getElementById('dox-validate-result');
			if (validateBtn) {
				validateBtn.addEventListener('click', function () {
					syncEditor();
					if (result) {
						result.textContent = t('validating');
						result.className = 'dox-validate-result';
					}
					ajax('dox_functions_validate', { code: ta.value })
						.then(function (res) {
							if (!result) return;
							var msg = (res && res.data && res.data.message) ? res.data.message : t('request_error');
							result.textContent = msg;
							result.className = 'dox-validate-result ' + (res && res.success ? 'is-ok' : 'is-err');
						})
						.catch(function () {
							if (!result) return;
							result.textContent = t('request_error');
							result.className = 'dox-validate-result is-err';
						});
				});
			}
		}

		/* ------------------------------------------------------------------
		 * List page
		 * ------------------------------------------------------------------ */

		// Toggle snippets in place; on any failure fall back to the plain
		// nonce'd link (full page load), which also covers no-JS.
		document.querySelectorAll('a.dox-switch[data-id]').forEach(function (sw) {
			sw.addEventListener('click', function (e) {
				if (!cfg.ajaxUrl || !cfg.nonce) return;
				e.preventDefault();
				if (sw.dataset.busy) return;
				sw.dataset.busy = '1';
				ajax('dox_functions_toggle', { id: sw.dataset.id })
					.then(function (res) {
						if (res && res.success) {
							var on = !!(res.data && res.data.active);
							sw.classList.toggle('is-on', on);
							sw.setAttribute('aria-checked', on ? 'true' : 'false');
							delete sw.dataset.busy;
						} else {
							window.location.href = sw.href;
						}
					})
					.catch(function () {
						window.location.href = sw.href;
					});
			});
		});

		// Import: choosing a file submits the form right away.
		var importInput = document.getElementById('dox-import-file');
		if (importInput) {
			importInput.addEventListener('change', function () {
				if (importInput.files.length && importInput.form) importInput.form.submit();
			});
		}
	});
})();
