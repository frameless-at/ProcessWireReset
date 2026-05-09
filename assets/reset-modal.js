/**
 * ProcessWireReset — confirmation modal driver.
 *
 * Loaded once per module config screen. Reads its strings + the
 * pre-computed dependency list and recovery URL base from
 * window.PWResetModalConfig (set by an inline script the PHP side
 * emits right before this file is referenced).
 */
(function() {
	function init() {
		var cfg = window.PWResetModalConfig || {};

		var checkbox        = document.getElementById('pwreset-enable');
		var confirmBtn      = document.getElementById('pwreset-confirm-btn');
		var hiddenSubmit    = document.getElementById('pwreset-hidden-submit');
		var hiddenConfirm   = document.getElementById('pwreset-hidden-confirm');
		var hiddenToken     = document.getElementById('pwreset-hidden-token');
		var recoveryUrlIn   = document.getElementById('pwreset-recovery-url');
		var recoverySaved   = document.getElementById('pwreset-recovery-saved');
		var recoveryCopyBtn = document.getElementById('pwreset-recovery-copy');
		var modal           = document.getElementById('pwreset-modal');

		if(!checkbox || !confirmBtn || !modal) return;
		var form = checkbox.closest('form');
		if(!form) return;

		var defaultProfile  = cfg.defaultProfile  || '';
		var noneText        = cfg.noneText        || '';
		var confirmText     = cfg.confirmText     || '';
		var recoveryUrlBase = cfg.recoveryUrlBase || '';
		var recoveryCopied  = cfg.recoveryCopied  || '';
		var precomputedDeps = cfg.precomputedDeps || [];
		var confirmed = false;

		function generateToken() {
			var bytes = new Uint8Array(32);
			(window.crypto || window.msCrypto).getRandomValues(bytes);
			return Array.prototype.map.call(bytes, function(b) {
				return ('0' + b.toString(16)).slice(-2);
			}).join('');
		}

		function buildRecoveryUrl(token) {
			var base = recoveryUrlBase;
			if(base.indexOf('://') === -1) {
				base = window.location.origin + base;
			}
			return base + '?token=' + token;
		}

		form.addEventListener('submit', function(e) {
			if(!checkbox.checked || confirmed) return;
			e.preventDefault();

			// Fresh token + URL on every modal open
			var token = generateToken();
			recoveryUrlIn.value = buildRecoveryUrl(token);
			hiddenToken.value = token;
			recoverySaved.checked = false;
			confirmBtn.disabled = true;

			// Profile
			var profileInput = form.querySelector('[name=profilePath]');
			var profileVal = profileInput ? profileInput.value.trim() : '';
			document.getElementById('pwreset-summary-profile').textContent =
				profileVal ? profileVal : defaultProfile;

			// Modules (from AsmSelect)
			var moduleItems = [];
			var asmItems = form.querySelectorAll('#wrap_keepModules .asmListItem .asmListItemLabel');
			if(asmItems.length) {
				asmItems.forEach(function(el) { moduleItems.push(el.textContent.trim()); });
			} else {
				var moduleSelect = form.querySelector('[name="keepModules[]"]');
				if(moduleSelect) {
					for(var i = 0; i < moduleSelect.options.length; i++) {
						if(moduleSelect.options[i].selected) {
							moduleItems.push(moduleSelect.options[i].text || moduleSelect.options[i].value);
						}
					}
				}
			}
			var modulesEl = document.getElementById('pwreset-summary-modules');
			if(moduleItems.length) {
				modulesEl.innerHTML = '<ul class="uk-list uk-list-disc uk-margin-remove">' +
					moduleItems.map(function(m) { return '<li>' + m + '</li>'; }).join('') + '</ul>';
			} else {
				modulesEl.textContent = noneText;
			}

			// Auto-included dependencies
			var depsRow = document.getElementById('pwreset-deps-row');
			var depsEl = document.getElementById('pwreset-summary-deps');
			if(precomputedDeps.length > 0) {
				depsRow.style.display = '';
				depsEl.innerHTML = '<ul class="uk-list uk-list-disc uk-margin-remove">' +
					precomputedDeps.map(function(d) { return '<li>' + d + '</li>'; }).join('') + '</ul>';
			} else {
				depsRow.style.display = 'none';
			}

			// Directories
			var dirsInput = form.querySelector('[name=keepDirectories]');
			var dirsVal = dirsInput ? dirsInput.value.trim() : '';
			var dirsEl = document.getElementById('pwreset-summary-dirs');
			if(dirsVal) {
				var dirLines = dirsVal.split('\n').filter(function(l) {
					return l.trim() && l.trim()[0] !== '#';
				});
				if(dirLines.length) {
					dirsEl.innerHTML = '<ul class="uk-list uk-list-disc uk-margin-remove">' +
						dirLines.map(function(d) { return '<li><code>' + d.trim() + '</code></li>'; }).join('') + '</ul>';
				} else {
					dirsEl.textContent = noneText;
				}
			} else {
				dirsEl.textContent = noneText;
			}

			// Chmod
			var chmodDirInput = form.querySelector('[name=chmodDir]');
			var chmodFileInput = form.querySelector('[name=chmodFile]');
			var chmodDirVal = (chmodDirInput && chmodDirInput.value.trim()) || (chmodDirInput ? chmodDirInput.placeholder : '0755');
			var chmodFileVal = (chmodFileInput && chmodFileInput.value.trim()) || (chmodFileInput ? chmodFileInput.placeholder : '0644');
			document.getElementById('pwreset-summary-chmod').textContent =
				'Dirs: ' + chmodDirVal + ', Files: ' + chmodFileVal;

			UIkit.modal(modal).show();
		});

		// Recovery-saved checkbox gates the execute button
		recoverySaved.addEventListener('change', function() {
			confirmBtn.disabled = !recoverySaved.checked;
		});

		// Click-to-copy
		recoveryCopyBtn.addEventListener('click', function(e) {
			e.preventDefault();
			recoveryUrlIn.select();
			recoveryUrlIn.setSelectionRange(0, 99999);
			var copied = false;
			try {
				if(navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(recoveryUrlIn.value);
					copied = true;
				} else {
					copied = document.execCommand('copy');
				}
			} catch(_) {}
			if(copied) {
				var orig = recoveryCopyBtn.getAttribute('title') || '';
				recoveryCopyBtn.setAttribute('title', recoveryCopied);
				setTimeout(function() { recoveryCopyBtn.setAttribute('title', orig); }, 1500);
			}
		});

		// Confirm: enable hidden fields, set flag, re-submit
		confirmBtn.addEventListener('click', function() {
			if(!recoverySaved.checked) return;
			confirmed = true;
			hiddenSubmit.value = '1';
			hiddenSubmit.disabled = false;
			hiddenConfirm.value = confirmText;
			hiddenConfirm.disabled = false;
			hiddenToken.disabled = false;
			UIkit.modal(modal).hide();
			form.submit();
		});
	}

	if(document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
