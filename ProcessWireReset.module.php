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

	/** Persistent snapshot of non-canonical tables, kept across resets for opt-in restore */
	const SNAPSHOT_FILE = '.snapshot.bin';

	/** Recovery state file consumed by repair.php when a reset crashes */
	const RECOVERY_STATE_FILE = 'recovery.state.php';

	/** Recovery token lifetime in seconds (24 h) */
	const RECOVERY_TTL = 86400;

	/** Recovery token byte length (32 bytes → 64 hex chars) */
	const RECOVERY_TOKEN_BYTES = 32;

	/** Filename of the recovery endpoint copied to the document root on install */
	const RECOVERY_SCRIPT = 'pwreset_repair.php';

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
			// Autoload when there are deferred installs to run, OR when a
			// snapshot is available — the latter so we can prompt the
			// superuser to look at it after the reset.
			'autoload' => function() {
				return file_exists(__DIR__ . '/' . self::PENDING_FILE)
				    || file_exists(__DIR__ . '/' . self::SNAPSHOT_FILE);
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
		if(file_exists(__DIR__ . '/' . self::PENDING_FILE)) {
			$this->addHookAfter('ProcessWire::ready', $this, 'processPendingInstalls');
		}
		if(file_exists(__DIR__ . '/' . self::SNAPSHOT_FILE)) {
			$this->addHookAfter('Page::render', $this, 'injectSnapshotBanner');
		}
	}

	/**
	 * Inject a sticky banner at the top of every admin page while a
	 * snapshot is available. Notices are no good for this — PW collapses
	 * them by default, and a hook that fires on multiple sub-requests
	 * piles up identical messages that nobody reads.
	 */
	public function injectSnapshotBanner(HookEvent $event) {
		$page = $event->object;
		if(!$page || !$page->template || $page->template->name !== 'admin') return;

		$user = $this->wire('user');
		if(!$user || !$user->isSuperuser()) return;

		if(!file_exists(__DIR__ . '/' . self::SNAPSHOT_FILE)) return;

		// Banner only appears until the user has restored anything from
		// the snapshot at least once. After that it stays out of the way
		// even if there are still un-restored tables — the user might be
		// deliberately ignoring them. The full restore UI remains
		// accessible from Modules → Configure → ProcessWire Reset.
		$snapshot = $this->readCustomTablesSnapshot();
		if(!empty($snapshot['acknowledged'])) return;

		$out = (string) $event->return;
		// Only touch real HTML responses — skip AJAX / fragment / file
		// downloads where there is no <body> to inject into.
		if(stripos($out, '<body') === false) return;

		$h         = function($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
		// editUrl is unconditionally raw user-provided-style text → escape it.
		// Title / body / link label come from $this->_() which PW already
		// runs through its own entity layer. Double-escaping here turned
		// "Review & restore" into the visible text "Review &amp; restore".
		// Trust the pipeline for translated strings; escape only what we
		// know is unsafe.
		$editUrl   = $h($this->wire('config')->urls->admin . 'module/edit?name=' . $this->className());
		$title     = $this->_('Database snapshot available');
		$body      = $this->_('A reset created a backup of non-canonical tables.');
		$linkLabel = $this->_('Review & restore');

		// UIkit-flavoured (AdminThemeUikit). Falls back gracefully on other
		// themes — without UIkit the alert just renders as a plain block,
		// not pretty but still readable.
		// Reuse PW's own notice markup so spacing, font sizes and the
		// container alignment match the rest of the admin chrome.
		// pw-notices on the <ul>, NoticeMessage + uk-alert-warning on the
		// <li>, and the standard pw-container/uk-container-expand wrapper
		// inside. Only the flex layout for the right-aligned link is
		// inline; everything else inherits from PW's stylesheet.
		$banner = '<ul class="pw-notices" id="pwreset-snapshot-banner">'
			. '<li class="NoticeMessage uk-alert uk-alert-warning">'
			. '<div class="pw-container uk-container uk-container-expand"'
			. ' style="display:flex;align-items:center;gap:1em;flex-wrap:wrap;">'
			. '<i class="fa fa-database pw-nav-icon fa-fw"></i>'
			. '<strong>' . $title . '</strong>'
			. '<span style="flex:1">' . $body . '</span>'
			. '<a href="' . $editUrl . '">' . $linkLabel . '</a>'
			. '</div>'
			. '</li>'
			. '</ul>';

		$event->return = preg_replace('/(<body\b[^>]*>)/i', '$1' . $banner, $out, 1);
	}

	/**
	 * Copy the recovery endpoint into the PW document root on install.
	 *
	 * The bundled .htaccess in site/modules/ tries to whitelist
	 * repair.php, but many shared-hosting setups block .php execution
	 * under site/modules/ at the server level (mod_security, restrictive
	 * AllowOverride, etc.) and Apache returns 403 before our override
	 * gets to run. Putting the recovery script into the same directory
	 * as index.php sidesteps that block — that is where index.php and
	 * (during initial install) install.php live, so .php execution is
	 * always permitted.
	 */
	public function ___install() {
		$src = __DIR__ . '/repair.php';
		$dst = $this->wire('config')->paths->root . self::RECOVERY_SCRIPT;
		if(!is_file($src)) {
			throw new WireException('ProcessWireReset: repair.php missing from module directory.');
		}
		if(@copy($src, $dst) === false) {
			throw new WireException(
				'ProcessWireReset: cannot copy repair.php to ' . $dst . '. '
				. 'The PW document root must be writable for the webserver. '
				. 'Either grant write access for the duration of the install, '
				. 'or copy the file manually before triggering the first reset.'
			);
		}
		@chmod($dst, 0644);
	}

	/**
	 * Remove the recovery endpoint when the module is uninstalled.
	 */
	public function ___uninstall() {
		$dst = $this->wire('config')->paths->root . self::RECOVERY_SCRIPT;
		if(is_file($dst)) @unlink($dst);
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
		$workFile    = $pendingFile . '.processing';

		// Capture any output produced by PW core or by module install()
		// hooks during the deferred-install phase. PW 3.0.218+ for instance
		// can raise "Undefined array key N" warnings out of ModulesFlags
		// when a module entry exists in `modules` but not yet in the
		// modules-flags cache — harmless to the install but fatal to the
		// header()-based redirect at the end of this method.
		ob_start();

		// Atomically claim the pending file by renaming it out of the
		// autoload trigger path. Three benefits:
		//   1. Concurrent requests (favicon, AJAX) that woke up on the
		//      same pending file see no file after the rename and do not
		//      double-process.
		//   2. unlink() failure no longer leaves a request-loop trap —
		//      a failed rename aborts cleanly.
		//   3. A crash during processing leaves a .processing file behind,
		//      but the original path is gone, so the next request does NOT
		//      re-trigger the same crash. The stale .processing is cleaned
		//      up at the top of the next run.
		if(file_exists($workFile)) @unlink($workFile); // stale from a previous crash

		if(!file_exists($pendingFile)) {
			while(ob_get_level() > 0) ob_end_clean();
			return;
		}

		if(!@rename($pendingFile, $workFile)) {
			$this->wire('log')->error("ProcessWireReset: cannot rename $pendingFile to $workFile");
			while(ob_get_level() > 0) ob_end_clean();
			return;
		}

		$raw     = @file_get_contents($workFile);
		$pending = ($raw !== false) ? json_decode($raw, true) : null;
		@unlink($workFile);

		if(!is_array($pending) || empty($pending)) {
			while(ob_get_level() > 0) ob_end_clean();
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
				} catch(\Exception $e) {
					$msg = $e->getMessage();
					$stmt2 = $database->prepare("SELECT id FROM modules WHERE class = :c");
					$stmt2->execute([':c' => $className]);
					$row = $stmt2->fetch();
					$isDuplicate = stripos($msg, 'duplicate') !== false;
					if($row && $isDuplicate) {
						// PW finished installing this class while resolving a
						// dependency chain — DB row is consistent, treat as success.
						$installed[$className] = true;
						$progress = true;
						$modules->refresh();
					} else {
						if($row) {
							// install() inserted the modules row before throwing.
							// Roll it back so the modules table stays consistent
							// with modules_flags (PW 3.0.218+) and so that no
							// "Undefined array key N" warning leaks from
							// ModulesFlags on subsequent requests.
							$id = (int) $row['id'];
							$database->prepare("DELETE FROM modules WHERE id = :id")
								->execute([':id' => $id]);
							try {
								$database->prepare("DELETE FROM modules_flags WHERE modules_id = :id")
									->execute([':id' => $id]);
							} catch(\PDOException $e2) { /* table absent on older PW */ }
							$modules->refresh();
						}
						$nextRemaining[]    = $className;
						$failed[$className] = $msg;
					}
					continue;
				}
				$modules->refresh();
				$installed[$className] = true;
				$progress = true;
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

		// Reset is fully complete (install loop ran end-to-end). Drop the
		// recovery state — only here, never in early-return paths — so that
		// a crashed install (e.g. die() inside a kept module's install())
		// leaves recovery.state.php on disk for repair.php to consume.
		$this->cleanupRecoveryState();

		$this->wire('user', $savedUser);

		// Redirect back to admin once more so PW's SystemUpdater gets a clean
		// request to finish any remaining core DB upgrades (e.g. field_admin_theme).
		// Without this bounce, Update #10 runs on the same request as the login
		// page, potentially leaving the form partially unstyled.
		//
		// Only redirect from inside the admin: the hook fires on every request
		// while pending files exist, including frontend pages and AJAX/API calls
		// where a hidden Location: header would clobber the response.
		// Safe from infinite loops: pending file is already deleted above, so the
		// module will not autoload on the next request and no redirect fires again.
		$config = $this->wire('config');
		$adminUrl = $config->urls->admin;
		$requestUrl = $this->wire('input')->url();
		if(strpos((string) $requestUrl, (string) $adminUrl) !== 0) {
			while(ob_get_level() > 0) ob_end_clean();
			return;
		}

		$location = $this->safeRedirectUrl($adminUrl);

		// Discard any output captured during install/restore so the
		// header() calls below see a clean stream.
		while(ob_get_level() > 0) ob_end_clean();

		if(headers_sent()) {
			// Last-resort fallback: HTML redirect when something already
			// echoed before this method took over (e.g. a startup hook
			// firing earlier in the request than ob_start() above).
			$h = function($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
			echo '<!doctype html><meta http-equiv="refresh" content="0;url=' . $h($location) . '">'
				. '<script>location.replace(' . json_encode($location) . ');</script>';
			exit;
		}

		header("Location: $location");
		header("Connection: close");
		header("Content-Length: 0");
		if(function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
		} else {
			flush();
		}
		exit;
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
		$this->handleSnapshotPostRequest();
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
	 * Handle snapshot restore / delete actions submitted from the
	 * module config screen.
	 */
	private function handleSnapshotPostRequest() {
		$input = $this->wire('input');
		if(!$input->requestMethod('POST')) return;
		$action = (string) $input->post('_pwreset_snapshot_action');
		if($action === '') return;

		if($action === 'delete') {
			$this->deleteCustomTablesSnapshot();
			$this->message($this->_('Snapshot deleted.'));
			$userRef = ($this->wire('user') && $this->wire('user')->name)
				? $this->wire('user')->name
				: '?';
			$this->wire('log')->save('processwirereset', "Snapshot discarded by user $userRef");
			return;
		}

		if($action === 'restore') {
			$selected = (array) $input->post('_pwreset_snapshot_tables');
			$selected = array_filter(array_map(function($s) {
				return preg_replace('/[^a-zA-Z0-9_]/', '', (string) $s);
			}, $selected));
			if(empty($selected)) {
				$this->error($this->_('No tables selected for restore.'));
				return;
			}
			$payload = $this->readCustomTablesSnapshot();
			if(!$payload) {
				$this->error($this->_('No snapshot available to restore from.'));
				return;
			}
			$subset = array_intersect_key($payload['tables'], array_flip($selected));
			if(empty($subset)) {
				$this->error($this->_('Selected tables are not in the snapshot.'));
				return;
			}
			try {
				$restored = $this->restoreCustomTables($this->wire('database'), $subset);
				if(!empty($restored)) {
					$this->message(sprintf(
						$this->_n('Restored %d table: %s', 'Restored %d tables: %s', count($restored)),
						count($restored), implode(', ', $restored)
					));
					// Audit trail — one log line per table with row count
					// and the user who triggered the restore.
					$log     = $this->wire('log');
					$userRef = ($this->wire('user') && $this->wire('user')->name)
						? $this->wire('user')->name
						: '?';
					foreach($restored as $tableName) {
						$rows = isset($subset[$tableName]['rows']) ? count($subset[$tableName]['rows']) : 0;
						$log->save('processwirereset', sprintf(
							'Snapshot restore: table `%s` (%d rows) by user %s',
							$tableName, $rows, $userRef
						));
					}
					// Drop restored tables from the snapshot. Mark the
					// snapshot as acknowledged so the admin banner stops
					// reappearing — even if the user deliberately left some
					// tables behind. The snapshot itself stays available
					// from the module config screen for later cleanup.
					foreach($restored as $tableName) {
						unset($payload['tables'][$tableName]);
					}
					if(empty($payload['tables'])) {
						$this->deleteCustomTablesSnapshot();
					} else {
						$payload['acknowledged'] = true;
						$this->writeCustomTablesSnapshotPayload($payload);
					}
				}
			} catch(\Exception $e) {
				$this->error($this->_('Restore failed: ') . $e->getMessage());
				$this->wire('log')->error('Snapshot restore failed: ' . $e->getMessage());
			}
		}
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

		// ── Snapshot restore ──────────────────────────────────────────────────
		$snapshot = $this->readCustomTablesSnapshot();
		if($snapshot) {
			/** @var InputfieldMarkup $f */
			$f = $modules->get('InputfieldMarkup');
			$f->attr('name', '_pwreset_snapshot');
			$f->label = $this->_('Database snapshot');
			$f->icon  = 'database';
			$f->collapsed = Inputfield::collapsedNo;
			$f->value = $this->buildSnapshotMarkup($snapshot);
			$inputfields->add($f);
		}

		return $inputfields;
	}

	/**
	 * Render the snapshot section: table list with checkboxes, ownership
	 * heuristic per row, restore + delete buttons. The whole block is
	 * inside the surrounding PW config form, so the buttons just set a
	 * hidden action field and resubmit.
	 */
	private function buildSnapshotMarkup(array $snapshot) {
		$keepModules = (array) ($snapshot['keep_modules'] ?? []);
		$tables      = (array) ($snapshot['tables']       ?? []);
		$created     = (int)   ($snapshot['created_at']   ?? 0);

		$h = function($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };

		$dateLabel    = $created > 0 ? date('Y-m-d H:i', $created) : '?';
		$introText    = sprintf($this->_('Snapshot taken %s. Select tables to restore.'), $h($dateLabel));
		$tableHdr     = $this->_('Table');
		$ownerHdr     = $this->_('Likely owner (heuristic)');
		$rowsHdr      = $this->_('Rows');
		$restoreLabel = $this->_('Restore selected tables');
		$deleteLabel  = $this->_('Delete snapshot');
		$confirmDel   = $this->_('Delete the snapshot? This cannot be undone.');
		$disclaimer   = $this->_('Owner suggestions are heuristic — verify before restoring. Restoring a table whose module is not present can leave orphaned data.');

		$rows = '';
		ksort($tables);
		foreach($tables as $name => $tableData) {
			$rowCount = isset($tableData['rows']) ? count($tableData['rows']) : 0;
			$owner    = $this->guessTableOwner($name, $keepModules);
			$rows .= '<tr>'
				. '<td><label><input type="checkbox" name="_pwreset_snapshot_tables[]" value="' . $h($name) . '"> <code>' . $h($name) . '</code></label></td>'
				. '<td>' . $h($owner) . '</td>'
				. '<td style="text-align:right">' . (int) $rowCount . '</td>'
				. '</tr>';
		}

		$confirmDelJs = $h(json_encode($confirmDel));

		return <<<HTMLSNAPSHOT
<div class="pwreset-snapshot">
	<p>{$introText}</p>
	<table class="uk-table uk-table-divider uk-table-small">
		<thead><tr><th>{$tableHdr}</th><th>{$ownerHdr}</th><th style="text-align:right">{$rowsHdr}</th></tr></thead>
		<tbody>{$rows}</tbody>
	</table>
	<p><small>{$disclaimer}</small></p>
	<div class="uk-margin-top">
		<button type="submit" class="uk-button uk-button-primary"
			onclick="document.getElementById('pwreset-snapshot-action').value='restore';">
			{$restoreLabel}
		</button>
		<button type="submit" class="uk-button uk-button-danger"
			onclick="if(!confirm({$confirmDelJs})){return false;}document.getElementById('pwreset-snapshot-action').value='delete';">
			{$deleteLabel}
		</button>
	</div>
	<input type="hidden" id="pwreset-snapshot-action" name="_pwreset_snapshot_action" value="">
</div>
HTMLSNAPSHOT;
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

		$recoveryHeader  = $this->_('Recovery URL — copy now');
		$recoveryHelp    = $this->_('If the reset crashes, this is the only way back. Calling it performs a clean default install with your current admin credentials. The URL is shown once and is not recoverable.');
		$recoveryCopyBtn = $this->_('Copy');
		$recoveryCopied  = $this->_('Copied');
		$recoverySavedLb = $this->_('I saved the recovery URL');
		$recoveryUrlBase = $this->wire('config')->urls->root . self::RECOVERY_SCRIPT;

		// Pre-compute transitive dependencies so JS can show them in the modal
		$savedKeep = isset($data['keepModules']) ? (array) $data['keepModules'] : [];
		$expanded  = $this->expandKeepModules($savedKeep);
		$deps      = array_values(array_diff($expanded, $savedKeep));

		// Build config object handed to the external JS via window.PWResetModalConfig.
		$jsConfig = json_encode([
			'defaultProfile'  => $defaultLabel,
			'noneText'        => $noneLabel,
			'confirmText'     => self::CONFIRM_TEXT,
			'recoveryUrlBase' => $recoveryUrlBase,
			'recoveryCopied'  => $recoveryCopied,
			'precomputedDeps' => $deps,
		]) ?: '{}';

		$jsSrc = $this->wire('config')->urls->siteModules . $this->className() . '/assets/reset-modal.js?v=' . filemtime(__DIR__ . '/assets/reset-modal.js');

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
			<div class="uk-alert uk-alert-warning uk-margin-top">
				<h4 class="uk-margin-remove-bottom">{$recoveryHeader}</h4>
				<p class="uk-margin-small-top uk-margin-small-bottom">{$recoveryHelp}</p>
				<div class="uk-inline uk-width-1-1">
					<a class="uk-form-icon uk-form-icon-flip" href="#" id="pwreset-recovery-copy"
					   uk-icon="icon: copy" title="{$recoveryCopyBtn}"></a>
					<input type="text" class="uk-input" id="pwreset-recovery-url"
					       readonly onclick="this.select(); this.setSelectionRange(0, 99999);">
				</div>
				<label class="uk-margin-small-top uk-display-block">
					<input type="checkbox" class="uk-checkbox" id="pwreset-recovery-saved">
					{$recoverySavedLb}
				</label>
			</div>
		</div>
		<div class="uk-modal-footer uk-text-right">
			<button type="button" class="uk-button uk-button-secondary uk-modal-close">{$cancelLabel}</button>
			<button type="button" id="pwreset-confirm-btn" class="uk-button uk-button-default" disabled>
				<i class="fa fa-refresh"></i> {$btnLabel}
			</button>
		</div>
	</div>
</div>

<input type="hidden" name="submit_reset"   id="pwreset-hidden-submit"   value="" disabled>
<input type="hidden" name="confirmReset"   id="pwreset-hidden-confirm"  value="" disabled>
<input type="hidden" name="recoveryToken"  id="pwreset-hidden-token"    value="" disabled>

<script>window.PWResetModalConfig = {$jsConfig};</script>
<script src="{$jsSrc}"></script>
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

		// Recovery endpoint must be in place before we wipe anything,
		// otherwise the URL shown in the confirmation modal is dead.
		$recoveryScript = $config->paths->root . self::RECOVERY_SCRIPT;
		if(!is_file($recoveryScript)) {
			throw new WireException(
				'Reset aborted: recovery endpoint not found at ' . $recoveryScript . '. '
				. 'Re-install ProcessWireReset, or copy repair.php from the module '
				. 'directory to ' . $recoveryScript . ' manually before retrying.'
			);
		}

		// ── Phase 1: Backup ───────────────────────────────────────────────────
		$superuser = $this->backupSuperuser($database, $config);
		if(!$superuser) throw new WireException('Could not backup superuser — reset aborted.');

		$coreSQL    = $config->paths->wire . 'core/install.sql';
		$profileSQL = $this->resolveProfileInstallSql(['profilePath' => $profilePath]);

		if(!is_file($coreSQL))    throw new WireException("Core install.sql not found: $coreSQL");
		if(!$profileSQL || !is_file($profileSQL)) throw new WireException("Profile install.sql not found.");

		// Persist recovery state BEFORE the wipe begins. repair.php uses this
		// to perform a clean default install (always against bundled
		// site-blank, never the custom profile, and without keepModules) if
		// any subsequent phase crashes. Token comes from a hidden form field
		// generated by JS and shown in the confirmation modal.
		$recoveryToken = (string) $input->post('recoveryToken');
		if(!preg_match('/^[a-f0-9]{40,128}$/', $recoveryToken)) {
			throw new WireException('Reset aborted: missing or malformed recovery token.');
		}
		$bundledProfileSQL = __DIR__ . '/install/install.sql';
		if(!is_file($bundledProfileSQL)) $bundledProfileSQL = $profileSQL;
		$this->writeRecoveryState($recoveryToken, $superuser, $coreSQL, $bundledProfileSQL);

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

			// 2h. AdminThemeUikit — fully restore synchronously, not deferred,
			// so the redirect target (admin login) renders with a working theme.
			$this->restoreAdminThemeUikit($database, $keptModuleData);

			// 2i. Re-register ProcessWireReset in the fresh modules table
			$this->restoreSelfModule($database, $keptModuleData);

			// 2j. Write deferred-task files for the next request
			$this->writeCustomTablesSnapshot($customTables, $keepModules);
			$this->writePendingInstalls($keptModuleData, $installOrder);

			// 2k. Clear stale session throttle entries so the first post-reset
			//     login is not blocked by counters from the pre-reset session.
			try {
				$database->exec("DELETE FROM session_login_throttle");
			} catch(\Exception $e2) {
				// table may not exist in older PW installs; ignore
			}

		} catch(\Exception $e) {
			// Clean up half-written pending files (and any stale .processing
			// siblings from a previous crash) so the next request doesn't
			// try to install modules into an inconsistent DB state.
			$p = __DIR__ . '/' . self::PENDING_FILE;
			if(file_exists($p))                 @unlink($p);
			if(file_exists($p . '.processing')) @unlink($p . '.processing');
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
		// Close the PHP session before redirecting. Without this, the browser's
		// old session cookie arrives on the next request while the session lock
		// is still held, which—with debug=true—produces "headers already sent"
		// cascades and CSRF failures.
		if(session_status() === PHP_SESSION_ACTIVE) {
			session_write_close();
		}

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
		$modules    = $this->wire('modules');
		$wirePath   = $this->wire('config')->paths->wire;

		// Build name → fieldtype map and a "is core fieldtype" cache.
		// PW-core field tables (e.g. field_admin_theme, added by SystemUpdater
		// after install) survive any reset because the SystemUpdater recreates
		// them — backing them up just adds noise to the snapshot UI.
		$registeredFields  = [];
		$coreFieldtypeFor  = [];
		try {
			$stmt = $database->query("SELECT name, type FROM fields");
			while($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
				$tbl = 'field_' . $row['name'];
				$registeredFields[$tbl] = true;
				$type = (string) $row['type'];
				if(!isset($coreFieldtypeFor[$type])) {
					$file = $modules ? $modules->getModuleFile($type) : '';
					$coreFieldtypeFor[$type] = $file && strpos($file, $wirePath) === 0;
				}
				if($coreFieldtypeFor[$type]) {
					// Mark this specific field-table as "skip from backup".
					$registeredFields[$tbl] = 'core';
				}
			}
		} catch(\PDOException $e) { /* fall through — back up everything */ }

		foreach($allTables as $table) {
			if(isset($canonicalTables[$table])) continue;
			if(strpos($table, 'field_') === 0 && !empty($registeredFields)) {
				if(!isset($registeredFields[$table])) continue;          // orphaned
				if($registeredFields[$table] === 'core') continue;       // PW-core fieldtype
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
	 * Write a persistent snapshot of the captured custom tables.
	 *
	 * The snapshot is the only way to recover module-specific tables after
	 * a reset — no automatic restore happens. The user opts into restoring
	 * individual tables from the module config screen post-login.
	 *
	 * Format: PHP wrapper that 403s on direct HTTP access, plus a
	 * base64-encoded serialized payload between markers.
	 *
	 * Only the latest snapshot is kept; the previous one is overwritten
	 * verbatim. Empty backups remove an existing snapshot file.
	 */
	protected function writeCustomTablesSnapshot(array $customTables, array $keepModules) {
		if(empty($customTables)) {
			$this->deleteCustomTablesSnapshot();
			return;
		}
		$this->writeCustomTablesSnapshotPayload([
			'version'      => 1,
			'created_at'   => time(),
			'keep_modules' => array_values($keepModules),
			'tables'       => $customTables,
		]);
	}

	/**
	 * Write a pre-built snapshot payload to disk. Used both by the
	 * initial snapshot (executeReset) and by the restore handler
	 * after pruning already-restored tables out of the payload.
	 */
	protected function writeCustomTablesSnapshotPayload(array $payload) {
		$this->writeWrappedFile(
			__DIR__ . '/' . self::SNAPSHOT_FILE,
			base64_encode(serialize($payload)),
			'SNAPSHOT'
		);
	}

	/**
	 * Read the persistent snapshot file. Returns null if no snapshot,
	 * or the unserialized payload array on success.
	 */
	protected function readCustomTablesSnapshot() {
		$encoded = $this->readWrappedFile(
			__DIR__ . '/' . self::SNAPSHOT_FILE,
			'SNAPSHOT'
		);
		if($encoded === null) return null;
		$payload = @unserialize((string) base64_decode($encoded, true), ['allowed_classes' => false]);
		return is_array($payload) && !empty($payload['tables']) ? $payload : null;
	}

	/**
	 * Delete the persistent snapshot file.
	 */
	protected function deleteCustomTablesSnapshot() {
		$file = __DIR__ . '/' . self::SNAPSHOT_FILE;
		if(file_exists($file)) @unlink($file);
	}

	/**
	 * Best-effort guess at which kept module a snapshot table belongs to.
	 *
	 * Three sources, in descending reliability:
	 *   1. `field_<name>` tables → look up the field's fieldtype in the
	 *      live `fields` table. This is deterministic PW semantics.
	 *   2. Class-name prefix match against the keep-module list captured
	 *      in the snapshot (e.g. ProcessChangelog → process_changelog).
	 *   3. Otherwise: marked unknown.
	 *
	 * Returns a short human-readable string for display only — never used
	 * for automatic restore decisions.
	 */
	protected function guessTableOwner($tableName, array $keepModules) {
		// 1. Field tables — authoritative lookup.
		if(strpos($tableName, 'field_') === 0) {
			$fieldName = substr($tableName, 6);
			try {
				$stmt = $this->wire('database')->prepare("SELECT type FROM fields WHERE name = :n");
				$stmt->execute([':n' => $fieldName]);
				$type = $stmt->fetchColumn();
				if($type) {
					return sprintf($this->_('Field %s (Fieldtype: %s)'), $fieldName, $type);
				}
				return sprintf($this->_('Field %s (no longer registered)'), $fieldName);
			} catch(\PDOException $e) {
				return sprintf($this->_('Field %s'), $fieldName);
			}
		}

		// 2. Class-name prefix heuristic against keep modules.
		$lcTable = strtolower($tableName);
		foreach($keepModules as $modClass) {
			$candidates = [
				strtolower($modClass),
				strtolower(preg_replace('/(?<!^)([A-Z])/', '_$1', $modClass)),
			];
			foreach($candidates as $cand) {
				if($cand !== '' && strpos($lcTable, $cand) === 0) {
					return sprintf($this->_('Likely module: %s'), $modClass);
				}
			}
		}

		return $this->_('Unknown owner');
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
				// Treat 'installs' as a dependency edge: $className must come after $co
				// so PW's auto-install during install($className) finds $co already
				// installed and doesn't trigger a duplicate-entry DB error.
				$deps[] = $co;
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
	 * Restore AdminThemeUikit's modules-table row using the captured backup.
	 *
	 * The fresh profile install.sql only seeds AdminThemeDefault, so Uikit
	 * is missing in the post-import modules table. Re-installing it via the
	 * deferred install loop would happen too late — the redirect lands on
	 * the admin login page first, and a missing/inactive Uikit theme leaves
	 * that page unstyled. So we INSERT/UPDATE Uikit synchronously inside
	 * Phase 2, with merged settings (backed-up data + useAsLogin=1).
	 *
	 * Called from executeReset() Phase 2h.
	 */
	protected function restoreAdminThemeUikit($database, array $keptModuleData) {
		try {
			$stmt = $database->prepare("SELECT data, flags FROM modules WHERE class = 'AdminThemeUikit'");
			$stmt->execute();
			$row    = $stmt->fetch(\PDO::FETCH_ASSOC);
			$backup = $keptModuleData['AdminThemeUikit'] ?? null;

			// Prefer backed-up user settings; fall back to fresh-DB data.
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
			// Tracy renamed the production-mode constant in 2.10:
			//   ≥ 2.10  → \Tracy\Debugger::ProductionMode  (CamelCase)
			//   <  2.10 → \Tracy\Debugger::PRODUCTION       (uppercase)
			// Reading whichever is present, with a literal int fallback so
			// the silencing path itself never throws an Undefined-constant
			// error during the very phase that needs it most.
			if(defined('\Tracy\Debugger::ProductionMode')) {
				$mode = \Tracy\Debugger::ProductionMode;
			} elseif(defined('\Tracy\Debugger::PRODUCTION')) {
				$mode = \Tracy\Debugger::PRODUCTION;
			} else {
				$mode = 1; // Tracy's internal value for production
			}
			\Tracy\Debugger::enable($mode);
		}
		while(ob_get_level() > 0) ob_end_clean();
	}

	// =========================================================================
	// Recovery state — written before wipe, consumed by repair.php on crash
	// =========================================================================

	/**
	 * Write a base64-encoded payload between identical markers inside a
	 * PHP-wrapped file. The wrapper sends 403 on direct HTTP access, the
	 * markers make extraction trivial regardless of where the payload
	 * sits in the file.
	 *
	 * Used by writeRecoveryState() and writeCustomTablesSnapshotPayload().
	 */
	protected function writeWrappedFile($path, $encoded, $marker) {
		$body = "<?php http_response_code(403); exit; ?>\n"
			. "==$marker==\n"
			. $encoded . "\n"
			. "==$marker==\n";
		if(file_put_contents($path, $body, LOCK_EX) === false) {
			throw new WireException("Could not write state file: $path");
		}
		@chmod($path, 0600);
	}

	/**
	 * Read a wrapped file back out and return the encoded payload string,
	 * or null if the file is missing / malformed. Decoding (base64,
	 * json/serialize) is left to the caller.
	 */
	protected function readWrappedFile($path, $marker) {
		if(!is_file($path)) return null;
		$raw = (string) @file_get_contents($path);
		if($raw === '') return null;
		$parts = explode("==$marker==", $raw);
		if(count($parts) < 3) return null;
		return trim($parts[1]);
	}

	/**
	 * Write the recovery state file used by repair.php to perform a
	 * standard install with the original superuser credentials if the
	 * current reset crashes.
	 *
	 * The file is a PHP wrapper that exits 403 on direct HTTP access; the
	 * payload (base64-encoded JSON) sits between markers in the body so
	 * even a misconfigured webserver that ignores the .htaccess won't leak
	 * it via curl/wget.
	 */
	protected function writeRecoveryState($token, array $superuser, $coreSQL, $profileSQL) {
		$payload = [
			'version'     => 1,
			'token_hash'  => password_hash((string) $token, PASSWORD_BCRYPT),
			'created_at'  => time(),
			'expires_at'  => time() + self::RECOVERY_TTL,
			'superuser'   => $superuser,
			'core_sql'    => (string) $coreSQL,
			'profile_sql' => (string) $profileSQL,
		];
		$this->writeWrappedFile(
			__DIR__ . '/' . self::RECOVERY_STATE_FILE,
			base64_encode((string) json_encode($payload)),
			'RECOVERY-STATE'
		);
	}

	/**
	 * Delete the recovery state file once the deferred-install loop has
	 * finished without leaving a pending file behind. Called from
	 * processPendingInstalls() at the tail end.
	 */
	protected function cleanupRecoveryState() {
		$pending  = __DIR__ . '/' . self::PENDING_FILE;
		$recovery = __DIR__ . '/' . self::RECOVERY_STATE_FILE;
		if(file_exists($pending)) return;
		if(file_exists($recovery)) @unlink($recovery);
	}
}
