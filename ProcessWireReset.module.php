<?php namespace ProcessWire;

require_once __DIR__ . '/InstallerCore.php';

/**
 * ProcessWire Reset
 *
 * Resets a ProcessWire installation to a clean profile state while preserving
 * the current superuser account and selected site modules.
 *
 * The SQL import uses ProcessWire's own installer code (InstallerCore extends
 * the upstream Installer class from install.php). The installer wizard GUI is
 * completely removed; its variables are pre-populated from the live installation.
 *
 * After a reset, kept modules are re-installed on the next request via a
 * deferred pending-file mechanism so that PW has a clean bootstrap state.
 *
 * @property string   $profilePath     Custom profile directory (contains install/ and templates/)
 * @property string[] $keepModules     Module class names to preserve
 * @property string   $keepDirectories One path per line, relative to site/
 * @property string   $chmodDir        Directory permission override (e.g. "0755")
 * @property string   $chmodFile       File permission override (e.g. "0644")
 */
class ProcessWireReset extends WireData implements Module, ConfigurableModule {

	/** Confirmation token the user must submit to trigger the reset */
	const CONFIRM_TEXT = 'CONFIRMED';

	/** Pending module-installs file (written by executeReset, read on next boot) */
	const PENDING_FILE = '.pending-installs.json';

	/** Pending custom-table-restore file */
	const PENDING_TABLES_FILE = '.pending-custom-tables.bin';

	/** @var string[] Filesystem operation failures collected during Phase 3 */
	protected $fsFailures = [];

	// =========================================================================
	// Module registration
	// =========================================================================

	public static function getModuleInfo() {
		return [
			'title'    => 'ProcessWire Reset',
			'version'  => '1.0.0',
			'summary'  => 'Resets a ProcessWire installation to a clean profile state while preserving the superuser and selected modules.',
			'author'   => 'frameless',
			'icon'     => 'refresh',
			'singular' => true,
			// Only autoload when there are deferred tasks from a previous reset.
			'autoload' => function() {
				return file_exists(__DIR__ . '/' . self::PENDING_FILE)
				    || file_exists(__DIR__ . '/' . self::PENDING_TABLES_FILE);
			},
			'requires' => ['ProcessWire>=3.0.0'],
		];
	}

	public function __construct() {
		parent::__construct();
		$this->set('profilePath',     '');
		$this->set('keepModules',     []);
		$this->set('keepDirectories', '');
		$this->set('chmodDir',        '');
		$this->set('chmodFile',       '');
	}

	// =========================================================================
	// Lifecycle — deferred post-reset tasks
	// =========================================================================

	/**
	 * Triggered on every boot when pending files exist (conditional autoload).
	 * Schedules processPendingInstalls() to run after PW is fully ready.
	 */
	public function init() {
		if(!file_exists(__DIR__ . '/' . self::PENDING_FILE)
		&& !file_exists(__DIR__ . '/' . self::PENDING_TABLES_FILE)) return;
		$this->addHookAfter('ProcessWire::ready', $this, 'processPendingInstalls');
	}

	/**
	 * Install kept modules and restore their configuration.
	 *
	 * Runs on the first request after a reset. PW has a clean bootstrap at
	 * this point so $modules->install() works correctly.
	 *
	 * Algorithm:
	 *   1. Read + delete pending file (delete first to prevent infinite retries).
	 *   2. Elevate to superuser so install() has full permissions.
	 *   3. Refresh the modules cache so PW rediscovers all files.
	 *   4. Multi-pass install loop — retries modules whose dependencies were not
	 *      ready in an earlier pass. "Duplicate entry" = already auto-installed
	 *      as a dependency → treat as success.
	 *   5. Restore backed-up data + flags for every module that had a backup.
	 *      Skip modules without backup (new transitive deps): their fresh-install
	 *      defaults are correct and we must not zero-out their autoload flags.
	 *   6. Restore custom tables (after all installs so CREATE TABLE conflicts
	 *      from install() calls are overwritten by the real backed-up data).
	 */
	public function processPendingInstalls() {
		$pendingFile = __DIR__ . '/' . self::PENDING_FILE;
		if(!file_exists($pendingFile)) {
			$this->processPendingCustomTables($this->wire('database'));
			return;
		}

		$raw     = file_get_contents($pendingFile);
		$pending = ($raw !== false) ? json_decode($raw, true) : null;

		// Delete before processing — worst case is one extra attempt, not a loop
		if(!unlink($pendingFile)) {
			$this->wire('log')->error("ProcessWireReset: cannot remove $pendingFile");
		}

		if(!is_array($pending) || empty($pending)) {
			$this->processPendingCustomTables($this->wire('database'));
			return;
		}

		// Index by class name, exclude self
		$byClass = [];
		foreach($pending as $item) {
			if(!empty($item['class']) && $item['class'] !== $this->className()) {
				$byClass[$item['class']] = $item;
			}
		}

		$database = $this->wire('database');
		$modules  = $this->wire('modules');
		$log      = $this->wire('log');

		// Elevate to superuser for install operations
		$savedUser  = $this->wire('user');
		$superuser  = $this->wire('users')->get($this->wire('config')->superUserPageID);
		if($superuser && $superuser->id) $this->wire('user', $superuser);

		$modules->refresh();

		// Multi-pass install — a module may fail because its dependency isn't
		// installed yet; retry in the next pass until no more progress is made.
		$remaining = array_keys($byClass);
		$installed = [];
		$failed    = [];
		$maxPasses = count($remaining) + 2;

		for($pass = 0; $pass < $maxPasses && !empty($remaining); $pass++) {
			$nextRemaining = [];
			$progress      = false;

			foreach($remaining as $className) {
				// Check DB directly (bypasses stale in-memory cache)
				$stmt = $database->prepare("SELECT id FROM modules WHERE class = :c");
				$stmt->execute([':c' => $className]);
				if($stmt->fetch()) {
					$installed[$className] = true;
					$progress = true;
					continue;
				}

				try {
					$modules->install($className);
					$modules->refresh();
					$installed[$className] = true;
					$progress = true;
				} catch(\Exception $e) {
					$msg = $e->getMessage();
					// Duplicate entry = auto-installed as dep → success
					if(stripos($msg, 'Duplicate entry') !== false) {
						$installed[$className] = true;
						$progress = true;
						$modules->refresh();
					} else {
						$nextRemaining[]      = $className;
						$failed[$className]   = $msg;
					}
				}
			}

			$remaining = $nextRemaining;
			if(!$progress) break;
		}

		// Restore backed-up data + flags.
		// Skip items without a 'flags' key — those are transitive dependencies
		// that had no pre-reset entry; overwriting flags=0 would break autoload.
		foreach($byClass as $className => $item) {
			if(!array_key_exists('flags', $item)) continue;
			try {
				$database->prepare(
					"UPDATE modules SET data = :data, flags = :flags WHERE class = :class"
				)->execute([
					':data'  => (string) $item['data'],
					':flags' => (int)    $item['flags'],
					':class' => $className,
				]);
			} catch(\Exception $e) {
				$log->save('processwirereset', "Config restore failed for $className: " . $e->getMessage());
			}
		}

		// Log summary
		if(!empty($installed)) {
			$log->save('processwirereset', 'Re-installed: ' . implode(', ', array_keys($installed)));
		}
		foreach($remaining as $className) {
			$log->save('processwirereset', "Install failed for $className: " . ($failed[$className] ?? 'unknown'));
		}

		// Restore custom tables after all module installs
		$this->processPendingCustomTables($database);

		$this->wire('user', $savedUser);
	}

	/**
	 * Read the pending custom-tables file and restore backed-up table data.
	 * Runs at the end of processPendingInstalls() — after all install() calls
	 * have created their (empty) fresh tables, we overwrite them with real data.
	 *
	 * @param WireDatabasePDO $database
	 */
	protected function processPendingCustomTables($database) {
		$file = __DIR__ . '/' . self::PENDING_TABLES_FILE;
		if(!file_exists($file)) return;

		$raw = file_get_contents($file);
		if(!unlink($file)) {
			$this->wire('log')->error("ProcessWireReset: cannot remove $file");
		}
		if($raw === false || $raw === '') return;

		$tables = @unserialize($raw, ['allowed_classes' => false]);
		if(!is_array($tables) || empty($tables)) return;

		try {
			$restored = $this->restoreCustomTables($database, $tables);
			if(!empty($restored)) {
				$this->wire('log')->save('processwirereset', 'Restored tables: ' . implode(', ', $restored));
			}
		} catch(\Exception $e) {
			$this->wire('log')->error('ProcessWireReset: table restore failed: ' . $e->getMessage());
		}
	}

	// =========================================================================
	// Module Config GUI
	// =========================================================================

	/**
	 * Entry point for PW's module config system.
	 * Handles the POST reset action, then returns the config form.
	 *
	 * @param  array $data Saved module config
	 * @return InputfieldWrapper
	 */
	public function getModuleConfigInputfields(array $data) {
		$this->handleResetPostRequest($data);
		return $this->buildConfigInputfields($data);
	}

	/**
	 * If the reset form was submitted (submit_reset=1, confirmReset=CONFIRMED),
	 * execute the reset. Otherwise do nothing.
	 *
	 * @param array $data
	 */
	private function handleResetPostRequest(array $data) {
		$input = $this->wire('input');
		if(!$input->requestMethod('POST')) return;
		if($input->post('submit_reset') === null) return;
		if($input->post('confirmReset') !== self::CONFIRM_TEXT) return;
		$this->executeReset($data);
	}

	/**
	 * Build the InputfieldWrapper for the module config screen.
	 *
	 * @param  array $data Saved config (may be missing keys on fresh install)
	 * @return InputfieldWrapper
	 */
	private function buildConfigInputfields(array $data) {
		$data = array_merge([
			'profilePath'     => '',
			'keepModules'     => [],
			'keepDirectories' => '',
			'chmodDir'        => '',
			'chmodFile'       => '',
		], $data);

		$modules    = $this->wire('modules');
		$inputfields = $this->wire(new InputfieldWrapper());

		// ── Profile ───────────────────────────────────────────────────────────
		/** @var InputfieldFieldset $fs */
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Profile Settings');
		$fs->icon  = 'database';
		$inputfields->add($fs);

		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->attr('name',  'profilePath');
		$f->attr('value', $data['profilePath']);
		$f->label       = $this->_('Custom Profile');
		$f->description = $this->_('Path to a profile directory containing install/ and templates/. Relative paths resolve from PW root. Leave empty for the bundled default (site-blank).');
		$f->notes       = $this->_('Example: site-rockfrontend');
		$f->collapsed   = Inputfield::collapsedBlank;
		$fs->add($f);

		// ── Modules to Keep ───────────────────────────────────────────────────
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Modules to Keep');
		$fs->icon  = 'plug';
		$inputfields->add($fs);

		/** @var InputfieldAsmSelect $f */
		$f = $modules->get('InputfieldAsmSelect');
		$f->attr('name', 'keepModules');
		$f->label       = $this->_('Select modules to preserve during reset');
		$f->description = $this->_('These site modules and their files survive the reset. ProcessWireReset is always preserved automatically.');
		$f->notes       = $this->_('Transitive site-module dependencies are automatically included.');

		$siteModulesPath = $this->wire('config')->paths->siteModules;
		foreach($modules as $module) {
			$className = $module->className();
			if($className === $this->className()) continue;
			$path = $modules->getModuleFile($module);
			if(!$path || strpos($path, $siteModulesPath) !== 0) continue;
			$info    = $modules->getModuleInfoVerbose($module);
			$version = $info['version'] ?? '?';
			$f->addOption($className, "$className (v$version)");
		}
		$f->attr('value', $data['keepModules']);
		$fs->add($f);

		// ── Directories to Keep ───────────────────────────────────────────────
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Directories to Keep');
		$fs->icon  = 'folder-o';
		$inputfields->add($fs);

		/** @var InputfieldTextarea $f */
		$f = $modules->get('InputfieldTextarea');
		$f->attr('name',  'keepDirectories');
		$f->attr('value', $data['keepDirectories']);
		$f->attr('rows',  5);
		$f->label       = $this->_('Additional directories to preserve (relative to site/)');
		$f->description = $this->_('One path per line. Lines starting with # are ignored.');
		$f->notes       = $this->_("Examples:\ntemplates/RockIcons\nassets/TracyDebugger\nassets/backups");
		$f->collapsed   = Inputfield::collapsedBlank;
		$fs->add($f);

		// ── File Permissions ──────────────────────────────────────────────────
		$fs = $modules->get('InputfieldFieldset');
		$fs->label     = $this->_('File Permissions');
		$fs->icon      = 'lock';
		$fs->collapsed = Inputfield::collapsedYes;
		$inputfields->add($fs);

		$pwChmodDir  = $this->wire('config')->chmodDir;
		$pwChmodFile = $this->wire('config')->chmodFile;

		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->attr('name',        'chmodDir');
		$f->attr('value',       $data['chmodDir']);
		$f->attr('placeholder', $pwChmodDir ?: '0755');
		$f->label       = $this->_('Directory permissions');
		$f->notes       = sprintf($this->_('Leave empty to use PW config: %s'), $pwChmodDir ?: '0755');
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldText');
		$f->attr('name',        'chmodFile');
		$f->attr('value',       $data['chmodFile']);
		$f->attr('placeholder', $pwChmodFile ?: '0644');
		$f->label       = $this->_('File permissions');
		$f->notes       = sprintf($this->_('Leave empty to use PW config: %s'), $pwChmodFile ?: '0644');
		$f->columnWidth = 50;
		$fs->add($f);

		// ── Execute Reset ─────────────────────────────────────────────────────
		/** @var InputfieldCheckbox $f */
		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name',  'enableReset');
		$f->attr('id',    'pwreset-enable');
		$f->attr('value', 1);
		$f->label       = $this->_('I want to reset this installation');
		$f->description = $this->_('A confirmation dialog will summarise all settings before executing.');
		$f->icon        = 'exclamation-triangle';
		$inputfields->add($f);

		/** @var InputfieldMarkup $f */
		$f = $modules->get('InputfieldMarkup');
		$f->attr('name', '_pwreset_modal');
		$f->skipLabel = Inputfield::skipLabelHeader;
		$f->wrapAttr('style', 'height:0;overflow:hidden;padding:0;margin:0;border:0;');
		$f->value = $this->buildResetModalMarkup($data);
		$inputfields->add($f);

		return $inputfields;
	}

	/**
	 * Build the UIkit confirmation modal markup + hidden fields + JS.
	 *
	 * The JS intercepts the form submit when the checkbox is checked,
	 * populates a summary table, and shows the modal. The "Execute Reset"
	 * button enables the two hidden fields and re-submits the form.
	 *
	 * @param  array $data Current config data
	 * @return string HTML
	 */
	private function buildResetModalMarkup(array $data) {
		$btnLabel    = $this->_('Execute Reset');
		$cancelLabel = $this->_('Cancel');
		$modalTitle  = $this->_('Confirm Installation Reset');
		$warningText = $this->_('This will permanently delete all content, fields, templates, uploaded files, and non-kept modules. The current superuser account will be preserved. This action cannot be undone!');
		$profileLabel = $this->_('Profile');
		$modulesLabel = $this->_('Modules to keep');
		$depsLabel    = $this->_('Auto-included dependencies');
		$dirsLabel    = $this->_('Directories to keep');
		$chmodLabel   = $this->_('Permissions');
		$noneLabel    = $this->_('None');
		$defaultLabel = $this->_('Bundled default (site-blank)');
		$confirmText  = self::CONFIRM_TEXT;

		// Pre-compute transitive dependencies so JS can show them in the modal
		$savedKeep = isset($data['keepModules']) ? (array) $data['keepModules'] : [];
		$expanded  = $this->expandKeepModules($savedKeep);
		$deps      = array_values(array_diff($expanded, $savedKeep));
		$depsJson  = json_encode($deps) ?: '[]';

		$html = <<<HTMLMODAL
<div id="pwreset-modal" uk-modal="bg-close:false; esc-close:false;">
	<div class="uk-modal-dialog" style="background:#fff;">
		<button class="uk-modal-close-default" type="button" uk-close></button>
		<div class="uk-modal-header">
			<h2 class="uk-modal-title">{$modalTitle}</h2>
		</div>
		<div class="uk-modal-body">
			<div class="uk-alert uk-alert-danger">
				{$warningText}
			</div>
			<table class="uk-table uk-table-divider uk-table-small uk-margin-top">
				<tr><th style="width:180px">{$profileLabel}</th><td id="pwreset-summary-profile"></td></tr>
				<tr><th>{$modulesLabel}</th><td id="pwreset-summary-modules"></td></tr>
				<tr id="pwreset-deps-row" style="display:none"><th>{$depsLabel}</th><td id="pwreset-summary-deps"></td></tr>
				<tr><th>{$dirsLabel}</th><td id="pwreset-summary-dirs"></td></tr>
				<tr style="border-bottom:none"><th>{$chmodLabel}</th><td id="pwreset-summary-chmod"></td></tr>
			</table>
		</div>
		<div class="uk-modal-footer uk-text-right">
			<button type="button" class="uk-button uk-button-secondary uk-modal-close">{$cancelLabel}</button>
			<button type="button" id="pwreset-confirm-btn" class="uk-button uk-button-default">
				<i class="fa fa-refresh"></i> {$btnLabel}
			</button>
		</div>
	</div>
</div>

<input type="hidden" name="submit_reset" id="pwreset-hidden-submit" value="" disabled>
<input type="hidden" name="confirmReset" id="pwreset-hidden-confirm" value="" disabled>


<script>
document.addEventListener('DOMContentLoaded', function() {
	var checkbox = document.getElementById('pwreset-enable');
	var confirmBtn = document.getElementById('pwreset-confirm-btn');
	var hiddenSubmit = document.getElementById('pwreset-hidden-submit');
	var hiddenConfirm = document.getElementById('pwreset-hidden-confirm');
	var modal = document.getElementById('pwreset-modal');
	var defaultProfile = '{$defaultLabel}';
	var noneText = '{$noneLabel}';
	var confirmText = '{$confirmText}';
	var confirmed = false;
	var precomputedDeps = {$depsJson};

	if (!checkbox || !confirmBtn || !modal) return;

	var form = checkbox.closest('form');
	if (!form) return;

	form.addEventListener('submit', function(e) {
		if (!checkbox.checked || confirmed) return;
		e.preventDefault();

		// Profile
		var profileInput = form.querySelector('[name=profilePath]');
		var profileVal = profileInput ? profileInput.value.trim() : '';
		document.getElementById('pwreset-summary-profile').textContent =
			profileVal ? profileVal : defaultProfile;

		// Modules (from AsmSelect)
		var moduleItems = [];
		var asmItems = form.querySelectorAll('#wrap_keepModules .asmListItem .asmListItemLabel');
		if (asmItems.length) {
			asmItems.forEach(function(el) { moduleItems.push(el.textContent.trim()); });
		} else {
			var moduleSelect = form.querySelector('[name="keepModules[]"]');
			if (moduleSelect) {
				for (var i = 0; i < moduleSelect.options.length; i++) {
					if (moduleSelect.options[i].selected) {
						moduleItems.push(moduleSelect.options[i].text || moduleSelect.options[i].value);
					}
				}
			}
		}
		var modulesEl = document.getElementById('pwreset-summary-modules');
		if (moduleItems.length) {
			modulesEl.innerHTML = '<ul class="uk-list uk-list-disc uk-margin-remove">' +
				moduleItems.map(function(m) { return '<li>' + m + '</li>'; }).join('') + '</ul>';
		} else {
			modulesEl.textContent = noneText;
		}

		// Auto-included dependencies
		var depsRow = document.getElementById('pwreset-deps-row');
		var depsEl = document.getElementById('pwreset-summary-deps');
		if (precomputedDeps.length > 0) {
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
		if (dirsVal) {
			var dirLines = dirsVal.split('\\n').filter(function(l) {
				return l.trim() && l.trim()[0] !== '#';
			});
			if (dirLines.length) {
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

	// Confirm: enable hidden fields, set flag, re-submit
	confirmBtn.addEventListener('click', function() {
		confirmed = true;
		hiddenSubmit.value = '1';
		hiddenSubmit.disabled = false;
		hiddenConfirm.value = confirmText;
		hiddenConfirm.disabled = false;
		UIkit.modal(modal).hide();
		form.submit();
	});
});
</script>
HTMLMODAL;

		return $html;
	}

	// =========================================================================
	// Reset Execution
	// =========================================================================

	/**
	 * Execute the full installation reset.
	 *
	 * Phase 0 — Pre-flight:  filesystem permission checks
	 * Phase 1 — Backup:      superuser, module data, custom tables, SQL paths
	 * Phase 2 — DB reset:    drop tables, import SQL, restore superuser + theme
	 * Phase 3 — Filesystem:  clean assets, templates, modules dirs
	 * Phase 4 — Redirect:    send Location header and exit
	 *
	 * MySQL DDL (DROP/CREATE TABLE) causes implicit commits and cannot be rolled
	 * back. Once Phase 2 starts, the DB is modified. On failure we throw so the
	 * user sees a clear error; the pending files are cleaned up to prevent
	 * partial-state module installs on the next request.
	 *
	 * @param  array $data Saved module config
	 * @throws WireException on DB or fatal filesystem failure
	 */
	protected function executeReset(array $data) {
		set_time_limit(300);

		$database = $this->wire('database');
		$config   = $this->wire('config');
		$input    = $this->wire('input');

		// POST values are authoritative for all reset settings.
		// The user may have changed the form without clicking Save first.
		$profilePath = (string) ($input->post('profilePath') ?? '');

		$rawKeep = $input->post('keepModules');
		if(is_array($rawKeep)) {
			$keepModules = array_values(array_filter(array_map('strval', $rawKeep)));
		} elseif(is_string($rawKeep) && $rawKeep !== '') {
			$keepModules = array_values(array_filter(array_map('trim', explode(',', $rawKeep))));
		} else {
			$keepModules = [];
		}

		$keepDirectories = (string) ($input->post('keepDirectories') ?? '');
		$chmodDir  = (string) ($input->post('chmodDir')  ?? '');
		$chmodFile = (string) ($input->post('chmodFile') ?? '');

		// Expand keepModules to include transitive site-module dependencies
		$keepModules = $this->expandKeepModules($keepModules);

		// Persist current settings to DB before backup so that backupModuleData
		// captures the values the user just submitted (they may not have clicked
		// the main Save button before triggering the reset).
		try {
			$database->prepare("UPDATE modules SET data = :data WHERE class = :class")->execute([
				':data'  => json_encode(compact('profilePath', 'keepModules', 'keepDirectories', 'chmodDir', 'chmodFile')),
				':class' => $this->className(),
			]);
		} catch(\PDOException $e) { /* non-fatal */ }

		// ── Phase 0: Pre-flight ───────────────────────────────────────────────
		$errors = $this->preflightCheck();
		if(!empty($errors)) {
			foreach($errors as $e) $this->error("Pre-flight: $e");
			throw new WireException('Reset aborted (filesystem check failed): ' . implode('; ', $errors));
		}

		// ── Phase 1: Backup ───────────────────────────────────────────────────
		$superuser = $this->backupSuperuser($database, $config);
		if(!$superuser) throw new WireException('Could not backup superuser — reset aborted.');

		$coreSQL    = $config->paths->wire . 'core/install.sql';
		$profileSQL = $this->resolveProfileInstallSql(['profilePath' => $profilePath]);

		if(!is_file($coreSQL))    throw new WireException("Core install.sql not found: $coreSQL");
		if(!$profileSQL || !is_file($profileSQL)) throw new WireException("Profile install.sql not found.");

		$keepModuleDirs      = $this->resolveKeepModuleDirs(['keepModules' => $keepModules]);
		$profileTemplatesPath = $this->resolveProfileTemplatesPath(['profilePath' => $profilePath]);

		// Topologically sorted install order (site + core deps)
		$installOrder = $this->resolveInstallOrder($keepModules);

		// Backup DB entries — always includes self + AdminThemeUikit
		$keptModuleData = $this->backupModuleData($database, $installOrder);

		// Backup non-canonical tables (module storage, logs, etc.)
		$customTables = [];
		if(!empty($keepModules)) {
			$canonicalTables = $this->getCanonicalTables($coreSQL, $profileSQL);
			$customTables    = $this->backupCustomTables($database, $canonicalTables);
		}

		// ── Phase 2: DB reset ─────────────────────────────────────────────────
		try {
			$tableCharset = $this->detectTableCharset($database, $config);

			$this->dropAllTables($database);

			// Instantiate headless installer and configure charset/engine
			$installer = new InstallerCore();
			$installer->chmodDir  = ltrim((string) ($chmodDir  ?: $config->chmodDir  ?: '0755'), '0') ?: '755';
			$installer->chmodFile = ltrim((string) ($chmodFile ?: $config->chmodFile ?: '0644'), '0') ?: '644';
			$installer->dbEngine  = $config->dbEngine ?: 'InnoDB';
			$installer->dbCharset = $tableCharset;

			// 2a. Import SQL (core + profile) using PW's own WireDatabaseBackup
			$installer->profileImportSQL($database, $coreSQL, $profileSQL, [
				'dbEngine'  => $installer->dbEngine,
				'dbCharset' => $installer->dbCharset,
			]);

			if($installer->numErrors) {
				throw new WireException('SQL import errors: ' . implode('; ', $installer->errors));
			}

			// 2b. Restore field_pass / field_email with original column schemas.
			// Re-importing install.sql resets column widths to bundled defaults;
			// modern PW hashes (Argon2/bcrypt) may need wider columns.
			if(!empty($superuser['pass_schema'])) {
				$database->exec("DROP TABLE IF EXISTS `field_pass`");
				$database->exec($superuser['pass_schema']);
				// Seed guest user row (pages_id=40) that core install.sql provides
				$database->exec("INSERT INTO field_pass (pages_id, data, salt) VALUES (40, '', '')");
			}
			if(!empty($superuser['email_schema'])) {
				$database->exec("DROP TABLE IF EXISTS `field_email`");
				$database->exec($superuser['email_schema']);
			}

			// 2c. Superuser page name
			$database->prepare("UPDATE pages SET name = :name WHERE id = :id")
				->execute([':name' => $superuser['name'], ':id' => (int) $superuser['id']]);

			// 2d. Admin root page name (preserves custom admin URL slug)
			$database->prepare("UPDATE pages SET name = :name WHERE id = :id")
				->execute([
					':name' => $superuser['admin_name'] ?: 'processwire',
					':id'   => (int) $config->adminRootPageID,
				]);

			// 2e. Password hash + salt
			if(!empty($superuser['pass_data'])) {
				$database->prepare(
					"INSERT INTO field_pass (pages_id, data, salt)
					 VALUES (:id, :data, :salt)
					 ON DUPLICATE KEY UPDATE data = VALUES(data), salt = VALUES(salt)"
				)->execute([
					':id'   => (int) $superuser['id'],
					':data' => $superuser['pass_data'],
					':salt' => $superuser['pass_salt'],
				]);
			}

			// 2f. Email
			if(!empty($superuser['email'])) {
				$database->prepare(
					"INSERT INTO field_email (pages_id, data)
					 VALUES (:id, :data)
					 ON DUPLICATE KEY UPDATE data = VALUES(data)"
				)->execute([':id' => (int) $superuser['id'], ':data' => $superuser['email']]);
			}

			// 2g. Admin theme per-user preference (best-effort — table may not exist yet)
			try {
				$database->prepare(
					"INSERT INTO field_admin_theme (pages_id, data)
					 VALUES (:id, :data)
					 ON DUPLICATE KEY UPDATE data = VALUES(data)"
				)->execute([
					':id'   => (int) $superuser['id'],
					':data' => $superuser['admin_theme'] ?: 'AdminThemeUikit',
				]);
			} catch(\Exception $e) {
				$this->wire('log')->save('processwirereset', 'admin_theme skipped: ' . $e->getMessage());
			}

			// 2h. AdminThemeUikit — fully restore before redirect (synchronous, not deferred).
			// The profile install.sql only seeds AdminThemeDefault, so Uikit is not in
			// the fresh modules table. INSERT if missing, UPDATE if present.
			// Backup data (flags + user settings) is merged in both branches so that
			// writePendingInstalls can safely exclude AdminThemeUikit — there is no
			// need for a deferred restore and deferring it would clobber useAsLogin=1.
			try {
				$stmt = $database->prepare("SELECT data, flags FROM modules WHERE class = 'AdminThemeUikit'");
				$stmt->execute();
				$row    = $stmt->fetch(\PDO::FETCH_ASSOC);
				$backup = $keptModuleData['AdminThemeUikit'] ?? null;

				// Prefer backed-up user settings; fall back to fresh-DB data
				if($backup && (string) $backup['data'] !== '') {
					$d = json_decode($backup['data'], true);
				} elseif($row && (string) $row['data'] !== '') {
					$d = json_decode($row['data'], true);
				} else {
					$d = [];
				}
				if(!is_array($d)) $d = [];
				$d['useAsLogin'] = 1;

				if($row !== false) {
					$flags = $backup ? (int) $backup['flags'] : (int) $row['flags'];
					$database->prepare(
						"UPDATE modules SET data = :data, flags = :flags WHERE class = 'AdminThemeUikit'"
					)->execute([':data' => json_encode($d), ':flags' => $flags]);
				} else {
					$flags = $backup ? (int) $backup['flags'] : 2;
					$database->prepare(
						"INSERT INTO modules (class, flags, data, created) VALUES (:class, :flags, :data, NOW())"
					)->execute([':class' => 'AdminThemeUikit', ':flags' => $flags, ':data' => json_encode($d)]);
				}
			} catch(\Exception $e) {
				$this->wire('log')->save('processwirereset', 'useAsLogin skipped: ' . $e->getMessage());
			}

			// 2i. Re-register ProcessWireReset in the fresh modules table
			$this->restoreSelfModule($database, $keptModuleData);

			// 2j. Write deferred-task files for the next request
			$this->writePendingCustomTables($customTables);
			$this->writePendingInstalls($keptModuleData, $installOrder);

		} catch(\Exception $e) {
			// Clean up half-written pending files so the next request doesn't
			// try to install modules into an inconsistent DB state.
			foreach([self::PENDING_FILE, self::PENDING_TABLES_FILE] as $f) {
				$p = __DIR__ . '/' . $f;
				if(file_exists($p)) @unlink($p);
			}
			$this->wire('log')->error('ProcessWireReset DB phase failed: ' . $e->getMessage());
			throw new WireException('Database reset failed: ' . $e->getMessage() .
				' — installation may be inconsistent.', 0, $e);
		}

		// ── Phase 3: Filesystem ───────────────────────────────────────────────
		$this->silenceAutoloadModules();

		$sitePath   = $config->paths->site;
		$this->fsFailures = [];
		$keepDirPaths     = $this->parseKeepDirectories($keepDirectories, $sitePath);

		$this->cleanAssetsDirectory($sitePath . 'assets/', $keepDirPaths);

		$this->emptyDirectory($sitePath . 'templates/', $keepDirPaths);
		if($profileTemplatesPath && is_dir($profileTemplatesPath)) {
			$this->copyDirectoryRecursive($profileTemplatesPath, $sitePath . 'templates/');
		}

		$this->cleanModulesDirectory($sitePath . 'modules/', $keepModuleDirs);

		if(file_put_contents(
			$sitePath . 'assets/installed.php',
			"<?php // The existence of this file prevents the installer from running."
		) === false) {
			$this->fsFailures[] = $sitePath . 'assets/installed.php (write failed)';
		}

		if(!empty($this->fsFailures)) {
			$count = count($this->fsFailures);
			$this->wire('log')->save('processwirereset',
				"Filesystem cleanup: $count failure(s):\n- " . implode("\n- ", array_slice($this->fsFailures, 0, 20))
			);
		}

		// ── Phase 4: Redirect ─────────────────────────────────────────────────
		$adminUrl = $this->safeRedirectUrl($config->urls->admin);
		header("Location: $adminUrl");
		header("Connection: close");
		header("Content-Length: 0");
		if(function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
		} else {
			while(ob_get_level() > 0) ob_end_clean();
			flush();
		}
		exit;
	}

	// =========================================================================
	// Backup helpers
	// =========================================================================

	/**
	 * Capture the current superuser's credentials and field schemas before reset.
	 *
	 * @return array|null Keys: id, name, admin_name, pass_data, pass_salt,
	 *                    email, admin_theme, pass_schema, email_schema
	 */
	protected function backupSuperuser($database, $config) {
		$userId  = (int) $config->superUserPageID;
		$adminId = (int) $config->adminRootPageID;
		try {
			$stmt = $database->prepare("SELECT id, name FROM pages WHERE id = :id");
			$stmt->execute([':id' => $userId]);
			$page = $stmt->fetch(\PDO::FETCH_ASSOC);
			if(!$page) return null;

			$stmt = $database->prepare("SELECT name FROM pages WHERE id = :id");
			$stmt->execute([':id' => $adminId]);
			$adminRow  = $stmt->fetch(\PDO::FETCH_ASSOC);
			$adminName = $adminRow ? $adminRow['name'] : 'processwire';

			$stmt = $database->prepare("SELECT data, salt FROM field_pass WHERE pages_id = :id");
			$stmt->execute([':id' => $userId]);
			$pass = $stmt->fetch(\PDO::FETCH_ASSOC);

			$stmt = $database->prepare("SELECT data FROM field_email WHERE pages_id = :id");
			$stmt->execute([':id' => $userId]);
			$email = $stmt->fetch(\PDO::FETCH_ASSOC);

			$adminTheme = '';
			try {
				$stmt = $database->prepare("SELECT data FROM field_admin_theme WHERE pages_id = :id");
				$stmt->execute([':id' => $userId]);
				$r = $stmt->fetch(\PDO::FETCH_ASSOC);
				$adminTheme = $r ? $r['data'] : '';
			} catch(\PDOException $e) { /* field_admin_theme may not exist */ }

			return [
				'id'          => $userId,
				'name'        => $page['name'],
				'admin_name'  => $adminName,
				'pass_data'   => $pass  ? rtrim($pass['data']) : '',
				'pass_salt'   => $pass  ? rtrim($pass['salt']) : '',
				'email'       => $email ? $email['data']       : '',
				'admin_theme' => $adminTheme,
				'pass_schema' => $this->getCreateTable($database, 'field_pass'),
				'email_schema'=> $this->getCreateTable($database, 'field_email'),
			];
		} catch(\PDOException $e) {
			return null;
		}
	}

	/**
	 * Backup modules table entries for the given class names.
	 *
	 * Always includes self and AdminThemeUikit regardless of $moduleNames so
	 * that (a) the module's own settings survive the reset and (b) the
	 * useAsLogin restore in Phase 2h has the correct flags/data to work with.
	 *
	 * @param  WireDatabasePDO $database
	 * @param  string[]        $moduleNames
	 * @return array  className => [class, flags, data]
	 */
	protected function backupModuleData($database, array $moduleNames) {
		$moduleNames = array_unique(array_merge([$this->className(), 'AdminThemeUikit'], $moduleNames));
		$result      = [];
		foreach($moduleNames as $className) {
			try {
				$stmt = $database->prepare("SELECT class, flags, data FROM modules WHERE class = :class");
				$stmt->execute([':class' => $className]);
				$row = $stmt->fetch(\PDO::FETCH_ASSOC);
				if($row) $result[$className] = $row;
			} catch(\PDOException $e) { /* ignore — module not yet installed */ }
		}
		return $result;
	}

	/**
	 * Backup non-canonical tables (those not defined in the install.sql files).
	 *
	 * For field_* tables: only backs up tables whose field is registered in the
	 * `fields` table. Orphaned field tables (from improperly uninstalled modules)
	 * are skipped.
	 *
	 * @param  WireDatabasePDO $database
	 * @param  array           $canonicalTables table_name => true
	 * @return array           table => ['create' => sql, 'rows' => [...]]
	 */
	protected function backupCustomTables($database, array $canonicalTables) {
		$backup     = [];
		$allTables  = $database->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);

		$registeredFields = [];
		try {
			$stmt = $database->query("SELECT name FROM fields");
			while($name = $stmt->fetchColumn()) {
				$registeredFields['field_' . $name] = true;
			}
		} catch(\PDOException $e) { /* fall through — back up everything */ }

		foreach($allTables as $table) {
			if(isset($canonicalTables[$table])) continue;
			if(strpos($table, 'field_') === 0 && !empty($registeredFields)) {
				if(!isset($registeredFields[$table])) continue;
			}
			$create = $this->getCreateTable($database, $table);
			if(empty($create)) continue;

			$safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
			try {
				$rows = $database->query("SELECT * FROM `$safe`")->fetchAll(\PDO::FETCH_ASSOC);
			} catch(\PDOException $e) { continue; }

			$backup[$safe] = ['create' => $create, 'rows' => $rows];
		}
		return $backup;
	}

	// =========================================================================
	// DB helpers
	// =========================================================================

	/**
	 * Detect the actual charset used by existing PW tables.
	 * Reads from information_schema — more reliable than $config->dbCharset.
	 */
	protected function detectTableCharset($database, $config) {
		try {
			$stmt = $database->prepare(
				"SELECT TABLE_COLLATION FROM information_schema.TABLES
				 WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'caches' LIMIT 1"
			);
			$stmt->execute([':db' => $config->dbName]);
			$collation = $stmt->fetchColumn();
			if($collation) {
				return explode('_', $collation, 2)[0]; // e.g. utf8mb3_general_ci → utf8mb3
			}
		} catch(\PDOException $e) { /* fall through */ }
		return $config->dbCharset ?: 'utf8';
	}

	/**
	 * Drop every table (and view) in the current database.
	 * Multi-pass to handle FK constraints. Throws if any tables remain.
	 *
	 * @throws WireException
	 */
	protected function dropAllTables($database) {
		$dbName = $this->wire('config')->dbName;
		$database->exec("SET FOREIGN_KEY_CHECKS = 0");

		for($pass = 0; $pass < 5; $pass++) {
			$stmt = $database->prepare(
				"SELECT TABLE_NAME FROM information_schema.TABLES
				 WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = 'BASE TABLE'"
			);
			$stmt->execute([':db' => $dbName]);
			$tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
			if(empty($tables)) break;
			foreach($tables as $t) {
				$safe = preg_replace('/[^a-zA-Z0-9_]/', '', $t);
				if($safe !== '') {
					try { $database->exec("DROP TABLE IF EXISTS `$safe`"); }
					catch(\PDOException $e) { /* retry next pass */ }
				}
			}
		}

		// Drop views
		try {
			$stmt = $database->prepare(
				"SELECT TABLE_NAME FROM information_schema.TABLES
				 WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = 'VIEW'"
			);
			$stmt->execute([':db' => $dbName]);
			foreach($stmt->fetchAll(\PDO::FETCH_COLUMN) as $v) {
				$safe = preg_replace('/[^a-zA-Z0-9_]/', '', $v);
				if($safe !== '') $database->exec("DROP VIEW IF EXISTS `$safe`");
			}
		} catch(\PDOException $e) { /* views are optional */ }

		$database->exec("SET FOREIGN_KEY_CHECKS = 1");

		$stmt = $database->prepare(
			"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db"
		);
		$stmt->execute([':db' => $dbName]);
		$remaining = (int) $stmt->fetchColumn();
		if($remaining > 0) {
			throw new WireException("dropAllTables: $remaining table(s) remain in '$dbName'");
		}
	}

	/** Return the CREATE TABLE statement for $table, or '' on failure. */
	protected function getCreateTable($database, $table) {
		$table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
		try {
			$row = $database->query("SHOW CREATE TABLE `$table`")->fetch(\PDO::FETCH_NUM);
			return $row ? $row[1] : '';
		} catch(\PDOException $e) { return ''; }
	}

	/**
	 * Parse all CREATE TABLE names from one or more SQL files.
	 * Returns a map of table_name => true (the "canonical" set after import).
	 */
	protected function getCanonicalTables(string ...$files) {
		$tables = [];
		foreach($files as $file) {
			if(!is_file($file) || !is_readable($file)) continue;
			$content = file_get_contents($file);
			if($content === false) continue;
			if(preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`/i', $content, $m)) {
				foreach($m[1] as $name) $tables[$name] = true;
			}
		}
		return $tables;
	}

	/**
	 * Restore backed-up custom tables after all module install() calls have run.
	 * Drops the fresh (empty) version and recreates it from the backup.
	 *
	 * @return string[] Restored table names
	 */
	protected function restoreCustomTables($database, array $customTables) {
		if(empty($customTables)) return [];
		$database->exec("SET FOREIGN_KEY_CHECKS = 0");
		$restored = [];
		foreach($customTables as $table => $data) {
			$database->exec("DROP TABLE IF EXISTS `$table`");
			$database->exec($data['create']);
			foreach($data['rows'] as $row) {
				if(empty($row)) continue;
				$cols   = array_keys($row);
				$colSql = '`' . implode('`,`', $cols) . '`';
				$params = [];
				$phs    = [];
				foreach($cols as $i => $col) {
					$ph        = ':v' . $i;
					$phs[]     = $ph;
					$params[$ph] = $row[$col];
				}
				try {
					$database->prepare("INSERT INTO `$table` ($colSql) VALUES (" . implode(',', $phs) . ")")
						->execute($params);
				} catch(\PDOException $e) {
					$this->wire('log')->error("ProcessWireReset: row insert failed for `$table`: " . $e->getMessage());
				}
			}
			$restored[] = $table;
		}
		$database->exec("SET FOREIGN_KEY_CHECKS = 1");
		return $restored;
	}

	/**
	 * Insert ProcessWireReset into the fresh modules table.
	 * Only self is registered immediately; other kept modules are deferred.
	 */
	protected function restoreSelfModule($database, array $keptModuleData) {
		$self = $this->className();
		$stmt = $database->prepare("SELECT id FROM modules WHERE class = :class");
		$stmt->execute([':class' => $self]);
		if($stmt->fetch()) return;

		$backup = $keptModuleData[$self] ?? ['class' => $self, 'flags' => 0, 'data' => ''];
		$database->prepare(
			"INSERT INTO modules (class, flags, data, created) VALUES (:class, :flags, :data, NOW())"
		)->execute([
			':class' => $self,
			':flags' => (int) $backup['flags'],
			':data'  => (string) $backup['data'],
		]);
	}

	/**
	 * Write the pending-installs JSON file.
	 * Lists every kept module (except self) in topological install order,
	 * with backed-up flags + data for those that had a pre-reset entry.
	 *
	 * @throws WireException if the file cannot be written
	 */
	protected function writePendingInstalls(array $keptModuleData, array $installOrder) {
		$self    = $this->className();
		// Core admin themes are fully restored synchronously in Phase 2h before the
		// redirect. Deferring them would clobber useAsLogin=1 via the data-restore loop.
		$syncClasses = ['AdminThemeUikit', 'AdminThemeDefault', 'AdminThemeReno'];
		$pending = [];
		foreach($installOrder as $className) {
			if($className === $self) continue;
			if(in_array($className, $syncClasses, true)) continue;
			$item = ['class' => $className];
			if(isset($keptModuleData[$className])) {
				$item['flags'] = (int)    $keptModuleData[$className]['flags'];
				$item['data']  = (string) $keptModuleData[$className]['data'];
			}
			$pending[] = $item;
		}

		$file = __DIR__ . '/' . self::PENDING_FILE;
		if(empty($pending)) {
			if(file_exists($file)) @unlink($file);
			return;
		}
		if(file_put_contents($file, json_encode($pending, JSON_PRETTY_PRINT)) === false) {
			throw new WireException("Cannot write pending-installs file: $file");
		}
	}

	/**
	 * Serialize and write the backed-up custom tables to a pending file.
	 * Processed in processPendingCustomTables() on the next request.
	 *
	 * @throws WireException if the file cannot be written
	 */
	protected function writePendingCustomTables(array $customTables) {
		$file = __DIR__ . '/' . self::PENDING_TABLES_FILE;
		if(empty($customTables)) {
			if(file_exists($file)) @unlink($file);
			return;
		}
		if(file_put_contents($file, serialize($customTables)) === false) {
			throw new WireException("Cannot write pending-tables file: $file");
		}
	}

	// =========================================================================
	// Module resolution helpers
	// =========================================================================

	/**
	 * Expand the keep-modules list to include transitive site-module dependencies.
	 * Core modules (wire/modules/) are excluded — they always survive a reset.
	 */
	protected function expandKeepModules(array $keepModules) {
		$modules         = $this->wire('modules');
		$siteModulesPath = $this->wire('config')->paths->siteModules;
		$self            = $this->className();
		$resolved        = [];
		$stack           = array_values(array_unique($keepModules));

		while(!empty($stack)) {
			$className = array_shift($stack);
			if(empty($className) || isset($resolved[$className]) || $className === $self) continue;

			$path = $modules->getModuleFile($className);
			if(!$path || strpos($path, $siteModulesPath) !== 0) continue;

			$resolved[$className] = true;
			$info = $modules->getModuleInfoVerbose($className);

			foreach((array) ($info['requires'] ?? []) as $req) {
				if(preg_match('/^([A-Za-z_][A-Za-z0-9_]*)/', $req, $m) && !isset($resolved[$m[1]])) {
					$stack[] = $m[1];
				}
			}
			foreach((array) ($info['installs'] ?? []) as $co) {
				if(!empty($co) && !isset($resolved[$co])) $stack[] = $co;
			}
		}
		return array_keys($resolved);
	}

	/**
	 * Topological sort (Kahn's algorithm) of ALL transitive dependencies —
	 * both site and core — so that each module can be installed individually
	 * in the correct order without relying on PW's nested auto-install.
	 *
	 * @return string[] Class names, dependencies first
	 */
	protected function resolveInstallOrder(array $keepModules) {
		$modules  = $this->wire('modules');
		$self     = $this->className();
		$requires = [];
		$queue    = array_values(array_unique($keepModules));

		while(!empty($queue)) {
			$className = array_shift($queue);
			if(empty($className) || isset($requires[$className])) continue;
			if($className === $self || $className === 'ProcessWire' || $className === 'PHP') continue;

			$path = $modules->getModuleFile($className);
			if(!$path) continue;

			$info = $modules->getModuleInfoVerbose($className);
			$deps = [];
			foreach((array) ($info['requires'] ?? []) as $req) {
				if(preg_match('/^([A-Za-z_][A-Za-z0-9_]*)/', $req, $m)) {
					$dep = $m[1];
					if($dep === 'ProcessWire' || $dep === 'PHP' || $dep === $self) continue;
					$deps[] = $dep;
					if(!isset($requires[$dep])) $queue[] = $dep;
				}
			}
			foreach((array) ($info['installs'] ?? []) as $co) {
				if(empty($co) || $co === $self) continue;
				if(!isset($requires[$co])) $queue[] = $co;
			}
			$requires[$className] = $deps;
		}

		// Kahn's algorithm
		$inDegree = array_fill_keys(array_keys($requires), 0);
		foreach($requires as $deps) {
			foreach($deps as $dep) {
				if(isset($inDegree[$dep])) $inDegree[$dep]++;
			}
		}

		$ready  = array_keys(array_filter($inDegree, fn($d) => $d === 0));
		$sorted = [];
		while(!empty($ready)) {
			$cls     = array_shift($ready);
			$sorted[] = $cls;
			foreach($requires as $other => $deps) {
				if(!in_array($cls, $deps, true)) continue;
				if(--$inDegree[$other] === 0) $ready[] = $other;
			}
		}

		// Append any remaining (cycles / unresolved)
		foreach(array_keys($requires) as $cls) {
			if(!in_array($cls, $sorted, true)) $sorted[] = $cls;
		}
		return $sorted;
	}

	/**
	 * Resolve the top-level directory/file names in site/modules/ to keep.
	 * Always includes self.
	 */
	protected function resolveKeepModuleDirs(array $data) {
		$modules         = $this->wire('modules');
		$siteModulesPath = $this->wire('config')->paths->siteModules;
		$dirs            = [$this->className()];

		foreach((array) ($data['keepModules'] ?? []) as $className) {
			$path = $modules->getModuleFile($className);
			if(!$path || strpos($path, $siteModulesPath) !== 0) continue;
			$rel  = substr($path, strlen($siteModulesPath));
			$top  = explode('/', $rel)[0];
			if(!empty($top)) $dirs[] = $top;
		}
		return array_unique($dirs);
	}

	// =========================================================================
	// Path resolution helpers
	// =========================================================================

	/**
	 * Resolve the path to the profile's install.sql.
	 * Validates against PW root to prevent directory traversal.
	 *
	 * @return string|null Absolute path or null on failure
	 */
	protected function resolveProfileInstallSql(array $data) {
		if(!empty($data['profilePath'])) {
			$base      = $this->resolveProfilePath($data['profilePath']);
			$candidate = rtrim($base, '/') . '/install/install.sql';
			$real      = realpath($candidate);
			if($real === false || !$this->isPathAllowed($real) || !is_file($real)) return null;
			return $real;
		}
		$bundled = __DIR__ . '/install/install.sql';
		return is_file($bundled) ? $bundled : null;
	}

	/**
	 * Resolve the path to the profile's templates directory.
	 *
	 * @return string|null Absolute path or null on failure
	 */
	protected function resolveProfileTemplatesPath(array $data) {
		if(!empty($data['profilePath'])) {
			$base      = $this->resolveProfilePath($data['profilePath']);
			$candidate = rtrim($base, '/') . '/templates/';
			$real      = realpath($candidate);
			if($real === false || !$this->isPathAllowed($real) || !is_dir($real)) return null;
			return $real;
		}
		$bundled = __DIR__ . '/install/site-templates/';
		return is_dir($bundled) ? $bundled : null;
	}

	/** Resolve a relative profile path against the PW root. */
	protected function resolveProfilePath($path) {
		$path = trim((string) $path);
		if($path !== '' && $path[0] === '/') return $path;
		return rtrim($this->wire('config')->paths->root, '/') . '/' . $path;
	}

	/** Guard against directory traversal: path must be inside PW root. */
	protected function isPathAllowed($absolutePath) {
		$root = realpath($this->wire('config')->paths->root);
		if($root === false) return false;
		return strpos($absolutePath, rtrim($root, '/') . '/') === 0 || $absolutePath === $root;
	}

	/** Sanitise a URL for the Location header — reject external hosts. */
	protected function safeRedirectUrl($url) {
		$url = str_replace(["\r", "\n"], '', (string) $url);
		if($url === '') return './';
		$parsed = parse_url($url);
		if($parsed === false) return './';
		if(!empty($parsed['host'])) {
			$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
			if($parsed['host'] !== $host) return './';
		}
		return $url;
	}

	// =========================================================================
	// Filesystem helpers
	// =========================================================================

	/**
	 * Pre-flight check: verify write+delete access on every path the reset
	 * will touch. Creates and immediately removes a temp file in each directory.
	 *
	 * @return string[] Human-readable error messages (empty = all OK)
	 */
	protected function preflightCheck() {
		$sitePath = $this->wire('config')->paths->site;
		$paths    = [
			$sitePath . 'assets/files/',
			$sitePath . 'assets/cache/',
			$sitePath . 'assets/logs/',
			$sitePath . 'assets/sessions/',
			$sitePath . 'assets/',
			$sitePath . 'templates/',
			$sitePath . 'modules/',
			__DIR__ . '/',
		];
		$errors = [];
		foreach($paths as $path) {
			if(!is_dir($path)) {
				$parent = dirname(rtrim($path, '/'));
				if(!is_dir($parent) || !is_writable($parent)) {
					$errors[] = "$path: missing and parent not writable";
				}
				continue;
			}
			if(!is_writable($path)) { $errors[] = "$path: not writable"; continue; }
			$tmp = rtrim($path, '/') . '/.pwreset-' . uniqid('', true);
			if(@file_put_contents($tmp, 'ok') === false) { $errors[] = "$path: cannot create files"; continue; }
			if(!@unlink($tmp)) $errors[] = "$path: cannot delete files ($tmp)";
		}
		return $errors;
	}

	/** Record a filesystem failure and log it. */
	protected function fsFailure($message) {
		$this->fsFailures[] = $message;
		try {
			$this->wire('log')->save('processwirereset', $message);
		} catch(\Exception $e) {
			$logDir = $this->wire('config')->paths->assets . 'logs/';
			if(is_dir($logDir)) @file_put_contents($logDir . 'processwirereset.txt',
				date('Y-m-d H:i:s') . " $message\n", FILE_APPEND);
		}
	}

	/**
	 * Empty a directory (delete all contents, keep the directory itself).
	 * Paths in $keepDirPaths are skipped.
	 */
	protected function emptyDirectory($dir, array $keepDirPaths = []) {
		if(!is_dir($dir)) {
			if(!mkdir($dir, $this->getChmodDir(), true) && !is_dir($dir)) {
				$this->fsFailure("Cannot create directory: $dir");
			}
			return;
		}
		try {
			foreach(new \DirectoryIterator($dir) as $item) {
				if($item->isDot()) continue;
				$path = $item->getPathname();
				if($this->isKeptPath($path, $keepDirPaths)) continue;
				if($item->isDir()) $this->removeDirectoryRecursive($path);
				else if(!unlink($path)) $this->fsFailure("Cannot delete: $path");
			}
		} catch(\Exception $e) {
			$this->fsFailure("Cannot iterate $dir: " . $e->getMessage());
		}
	}

	/** Recursively delete a directory and all its contents. */
	protected function removeDirectoryRecursive($dir) {
		if(!is_dir($dir)) return;
		try {
			$items = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach($items as $item) {
				$path = $item->getPathname();
				$ok   = $item->isDir() ? rmdir($path) : unlink($path);
				if(!$ok) $this->fsFailure("Cannot remove: $path");
			}
		} catch(\Exception $e) {
			$this->fsFailure("Cannot iterate $dir: " . $e->getMessage());
			return;
		}
		if(!rmdir($dir)) $this->fsFailure("Cannot remove directory: $dir");
	}

	/** Recursively copy $src into $dst. */
	protected function copyDirectoryRecursive($src, $dst) {
		if(!is_dir($dst) && !mkdir($dst, $this->getChmodDir(), true) && !is_dir($dst)) {
			$this->fsFailure("Cannot create: $dst");
			return;
		}
		foreach(new \DirectoryIterator($src) as $item) {
			if($item->isDot()) continue;
			$s = $item->getPathname();
			$d = $dst . '/' . $item->getFilename();
			if($item->isDir()) {
				$this->copyDirectoryRecursive($s, $d);
			} else {
				if(!copy($s, $d)) $this->fsFailure("Cannot copy $s → $d");
				else chmod($d, $this->getChmodFile());
			}
		}
	}

	/**
	 * Clean site/assets/:
	 *  - Standard subdirs (files, cache, logs, sessions) → emptied
	 *  - Other subdirs created by modules → removed
	 *  - installed.php, index.php, .htaccess → kept
	 */
	protected function cleanAssetsDirectory($assetsDir, array $keepDirPaths = []) {
		if(!is_dir($assetsDir)) return;
		$standardDirs  = ['files', 'cache', 'logs', 'sessions'];
		$preserveFiles = ['installed.php', 'index.php', '.htaccess'];

		foreach(new \DirectoryIterator($assetsDir) as $item) {
			if($item->isDot()) continue;
			$name = $item->getFilename();
			$path = $item->getPathname();
			if($this->isKeptPath($path, $keepDirPaths)) continue;

			if($item->isDir()) {
				if(in_array($name, $standardDirs, true)) $this->emptyDirectory($path, $keepDirPaths);
				else $this->removeDirectoryRecursive($path);
			} else {
				if(in_array($name, $preserveFiles, true)) continue;
				if(!unlink($path)) $this->fsFailure("Cannot delete: $path");
			}
		}
	}

	/**
	 * Clean site/modules/ — remove everything except kept module dirs/files.
	 * README files are always preserved.
	 */
	protected function cleanModulesDirectory($modulesDir, array $keepDirs) {
		if(!is_dir($modulesDir)) return;
		foreach(new \DirectoryIterator($modulesDir) as $item) {
			if($item->isDot()) continue;
			$name     = $item->getFilename();
			$nameLower = strtolower($name);
			if($nameLower === 'readme.txt' || $nameLower === 'readme.md') continue;
			$baseName = pathinfo($name, PATHINFO_FILENAME);
			if(in_array($name, $keepDirs, true) || in_array($baseName, $keepDirs, true)) continue;
			$path = $item->getPathname();
			if($item->isDir()) $this->removeDirectoryRecursive($path);
			else if(!unlink($path)) $this->fsFailure("Cannot delete: $path");
		}
	}

	/**
	 * Parse the keepDirectories textarea into a map of absolute paths.
	 * Lines beginning with # are treated as comments.
	 *
	 * @return array  absolute_path => true
	 */
	protected function parseKeepDirectories($textarea, $sitePath) {
		$result   = [];
		$sitePath = rtrim($sitePath, '/') . '/';
		foreach(explode("\n", (string) $textarea) as $line) {
			$line = trim($line);
			if($line === '' || $line[0] === '#') continue;
			$line = preg_replace('#^site/#', '', $line);
			$line = trim($line, '/');
			if($line === '') continue;
			$result[rtrim($sitePath . $line, '/')] = true;
		}
		return $result;
	}

	/**
	 * True if $path is inside, equal to, or a parent of any kept path.
	 * Prevents deletion of kept directories and their ancestors.
	 */
	protected function isKeptPath($path, array $keepDirPaths) {
		if(empty($keepDirPaths)) return false;
		$path = rtrim($path, '/');
		if(isset($keepDirPaths[$path])) return true;
		foreach($keepDirPaths as $kept => $v) {
			if(strpos($path, $kept . '/') === 0) return true;  // $path inside kept
			if(strpos($kept, $path . '/') === 0) return true;  // $path is parent of kept
		}
		return false;
	}

	/** Directory permission as integer (for mkdir/chmod). */
	protected function getChmodDir() {
		$val = $this->chmodDir;
		if(empty($val)) $val = $this->wire('config')->chmodDir;
		if(empty($val)) $val = '0755';
		return octdec(ltrim($val, '0') ?: '755');
	}

	/** File permission as integer (for chmod). */
	protected function getChmodFile() {
		$val = $this->chmodFile;
		if(empty($val)) $val = $this->wire('config')->chmodFile;
		if(empty($val)) $val = '0644';
		return octdec(ltrim($val, '0') ?: '644');
	}

	/**
	 * Disable debug-tool autoload modules and suppress error output.
	 * Prevents Tracy/similar shutdown handlers from fataling when their files
	 * are deleted during Phase 3.
	 */
	protected function silenceAutoloadModules() {
		error_reporting(0);
		ini_set('display_errors', '0');
		if(class_exists('\Tracy\Debugger', false)) {
			\Tracy\Debugger::$showBar = false;
			\Tracy\Debugger::enable(\Tracy\Debugger::ProductionMode);
		}
		while(ob_get_level() > 0) ob_end_clean();
	}
}
