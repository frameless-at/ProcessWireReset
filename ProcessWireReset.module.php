<?php namespace ProcessWire;

/**
 * ProcessWire Reset Module
 *
 * Resets a ProcessWire installation to a clean profile state.
 * Preserves the current superuser account and selected site modules.
 *
 * @property string $profilePath Custom path to profile install directory (containing install.sql)
 * @property array $keepModules Module class names to preserve during reset
 */
class ProcessWireReset extends WireData implements Module, ConfigurableModule {

	const CONFIRM_TEXT = 'RESET';
	const PENDING_FILE = '.pending-installs.json';
	const PENDING_TABLES_FILE = '.pending-custom-tables.bin';

	/**
	 * Tracks filesystem operation failures during the reset phase.
	 * Populated by emptyDirectory(), removeDirectoryRecursive(),
	 * cleanModulesDirectory(), copyDirectoryRecursive(). Inspected at the
	 * end of Phase 3 to decide whether to throw a WireException.
	 *
	 * @var string[]
	 */
	protected $fsFailures = [];

	public static function getModuleInfo() {
		return [
			'title' => 'ProcessWire Reset',
			'version' => '0.1.0',
			'summary' => 'Resets a ProcessWire installation to a clean profile state while preserving the current superuser and selected modules.',
			'author' => 'frameless',
			'icon' => 'refresh',
			'singular' => true,
			// Conditional autoload: only load automatically when there are
			// pending tasks (module installs or custom-table restore) to
			// process after a reset.
			'autoload' => function() {
				return file_exists(__DIR__ . '/.pending-installs.json')
					|| file_exists(__DIR__ . '/.pending-custom-tables.bin');
			},
			'requires' => [
				'ProcessWire>=3.0.0',
			],
		];
	}

	public function __construct() {
		parent::__construct();
		$this->set('profilePath', '');
		$this->set('keepModules', []);
		$this->set('keepDirectories', '');
		$this->set('chmodDir', '');
		$this->set('chmodFile', '');
	}

	/**
	 * Initialize — processes pending tasks from a previous reset if present
	 *
	 * When a reset has kept modules, their re-install and custom-table
	 * restore is deferred to the next request (when PW has a clean state).
	 * The pending files trigger conditional autoload, and init() schedules
	 * the processing hook.
	 */
	public function init() {
		$pendingInstalls = file_exists(__DIR__ . '/' . self::PENDING_FILE);
		$pendingTables = file_exists(__DIR__ . '/' . self::PENDING_TABLES_FILE);
		if (!$pendingInstalls && !$pendingTables) return;

		// Process after PW is fully ready so installModule() has a clean API
		$this->addHookAfter('ProcessWire::ready', $this, 'processPendingInstalls');
	}

	/**
	 * Process deferred module installs from the pending file
	 *
	 * Runs each kept module's install() method (which creates admin pages,
	 * DB tables, custom fields, etc.) and then restores the backed-up
	 * config/flags to modules.data.
	 */
	public function processPendingInstalls() {
		$pendingFile = __DIR__ . '/' . self::PENDING_FILE;
		if (!file_exists($pendingFile)) return;

		$contents = file_get_contents($pendingFile);
		$pending = $contents !== false ? json_decode($contents, true) : null;

		// Delete file first to prevent infinite retries on error.
		// If deletion fails (rare), log but continue — the worst case is one
		// extra attempt on the next request, not an infinite loop.
		if (!unlink($pendingFile)) {
			$this->wire('log')->error("ProcessWireReset: Could not remove pending file: $pendingFile");
		}

		if (!is_array($pending) || empty($pending)) return;

		// Index pending by class name
		$byClass = [];
		foreach ($pending as $item) {
			if (!empty($item['class']) && $item['class'] !== $this->className()) {
				$byClass[$item['class']] = $item;
			}
		}
		if (empty($byClass)) return;

		$modules = $this->wire('modules');
		$database = $this->wire('database');
		$config = $this->wire('config');
		$log = $this->wire('log');

		// Temporarily elevate to superuser for install operations
		$savedUser = $this->wire('user');
		$superuser = $this->wire('users')->get($config->superUserPageID);
		if ($superuser && $superuser->id) {
			$this->wire('user', $superuser);
		}

		// Refresh modules cache so PW rediscovers module files
		$modules->refresh();

		// Multi-pass install: if a module fails because its dependency isn't
		// ready yet, retry in a later pass. Handles duplicate-entry errors as
		// success (PW may have auto-installed a dependency already).
		$remaining = array_keys($byClass);
		$installed = [];
		$failed = [];
		$maxPasses = count($remaining) + 2;

		for ($pass = 0; $pass < $maxPasses && !empty($remaining); $pass++) {
			$nextRemaining = [];
			$progress = false;

			foreach ($remaining as $className) {
				// Check DB directly (bypasses PW's possibly-stale cache)
				$stmt = $database->prepare("SELECT id FROM modules WHERE class = :class");
				$stmt->execute([':class' => $className]);
				if ($stmt->fetch()) {
					$installed[$className] = true;
					$progress = true;
					continue;
				}

				try {
					$modules->install($className);
					$modules->refresh();
					$installed[$className] = true;
					$progress = true;
				} catch (\Exception $e) {
					$msg = $e->getMessage();
					// Duplicate entry means the module was auto-installed as
					// a dependency during a sibling's install(). Treat as success.
					if (stripos($msg, 'Duplicate entry') !== false) {
						$installed[$className] = true;
						$progress = true;
						$modules->refresh();
					} else {
						// Retry in next pass — a dependency may get installed meanwhile
						$nextRemaining[] = $className;
						$failed[$className] = $msg;
					}
				}
			}

			$remaining = $nextRemaining;
			if (!$progress) break; // no progress, give up
		}

		// Restore config/flags for ALL modules from the pending list that
		// ended up in the DB (including deps auto-installed by PW).
		foreach ($byClass as $className => $item) {
			try {
				$stmt = $database->prepare(
					"UPDATE modules SET data = :data, flags = :flags WHERE class = :class"
				);
				$stmt->execute([
					':data' => isset($item['data']) ? $item['data'] : '',
					':flags' => isset($item['flags']) ? (int) $item['flags'] : 0,
					':class' => $className,
				]);
			} catch (\Exception $e) {
				$log->save('processwirereset', "Failed to restore config for $className: " . $e->getMessage());
			}
		}

		// Log summary
		if (!empty($installed)) {
			$log->save('processwirereset', "Re-installed kept modules: " . implode(', ', array_keys($installed)));
		}
		foreach ($remaining as $className) {
			$reason = isset($failed[$className]) ? $failed[$className] : 'unknown';
			$log->save('processwirereset', "Failed to install kept module $className: $reason");
		}

		// Restore custom tables AFTER all module installs completed. Each
		// install() may have created empty versions of the tables we backed
		// up — restoreCustomTables() drops the fresh versions and recreates
		// them from the backup, preserving user data (matrix types, login
		// throttle counters, log rows, etc.).
		$this->processPendingCustomTables($database);

		// Restore user context
		$this->wire('user', $savedUser);
	}

	/**
	 * Read the pending custom tables file and restore its contents
	 *
	 * Deletes the file after processing to prevent retries on the next
	 * request.
	 *
	 * @param WireDatabasePDO $database
	 */
	protected function processPendingCustomTables($database) {
		$pendingTablesFile = __DIR__ . '/' . self::PENDING_TABLES_FILE;
		if (!file_exists($pendingTablesFile)) return;

		$raw = file_get_contents($pendingTablesFile);

		// Delete file first to prevent retries on error
		if (!unlink($pendingTablesFile)) {
			$this->wire('log')->error("ProcessWireReset: Could not remove pending tables file: $pendingTablesFile");
		}

		if ($raw === false || $raw === '') return;

		// Serialized PHP — any non-object classes; allowed_classes = false
		$customTables = @unserialize($raw, ['allowed_classes' => false]);
		if (!is_array($customTables) || empty($customTables)) return;

		try {
			$restored = $this->restoreCustomTables($database, $customTables);
			if (!empty($restored)) {
				$this->wire('log')->save(
					'processwirereset',
					'Restored custom tables: ' . implode(', ', $restored)
				);
			}
		} catch (\Exception $e) {
			$this->wire('log')->error(
				'ProcessWireReset: Custom table restore failed: ' . $e->getMessage()
			);
		}
	}

	/**
	 * Module config screen entry point
	 *
	 * Delegates to two helpers: handleResetPostRequest() for POST handling
	 * (executes the reset if the user confirmed) and buildConfigInputfields()
	 * for the actual form construction.
	 *
	 * @param array $data Saved config data
	 * @return InputfieldWrapper
	 */
	public function getModuleConfigInputfields(array $data) {
		$this->handleResetPostRequest($data);
		return $this->buildConfigInputfields($data);
	}

	/**
	 * Detect and execute the reset action from POST data
	 *
	 * If the user clicked the "Reset Installation" button and supplied the
	 * confirmation text, executeReset() is called. executeReset() ends the
	 * request via exit(), so this method does not return on success.
	 *
	 * @param array $data Saved config data
	 */
	private function handleResetPostRequest(array $data) {
		$input = $this->wire('input');
		if (!$input->requestMethod('POST')) return;
		if ($input->post('submit_reset') === null) return;

		if ($input->post('confirmReset') === self::CONFIRM_TEXT) {
			$this->executeReset($data);
			// executeReset() exits — control never reaches here on success
			return;
		}

		$this->error($this->_('Confirmation text did not match. Reset was not executed.'));
	}

	/**
	 * Build the InputfieldWrapper for the module config screen
	 *
	 * @param array $data Saved config data (may be missing keys for fresh installs)
	 * @return InputfieldWrapper
	 */
	private function buildConfigInputfields(array $data) {
		// Merge with defaults — modules.data can be empty on fresh install
		$data = array_merge([
			'profilePath' => '',
			'keepModules' => [],
			'keepDirectories' => '',
		'chmodDir' => '',
		'chmodFile' => '',
		], $data);

		$modules = $this->wire('modules');
		$inputfields = $this->wire(new InputfieldWrapper());

		// ── Profile Settings ─────────────────────────────────────────────

		/** @var InputfieldFieldset $fs */
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Profile Settings');
		$fs->icon = 'database';
		$inputfields->add($fs);

		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->attr('name', 'profilePath');
		$f->attr('value', $data['profilePath']);
		$f->label = $this->_('Custom Profile Path');
		$f->description = $this->_('Path to a custom profile install directory containing install.sql. Leave empty to use the bundled default (site-blank). For templates, the module looks for a templates/ directory as a sibling of the install directory.');
		$f->notes = $this->_('Example: /var/www/profiles/site-blank/install/');
		$f->collapsed = Inputfield::collapsedBlank;
		$fs->add($f);

		// ── Modules to Keep ──────────────────────────────────────────────

		/** @var InputfieldFieldset $fs */
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Modules to Keep');
		$fs->icon = 'plug';
		$inputfields->add($fs);

		/** @var InputfieldAsmSelect $f */
		$f = $modules->get('InputfieldAsmSelect');
		$f->attr('name', 'keepModules');
		$f->label = $this->_('Select modules to preserve during reset');
		$f->description = $this->_('These site modules and their files will survive the reset. ProcessWireReset is always preserved automatically.');
		$f->notes = $this->_('Transitive site-module dependencies are automatically included — selecting a module also preserves any modules it requires.');

		$siteModulesPath = $this->wire('config')->paths->siteModules;
		foreach ($modules as $module) {
			$className = $module->className();
			if ($className === $this->className()) continue;
			$path = $modules->getModuleFile($module);
			if ($path && strpos($path, $siteModulesPath) === 0) {
				$info = $modules->getModuleInfoVerbose($module);
				$version = isset($info['version']) ? $info['version'] : '?';
				$f->addOption($className, "$className (v$version)");
			}
		}

		$f->attr('value', $data['keepModules']);
		$fs->add($f);

		// ── Directories to Keep ──────────────────────────────────────────

		/** @var InputfieldFieldset $fs */
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Directories to Keep');
		$fs->icon = 'folder-o';
		$inputfields->add($fs);

		/** @var InputfieldTextarea $f */
		$f = $modules->get('InputfieldTextarea');
		$f->attr('name', 'keepDirectories');
		$f->attr('value', $data['keepDirectories']);
		$f->attr('rows', 5);
		$f->label = $this->_('Additional directories to preserve during reset');
		$f->description = $this->_('One path per line, relative to the site/ directory. These directories and their contents will not be deleted.');
		$f->notes = $this->_("Examples:\ntemplates/RockIcons\nassets/TracyDebugger\nassets/backups");
		$f->collapsed = Inputfield::collapsedBlank;
		$fs->add($f);

		// ── File Permissions ─────────────────────────────────────────────

		/** @var InputfieldFieldset $fs */
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('File Permissions');
		$fs->icon = 'lock';
		$fs->collapsed = Inputfield::collapsedYes;
		$inputfields->add($fs);

		$pwChmodDir = $this->wire('config')->chmodDir;
		$pwChmodFile = $this->wire('config')->chmodFile;

		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->attr('name', 'chmodDir');
		$f->attr('value', $data['chmodDir']);
		$f->label = $this->_('Directory permissions');
		$f->description = $this->_('Octal permission for created directories.');
		$f->notes = sprintf($this->_('Leave empty to use PW config value: %s'), $pwChmodDir ?: '0755');
		$f->attr('placeholder', $pwChmodDir ?: '0755');
		$f->columnWidth = 50;
		$fs->add($f);

		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->attr('name', 'chmodFile');
		$f->attr('value', $data['chmodFile']);
		$f->label = $this->_('File permissions');
		$f->description = $this->_('Octal permission for created/copied files.');
		$f->notes = sprintf($this->_('Leave empty to use PW config value: %s'), $pwChmodFile ?: '0644');
		$f->attr('placeholder', $pwChmodFile ?: '0644');
		$f->columnWidth = 50;
		$fs->add($f);

		// ── Execute Reset ────────────────────────────────────────────────

		/** @var InputfieldFieldset $fs */
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Execute Reset');
		$fs->icon = 'exclamation-triangle';
		$fs->description = $this->_('WARNING: This will permanently delete all content, fields, templates, uploaded files, and non-kept modules. The current superuser account will be preserved. This action cannot be undone!');
		$inputfields->add($fs);

		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->attr('name', 'confirmReset');
		$f->attr('value', '');
		$f->label = $this->_('Confirmation');
		$f->description = sprintf($this->_('Type %s to confirm the reset.'), '"' . self::CONFIRM_TEXT . '"');
		$fs->add($f);

		/** @var InputfieldSubmit $f */
		$f = $modules->get('InputfieldSubmit');
		$f->attr('name', 'submit_reset');
		$f->attr('value', $this->_('Reset Installation'));
		$f->icon = 'refresh';
		$fs->add($f);

		return $inputfields;
	}

	// =====================================================================
	// Reset Execution
	// =====================================================================

	/**
	 * Execute the full installation reset
	 *
	 * Drops all tables, reimports from install.sql, restores the superuser,
	 * cleans the filesystem, and writes the deferred-install pending file.
	 * On success, sends a redirect header and exits the request.
	 *
	 * @param array $data Current module config data
	 * @throws WireException If the SQL import or DB reset phase fails
	 */
	protected function executeReset(array $data) {
		set_time_limit(300);

		// Merge with defaults — saved config may be missing keys
		$data = array_merge([
			'profilePath' => '',
			'keepModules' => [],
			'keepDirectories' => '',
			'chmodDir' => '',
			'chmodFile' => '',
		], $data);

		$database = $this->wire('database');
		$config = $this->wire('config');
		$input = $this->wire('input');

		// executeReset() only runs from a POST submission of our reset form,
		// so POST values are authoritative — NOT the saved config. Using the
		// saved config as fallback is wrong: the user may have just deselected
		// modules in the AsmSelect without clicking the main Submit to persist,
		// and the form could still legitimately mean "clean reset, keep nothing".
		$data['profilePath'] = (string) ($input->post('profilePath') ?? '');

		$rawKeepModules = $input->post('keepModules');
		if (is_array($rawKeepModules)) {
			// AsmSelect posts as an array — filter out empty entries
			$data['keepModules'] = array_values(array_filter(
				array_map('strval', $rawKeepModules),
				function($v) { return $v !== ''; }
			));
		} elseif (is_string($rawKeepModules) && $rawKeepModules !== '') {
			$data['keepModules'] = array_values(array_filter(
				array_map('trim', explode(',', $rawKeepModules))
			));
		} else {
			// null, empty string, or unexpected type → nothing selected
			$data['keepModules'] = [];
		}

		// Read keepDirectories from POST (textarea, one path per line)
		$rawKeepDirs = $input->post('keepDirectories');
		$data['keepDirectories'] = ($rawKeepDirs !== null) ? (string) $rawKeepDirs : '';

		// Read chmod fields from POST
		$rawChmodDir = $input->post('chmodDir');
		$data['chmodDir'] = ($rawChmodDir !== null) ? (string) $rawChmodDir : '';
		$rawChmodFile = $input->post('chmodFile');
		$data['chmodFile'] = ($rawChmodFile !== null) ? (string) $rawChmodFile : '';

		// Automatically include all transitive site-module dependencies so
		// preserving Module A also preserves the modules it requires.
		$data['keepModules'] = $this->expandKeepModules((array) $data['keepModules']);

		// Persist current config to DB BEFORE backup. When the user clicks
		// "Reset Installation" without first clicking the main Submit button,
		// PW's config save flow never runs (executeReset exits before it).
		// Without this, backupModuleData reads the OLD saved config and the
		// module's settings are lost after the reset.
		try {
			$selfConfig = json_encode([
				'profilePath' => $data['profilePath'],
				'keepModules' => $data['keepModules'],
				'keepDirectories' => $data['keepDirectories'],
				'chmodDir' => $data['chmodDir'],
				'chmodFile' => $data['chmodFile'],
			]);
			$database->prepare("UPDATE modules SET data = :data WHERE class = :class")
				->execute([':data' => $selfConfig, ':class' => $this->className()]);
		} catch (\PDOException $e) {
			// Non-fatal — worst case the settings reset to defaults
		}

		// ── Phase 0: Pre-flight checks ───────────────────────────────────
		//
		// Verify we can actually write to and delete from every critical
		// directory BEFORE touching the database. If any check fails, abort
		// cleanly — nothing has been modified yet.
		$preflightErrors = $this->preflightCheck();
		if (!empty($preflightErrors)) {
			foreach ($preflightErrors as $err) {
				$this->error("Pre-flight check failed: $err");
			}
			throw new WireException(
				'Reset aborted: filesystem permission check failed. Cannot proceed. Issues: '
				. implode('; ', $preflightErrors)
			);
		}

		// ── Phase 1: Gather data before destroying anything ──────────────

		$superuser = $this->backupSuperuser($database, $config);
		if (!$superuser) {
			$this->error($this->_('Could not backup superuser data. Reset aborted.'));
			return;
		}

		$coreInstallSql = $config->paths->wire . 'core/install.sql';
		$profileInstallSql = $this->resolveProfileInstallSql($data);

		if (!is_file($coreInstallSql)) {
			$this->error($this->_('Core install.sql not found. Reset aborted.'));
			return;
		}
		if (!$profileInstallSql || !is_file($profileInstallSql)) {
			$this->error($this->_('Profile install.sql not found. Reset aborted.'));
			return;
		}

		$keepModuleDirs = $this->resolveKeepModuleDirs($data);
		$profileTemplatesPath = $this->resolveProfileTemplatesPath($data);

		// Compute the topologically sorted install order including ALL
		// transitive dependencies (site + core modules). Used to explicitly
		// install each module after the reset instead of relying on PW's
		// nested auto-install from within install().
		$installOrder = $this->resolveInstallOrder((array) $data['keepModules']);

		// Back up DB config/flags for every module in the install order
		// (plus self). This captures user-set configuration for core-module
		// dependencies too — e.g. CKEditor plugins, SessionHandlerDB settings.
		$keptModuleData = $this->backupModuleData($database, $installOrder);

		// Back up custom tables (any table not defined in install.sql) —
		// these typically belong to modules that create their own storage
		// (e.g. login throttle, logs, custom module caches).
		$customTables = [];
		if (!empty($data['keepModules'])) {
			$canonicalTables = $this->getCanonicalTables($coreInstallSql, $profileInstallSql);
			$customTables = $this->backupCustomTables($database, $canonicalTables);
		}

		// ── Phase 2: Database reset ──────────────────────────────────────
		//
		// Note: MySQL/MariaDB DDL statements (DROP TABLE, CREATE TABLE) cause
		// implicit commits and cannot be rolled back, so wrapping this phase
		// in a transaction would be misleading. Instead, we use try/catch to
		// surface errors clearly and clean up any partial state on failure.
		try {
			$this->dropAllTables($database);
			$this->importSqlMerged($database, $coreInstallSql, $profileInstallSql, $config);
			$this->restoreSuperuser($database, $superuser, $config);
			// Only re-register ProcessWireReset itself directly so PW can autoload
			// it on the next request. Other kept modules are deferred — their
			// install() is called in processPendingInstalls() on the next request,
			// so admin pages, custom fields, DB tables etc. are recreated.
			$this->restoreSelfModule($database, $keptModuleData);
			// Custom tables are NOT restored here. If we recreated them now,
			// the next request's processPendingInstalls() would call each
			// module's install() which in turn tries to CREATE TABLE for its
			// own custom tables — and those CREATEs would fail because the
			// tables already exist. Instead we defer the restore: serialize
			// the backup to a pending file, and restoreCustomTables() runs
			// in processPendingInstalls() AFTER all module installs have
			// created their (empty) fresh tables.
			$this->writePendingCustomTables($customTables);
			$this->writePendingInstalls($keptModuleData, $installOrder);
		} catch (\Exception $e) {
			// Remove any half-written pending files so the next request doesn't
			// try to install modules into an inconsistent DB state.
			$pendingFile = __DIR__ . '/' . self::PENDING_FILE;
			if (file_exists($pendingFile)) unlink($pendingFile);
			$pendingTablesFile = __DIR__ . '/' . self::PENDING_TABLES_FILE;
			if (file_exists($pendingTablesFile)) unlink($pendingTablesFile);

			$this->wire('log')->error('ProcessWireReset: DB reset phase failed: ' . $e->getMessage());
			throw new WireException(
				'Database reset failed: ' . $e->getMessage() .
				' — installation may be in an inconsistent state. Use repair.php to recover.',
				0,
				$e
			);
		}

		// ── Phase 3: Filesystem reset ────────────────────────────────────

		// Disable autoloaded debug tools and suppress errors from their
		// shutdown handlers — module files are about to be deleted.
		$this->silenceAutoloadModules();

		$sitePath = $config->paths->site;

		// Reset failure counter — tracks files/dirs that could not be deleted.
		// Any non-zero count after the phase triggers an exception in the
		// redirect step so the user sees a clear failure message.
		$this->fsFailures = [];

		// Parse the keepDirectories textarea into a set of absolute paths
		// that the cleanup helpers will skip.
		$keepDirPaths = $this->parseKeepDirectories($data['keepDirectories'], $sitePath);

		// Clean site/assets/ — empties the four standard dirs (files, cache,
		// logs, sessions) and removes any other entries that modules may
		// have created (e.g. assets/TracyDebugger/, assets/backups/,
		// assets/FormBuilder/). Keeps installed.php, index.php, and any
		// paths listed in keepDirectories.
		$this->cleanAssetsDirectory($sitePath . 'assets/', $keepDirPaths);

		// Reset templates to profile state — preserving any keepDirectories
		// entries that live under templates/
		$this->emptyDirectory($sitePath . 'templates/', $keepDirPaths);
		if ($profileTemplatesPath && is_dir($profileTemplatesPath)) {
			$this->copyDirectoryRecursive($profileTemplatesPath, $sitePath . 'templates/');
		}

		// Clean site/modules/ — keep only self + selected
		$this->cleanModulesDirectory($sitePath . 'modules/', $keepModuleDirs);

		// Ensure installed.php exists (prevents PW installer from running)
		if (file_put_contents(
			$sitePath . 'assets/installed.php',
			"<?php // The existence of this file prevents the installer from running."
		) === false) {
			$this->fsFailures[] = $sitePath . 'assets/installed.php (could not write)';
		}

		// Log any filesystem failures but do NOT throw — the DB reset has
		// already completed and there is no way to roll it back. Throwing
		// here would block the redirect and leave the user stuck on an
		// error page with a half-cleaned installation. Instead, log the
		// details and continue to the login page. The user can check the
		// log and manually remove leftover files.
		if (!empty($this->fsFailures)) {
			$count = count($this->fsFailures);
			$this->wire('log')->save('processwirereset',
				"Filesystem cleanup: $count operation(s) failed. "
				. "These files/directories could not be removed (permission issues). "
				. "First 20:\n- " . implode("\n- ", array_slice($this->fsFailures, 0, 20))
			);
		}

		// ── Phase 4: Redirect to login ───────────────────────────────────

		$adminUrl = $this->safeRedirectUrl($config->urls->admin);

		// Send redirect and close connection before shutdown handlers fire
		header("Location: $adminUrl");
		header("Connection: close");
		header("Content-Length: 0");
		if (function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
		} else {
			while (ob_get_level() > 0) ob_end_clean();
			flush();
		}
		exit;
	}

	// =====================================================================
	// Database Helpers
	// =====================================================================

	/**
	 * Backup the current superuser's credentials
	 *
	 * @param WireDatabasePDO $database
	 * @param Config $config
	 * @return array|null
	 */
	protected function backupSuperuser($database, $config) {
		$userId = (int) $config->superUserPageID;

		try {
			$stmt = $database->prepare("SELECT id, name FROM pages WHERE id = :id");
			$stmt->execute([':id' => $userId]);
			$page = $stmt->fetch(\PDO::FETCH_ASSOC);
			if (!$page) return null;

			$stmt = $database->prepare("SELECT data, salt FROM field_pass WHERE pages_id = :id");
			$stmt->execute([':id' => $userId]);
			$pass = $stmt->fetch(\PDO::FETCH_ASSOC);

			$stmt = $database->prepare("SELECT data FROM field_email WHERE pages_id = :id");
			$stmt->execute([':id' => $userId]);
			$email = $stmt->fetch(\PDO::FETCH_ASSOC);

			// Capture current schemas for field_pass and field_email to
			// preserve any column size upgrades applied by SystemUpdater
			// or third-party modules (modern PW hashes can exceed char(40))
			$passSchema = $this->getCreateTable($database, 'field_pass');
			$emailSchema = $this->getCreateTable($database, 'field_email');

			return [
				'id' => $userId,
				'name' => $page['name'],
				'pass_data' => $pass ? rtrim($pass['data']) : '',
				'pass_salt' => $pass ? rtrim($pass['salt']) : '',
				'email' => $email ? $email['data'] : '',
				'pass_schema' => $passSchema,
				'email_schema' => $emailSchema,
			];
		} catch (\PDOException $e) {
			return null;
		}
	}

	/**
	 * Backup module DB entries (class, flags, data) for given module names
	 *
	 * Backs up the modules.data column for every module in the install list
	 * (including self). Modules not currently in the modules table (e.g. core
	 * modules that get installed as dependencies later) are skipped — they'll
	 * be installed fresh with default config.
	 *
	 * @param WireDatabasePDO $database
	 * @param array $moduleNames List of module class names to back up
	 * @return array Map of className => ['class' => ..., 'flags' => ..., 'data' => ...]
	 */
	protected function backupModuleData($database, array $moduleNames) {
		$moduleNames = array_unique(array_merge([$this->className()], $moduleNames));
		$result = [];

		foreach ($moduleNames as $className) {
			try {
				$stmt = $database->prepare("SELECT class, flags, data FROM modules WHERE class = :class");
				$stmt->execute([':class' => $className]);
				$row = $stmt->fetch(\PDO::FETCH_ASSOC);
				// Only back up modules that are actually installed. Unknown
				// modules (e.g. core deps added by resolveInstallOrder but
				// not yet installed) are skipped and will use defaults.
				if ($row) $result[$className] = $row;
			} catch (\PDOException $e) {
				// Ignore errors — module not in DB means no config to back up
			}
		}

		return $result;
	}

	/**
	 * Get the CREATE TABLE statement for a given table
	 *
	 * @param WireDatabasePDO $database
	 * @param string $table Table name (must be validated)
	 * @return string CREATE TABLE statement or empty string on failure
	 */
	protected function getCreateTable($database, $table) {
		$table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
		try {
			$row = $database->query("SHOW CREATE TABLE `$table`")->fetch(\PDO::FETCH_NUM);
			return $row ? $row[1] : '';
		} catch (\PDOException $e) {
			return '';
		}
	}

	/**
	 * Parse install.sql files and return all CREATE TABLE names
	 *
	 * These are the "canonical" tables that restoreMerge will recreate.
	 * Any table in the live DB that is NOT in this list is considered
	 * custom (typically belonging to modules that create their own tables).
	 *
	 * @param string ...$files One or more SQL file paths
	 * @return array Map of table_name => true
	 */
	protected function getCanonicalTables(...$files) {
		$tables = [];
		foreach ($files as $file) {
			if (!is_file($file) || !is_readable($file)) continue;
			$content = file_get_contents($file);
			if ($content === false) continue;
			if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`/i', $content, $matches)) {
				foreach ($matches[1] as $tableName) {
					$tables[$tableName] = true;
				}
			}
		}
		return $tables;
	}

	/**
	 * Back up non-canonical tables that are still legitimate
	 *
	 * Backs up custom tables (not in install.sql) that should survive the
	 * reset. For field_* tables, cross-references the `fields` table: only
	 * fields with a registered entry are backed up. Orphaned field tables
	 * (from modules that were uninstalled but didn't clean up their tables)
	 * are skipped. Non-field tables (fieldtype_options, pages_meta, etc.)
	 * are always backed up.
	 *
	 * @param WireDatabasePDO $database
	 * @param array $canonicalTables Map of table_name => true from getCanonicalTables
	 * @return array Array of [table => ['create' => ..., 'rows' => [...]]]
	 */
	protected function backupCustomTables($database, array $canonicalTables) {
		$backup = [];
		$allTables = $database->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);

		// Build a set of legitimate field_* table names by querying the
		// fields table BEFORE the reset. Every registered field has a
		// corresponding field_<name> table. Unregistered field tables are
		// orphans from improperly uninstalled modules.
		$registeredFieldTables = [];
		try {
			$stmt = $database->query("SELECT name FROM fields");
			while ($name = $stmt->fetchColumn()) {
				$registeredFieldTables['field_' . $name] = true;
			}
		} catch (\PDOException $e) {
			// If fields table is unreadable, skip the filter — back up everything
		}

		foreach ($allTables as $table) {
			if (isset($canonicalTables[$table])) continue;

			// For field_* tables: only back up if the field is registered in
			// the fields table. Unregistered = orphaned from deleted module.
			if (strpos($table, 'field_') === 0 && !empty($registeredFieldTables)) {
				if (!isset($registeredFieldTables[$table])) continue;
			}

			$createSql = $this->getCreateTable($database, $table);
			if (empty($createSql)) continue;

			$safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
			$rows = [];
			try {
				$stmt = $database->query("SELECT * FROM `$safeTable`");
				$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
			} catch (\PDOException $e) {
				continue;
			}

			$backup[$safeTable] = [
				'create' => $createSql,
				'rows' => $rows,
			];
		}

		return $backup;
	}

	/**
	 * Restore backed-up custom tables after the DB reset
	 *
	 * Unconditionally restores every table in the backup. Orphan filtering
	 * has already happened in backupCustomTables() by cross-referencing the
	 * `fields` table — so everything in the backup is legitimate.
	 *
	 * Uses DROP IF EXISTS + CREATE to overwrite any empty version that a
	 * module's install() may have just created with the backed-up data.
	 *
	 * @param WireDatabasePDO $database
	 * @param array $customTables Output of backupCustomTables()
	 * @return array List of restored table names
	 */
	protected function restoreCustomTables($database, array $customTables) {
		if (empty($customTables)) return [];

		$database->exec("SET FOREIGN_KEY_CHECKS = 0");

		$restored = [];

		foreach ($customTables as $table => $tableData) {
			$database->exec("DROP TABLE IF EXISTS `$table`");
			$database->exec($tableData['create']);

			foreach ($tableData['rows'] as $row) {
				if (empty($row)) continue;

				$cols = array_keys($row);
				$colsSql = '`' . implode('`,`', $cols) . '`';
				$placeholders = [];
				$bindParams = [];
				foreach ($cols as $i => $col) {
					$ph = ':v' . $i;
					$placeholders[] = $ph;
					$bindParams[$ph] = $row[$col];
				}
				$phSql = implode(',', $placeholders);

				try {
					$stmt = $database->prepare(
						"INSERT INTO `$table` ($colsSql) VALUES ($phSql)"
					);
					$stmt->execute($bindParams);
				} catch (\PDOException $e) {
					// Continue on row errors — don't fail the whole table
					$this->wire('log')->error("ProcessWireReset: row insert failed for `$table`: " . $e->getMessage());
				}
			}

			$restored[] = $table;
		}

		$database->exec("SET FOREIGN_KEY_CHECKS = 1");

		return $restored;
	}

	/**
	 * Drop ALL tables in the current database
	 *
	 * Uses INFORMATION_SCHEMA scoped to the current DB (more reliable than
	 * SHOW TABLES which can return stale results in some MySQL setups) and
	 * a multi-pass approach to handle any tables that resist a single-pass
	 * drop (e.g. due to FK constraints, table cache issues, or concurrent
	 * connections). After dropping, throws if any tables remain.
	 *
	 * @param WireDatabasePDO $database
	 * @throws WireException If tables remain after all drop passes
	 */
	protected function dropAllTables($database) {
		$dbName = $this->wire('config')->dbName;

		$database->exec("SET FOREIGN_KEY_CHECKS = 0");

		$maxPasses = 5;
		for ($pass = 0; $pass < $maxPasses; $pass++) {
			$stmt = $database->prepare(
				"SELECT TABLE_NAME FROM information_schema.TABLES " .
				"WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = 'BASE TABLE'"
			);
			$stmt->execute([':db' => $dbName]);
			$tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);

			if (empty($tables)) break;

			foreach ($tables as $table) {
				$safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
				if ($safe === '') continue;
				try {
					$database->exec("DROP TABLE IF EXISTS `$safe`");
				} catch (\PDOException $e) {
					// Continue — retry in next pass
				}
			}
		}

		// Also drop any views that may exist
		try {
			$stmt = $database->prepare(
				"SELECT TABLE_NAME FROM information_schema.TABLES " .
				"WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = 'VIEW'"
			);
			$stmt->execute([':db' => $dbName]);
			$views = $stmt->fetchAll(\PDO::FETCH_COLUMN);
			foreach ($views as $view) {
				$safe = preg_replace('/[^a-zA-Z0-9_]/', '', $view);
				if ($safe !== '') $database->exec("DROP VIEW IF EXISTS `$safe`");
			}
		} catch (\PDOException $e) {
			// Ignore — views are optional cleanup
		}

		$database->exec("SET FOREIGN_KEY_CHECKS = 1");

		// Verify clean state
		$stmt = $database->prepare(
			"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db"
		);
		$stmt->execute([':db' => $dbName]);
		$remaining = (int) $stmt->fetchColumn();
		if ($remaining > 0) {
			throw new WireException(
				"dropAllTables: $remaining table(s) could not be dropped from database '$dbName'"
			);
		}
	}

	/**
	 * Import a SQL file (PW install.sql format) via raw PDO
	 *
	 * Handles WireDatabaseBackup header lines and SQL comments.
	 *
	 * Uses WireDatabaseBackup::restoreMerge() — the same method the PW installer
	 * uses. This correctly merges data from both files: core provides base data
	 * (permissions, roles, passwords) while the profile adds content data.
	 * Sequential import would fail because the profile's DROP TABLE statements
	 * destroy the core's data.
	 *
	 * @param WireDatabasePDO $database
	 * @param string $coreFile Path to wire/core/install.sql
	 * @param string $profileFile Path to profile install.sql
	 * @param Config $config
	 * @throws WireException
	 */
	protected function importSqlMerged($database, $coreFile, $profileFile, $config) {
		$backupClass = $config->paths->wire . 'core/WireDatabaseBackup.php';
		if (!class_exists('\ProcessWire\WireDatabaseBackup', false)) {
			require_once $backupClass;
		}

		$backup = new WireDatabaseBackup();
		$backup->setDatabase($database);

		$restoreOptions = [];
		$dbEngine = $config->dbEngine ?: 'InnoDB';
		$dbCharset = $config->dbCharset ?: 'utf8';

		$replace = [];
		$replace['ENGINE=InnoDB'] = "ENGINE=$dbEngine";
		$replace['ENGINE=MyISAM'] = "ENGINE=$dbEngine";
		$replace['CHARSET=utf8mb4;'] = "CHARSET=$dbCharset;";
		$replace['CHARSET=utf8;'] = "CHARSET=$dbCharset;";

		if (strtolower($dbCharset) === 'utf8mb4') {
			if (strtolower($dbEngine) === 'innodb') {
				$replace['(255)'] = '(191)';
				$replace['(250)'] = '(191)';
			} else {
				$replace['(255)'] = '(250)';
			}
		}

		$restoreOptions['findReplaceCreateTable'] = $replace;

		if (!$backup->restoreMerge($coreFile, $profileFile, $restoreOptions)) {
			$errors = $backup->errors();
			throw new WireException("SQL import failed: " . implode(', ', $errors));
		}
	}

	/**
	 * Restore the superuser account into the freshly imported database
	 *
	 * The core install.sql (imported via restoreMerge) already populates
	 * field_pass, field_email, field_roles, and field_permissions with
	 * empty defaults for users 40 and 41. We only need to UPDATE the
	 * existing entries with the backed-up credentials.
	 *
	 * @param WireDatabasePDO $database
	 * @param array $superuser Backed-up superuser data
	 * @param Config $config
	 */
	protected function restoreSuperuser($database, array $superuser, $config) {
		$id = (int) $superuser['id'];

		// Restore original field_pass/field_email schemas to prevent hash
		// truncation if the live installation had larger columns than the
		// default char(40)/char(32) (e.g. from PW upgrades over time).
		// We drop the freshly imported tables and recreate with the original
		// schemas, then re-insert the guest user defaults the core sql expects.
		if (!empty($superuser['pass_schema'])) {
			$database->exec("DROP TABLE IF EXISTS `field_pass`");
			$database->exec($superuser['pass_schema']);
			// Re-insert guest user default (core install.sql had this)
			$database->exec("INSERT INTO field_pass (pages_id, data, salt) VALUES (40, '', '')");
		}
		if (!empty($superuser['email_schema'])) {
			$database->exec("DROP TABLE IF EXISTS `field_email`");
			$database->exec($superuser['email_schema']);
		}

		// Update admin page name to match original superuser
		$stmt = $database->prepare("UPDATE pages SET name = :name WHERE id = :id");
		$stmt->execute([':name' => $superuser['name'], ':id' => $id]);

		// Insert password (table was just recreated, empty for user 41)
		$stmt = $database->prepare(
			"INSERT INTO field_pass (pages_id, data, salt) VALUES (:id, :data, :salt)"
		);
		$stmt->execute([
			':id' => $id,
			':data' => $superuser['pass_data'],
			':salt' => $superuser['pass_salt'],
		]);

		// Insert email (table was just recreated)
		$stmt = $database->prepare(
			"INSERT INTO field_email (pages_id, data) VALUES (:id, :data)"
		);
		$stmt->execute([':id' => $id, ':data' => $superuser['email'] ?: '']);

		// Roles and permissions are already set up by the core install.sql:
		//   field_roles: user 41 gets guest + superuser roles
		//   field_permissions: full default assignments for both roles
	}

	/**
	 * Re-register ProcessWireReset itself in the fresh modules table
	 *
	 * Only self is restored immediately. Other kept modules are deferred
	 * via writePendingInstalls() so their install() side effects (admin
	 * pages, custom tables, fields) are recreated on the next request.
	 *
	 * @param WireDatabasePDO $database
	 * @param array $keptModuleData
	 */
	protected function restoreSelfModule($database, array $keptModuleData) {
		$selfClass = $this->className();
		$selfData = isset($keptModuleData[$selfClass]) ? $keptModuleData[$selfClass] : [
			'class' => $selfClass,
			'flags' => 0,
			'data' => '',
		];

		$stmt = $database->prepare("SELECT id FROM modules WHERE class = :class");
		$stmt->execute([':class' => $selfClass]);
		if ($stmt->fetch()) return;

		$stmt = $database->prepare(
			"INSERT INTO modules (class, flags, data, created) VALUES (:class, :flags, :data, NOW())"
		);
		$stmt->execute([
			':class' => $selfClass,
			':flags' => $selfData['flags'],
			':data' => $selfData['data'],
		]);
	}

	/**
	 * Write kept modules (except self) to the pending-installs file
	 *
	 * The next request loads ProcessWireReset as autoload (triggered by the
	 * presence of this file), and processPendingInstalls() calls each
	 * module's install() to recreate admin pages, tables, and other side
	 * effects, then restores the backed-up config.
	 *
	 * @param array $keptModuleData
	 * @param array $installOrder
	 * @throws WireException If the pending file cannot be written
	 */
	protected function writePendingInstalls(array $keptModuleData, array $installOrder) {
		$selfClass = $this->className();
		$pending = [];

		foreach ($installOrder as $className) {
			if ($className === $selfClass) continue;

			$item = ['class' => $className];
			// Include backed-up config for modules that were previously
			// installed (user selection + their site-module transitive deps).
			// Core modules added as dependencies have no backup data and
			// will be installed with their default config.
			if (isset($keptModuleData[$className])) {
				$item['flags'] = (int) $keptModuleData[$className]['flags'];
				$item['data'] = (string) $keptModuleData[$className]['data'];
			}
			$pending[] = $item;
		}

		$pendingFile = __DIR__ . '/' . self::PENDING_FILE;
		if (empty($pending)) {
			if (file_exists($pendingFile) && !unlink($pendingFile)) {
				$this->wire('log')->error("ProcessWireReset: Could not remove pending file: $pendingFile");
			}
			return;
		}
		$json = json_encode($pending, JSON_PRETTY_PRINT);
		if (file_put_contents($pendingFile, $json) === false) {
			$this->wire('log')->error("ProcessWireReset: Could not write pending file: $pendingFile");
			throw new WireException(
				"Cannot write pending installs file at $pendingFile — kept modules will not be restored after reset."
			);
		}
	}

	/**
	 * Write backed-up custom tables to a pending file for deferred restore
	 *
	 * Custom tables cannot be restored immediately after the DB reset: doing
	 * so would conflict with the kept modules' install() methods on the next
	 * request (which try to CREATE TABLE for their own storage). Instead we
	 * serialize the backup to a pending file and restore it in
	 * processPendingCustomTables() — after all install() calls have finished.
	 *
	 * Uses PHP serialize() rather than JSON because table rows may contain
	 * binary data, non-UTF8 strings, or other non-JSON-safe values.
	 *
	 * @param array $customTables Output of backupCustomTables()
	 * @throws WireException If the pending file cannot be written
	 */
	protected function writePendingCustomTables(array $customTables) {
		$pendingFile = __DIR__ . '/' . self::PENDING_TABLES_FILE;

		if (empty($customTables)) {
			if (file_exists($pendingFile) && !unlink($pendingFile)) {
				$this->wire('log')->error("ProcessWireReset: Could not remove pending tables file: $pendingFile");
			}
			return;
		}

		$serialized = serialize($customTables);
		if (file_put_contents($pendingFile, $serialized) === false) {
			$this->wire('log')->error("ProcessWireReset: Could not write pending tables file: $pendingFile");
			throw new WireException(
				"Cannot write pending custom tables file at $pendingFile — module table data will not be restored."
			);
		}
	}

	/**
	 * Disable autoloaded debug tools and suppress errors
	 *
	 * Autoload modules like TracyDebugger register PHP shutdown handlers.
	 * When we delete their files, those handlers fail fatally on exit.
	 * This method disables known debug tools and suppresses all error
	 * output so shutdown handlers of deleted modules fail silently.
	 */
	protected function silenceAutoloadModules() {
		// Suppress all error output from this point on
		error_reporting(0);
		ini_set('display_errors', '0');

		// Disable TracyDebugger specifically if loaded
		if (class_exists('\Tracy\Debugger', false)) {
			\Tracy\Debugger::$showBar = false;
			\Tracy\Debugger::enable(\Tracy\Debugger::ProductionMode);
		}

		// Clear output buffers registered by debug tools
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
	}

	// =====================================================================
	// Path Resolution Helpers
	// =====================================================================

	/**
	 * Resolve the path to the profile's install.sql
	 *
	 * For custom profile paths, the resolved absolute path is verified to
	 * lie within the PW root directory to prevent directory traversal
	 * (e.g. "../../../etc/passwd" would be rejected).
	 *
	 * @param array $data Module config data
	 * @return string|null Absolute path to install.sql or null if invalid
	 */
	protected function resolveProfileInstallSql(array $data) {
		if (!empty($data['profilePath'])) {
			$candidate = rtrim($data['profilePath'], '/') . '/install.sql';
			$path = realpath($candidate);
			if ($path === false) return null;
			if (!$this->isPathAllowed($path)) return null;
			return is_file($path) ? $path : null;
		}
		return __DIR__ . '/install/install.sql';
	}

	/**
	 * Resolve the path to the profile's template files
	 *
	 * For the bundled default, templates live in install/site-templates/.
	 * For custom profiles, templates are expected at ../templates/ relative
	 * to the install dir. Custom paths are validated against the PW root
	 * to prevent directory traversal.
	 *
	 * @param array $data Module config data
	 * @return string|null Absolute path to templates dir or null if invalid
	 */
	protected function resolveProfileTemplatesPath(array $data) {
		if (!empty($data['profilePath'])) {
			$candidate = dirname(rtrim($data['profilePath'], '/')) . '/templates/';
			$path = realpath($candidate);
			if ($path === false) return null;
			if (!$this->isPathAllowed($path)) return null;
			return is_dir($path) ? $path : null;
		}
		$path = __DIR__ . '/install/site-templates/';
		return is_dir($path) ? $path : null;
	}

	/**
	 * Verify that an absolute path is within the PW root directory
	 *
	 * Prevents directory traversal attacks via user-supplied profile paths.
	 *
	 * @param string $absolutePath A real (canonicalized) absolute path
	 * @return bool True if the path is inside the PW root
	 */
	protected function isPathAllowed($absolutePath) {
		$root = realpath($this->wire('config')->paths->root);
		if ($root === false) return false;
		return strpos($absolutePath, rtrim($root, '/') . '/') === 0
			|| $absolutePath === $root;
	}

	/**
	 * Sanitize a URL for use in a Location header
	 *
	 * Allows only same-host or relative URLs to prevent open-redirect /
	 * header-injection attacks. Falls back to "./" if the URL points to
	 * a different host or contains CR/LF.
	 *
	 * @param string $url
	 * @return string Safe URL for Location header
	 */
	protected function safeRedirectUrl($url) {
		// Strip any CR/LF (header injection guard)
		$url = str_replace(["\r", "\n"], '', (string) $url);
		if ($url === '') return './';

		$parsed = parse_url($url);
		if ($parsed === false) return './';

		// If a host is specified, it must match the current request host
		if (!empty($parsed['host'])) {
			$currentHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
			if ($parsed['host'] !== $currentHost) return './';
		}

		return $url;
	}

	/**
	 * Expand the user's keep-modules selection to include transitive deps
	 *
	 * Walks each selected module's 'requires' info and recursively includes
	 * any site-module (not core) dependencies. This ensures that preserving
	 * Module A also preserves the site modules it needs to function (B → C → ...).
	 * Core modules are excluded because they always live in wire/modules/
	 * and survive a reset untouched.
	 *
	 * @param array $keepModules User-selected module class names
	 * @return array Expanded list including transitive site-module dependencies
	 */
	protected function expandKeepModules(array $keepModules) {
		$modules = $this->wire('modules');
		$siteModulesPath = $this->wire('config')->paths->siteModules;
		$selfClass = $this->className();

		$resolved = [];
		$stack = array_values(array_unique($keepModules));

		while (!empty($stack)) {
			$className = array_shift($stack);
			if (empty($className) || isset($resolved[$className])) continue;
			if ($className === $selfClass) continue;

			// Only include site modules — core modules always survive
			$path = $modules->getModuleFile($className);
			if (!$path || strpos($path, $siteModulesPath) !== 0) continue;

			$resolved[$className] = true;

			$info = $modules->getModuleInfoVerbose($className);

			// Follow 'requires' — modules this one depends on
			foreach ((array) (isset($info['requires']) ? $info['requires'] : []) as $req) {
				if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)/', $req, $m)) {
					if (!isset($resolved[$m[1]])) $stack[] = $m[1];
				}
			}

			// Follow 'installs' — bundled modules co-installed with this one
			foreach ((array) (isset($info['installs']) ? $info['installs'] : []) as $coModule) {
				if (!empty($coModule) && !isset($resolved[$coModule])) {
					$stack[] = $coModule;
				}
			}
		}

		return array_keys($resolved);
	}

	/**
	 * Resolve the full install order including core-module dependencies
	 *
	 * Walks the dependency graph starting from the user's selection,
	 * collecting ALL transitive dependencies (both site and core modules).
	 * Then performs a topological sort (Kahn's algorithm) so that
	 * dependencies come before the modules that depend on them — ensuring
	 * each module can be installed individually without relying on PW's
	 * nested auto-install logic.
	 *
	 * Core modules that must be explicitly installed (e.g. InputfieldCKEditor,
	 * FieldtypeMapMarker) are included in the list; already-installed core
	 * modules will be skipped by the install loop via the DB-direct check.
	 *
	 * @param array $keepModules Expanded keep-modules list (site modules)
	 * @return array Class names in topological order (deps first)
	 */
	protected function resolveInstallOrder(array $keepModules) {
		$modules = $this->wire('modules');
		$selfClass = $this->className();

		// Step 1: BFS through dependency graph, collecting requires + installs
		$requires = [];  // class => [dep1, dep2, ...] (hard deps for topo sort)
		$queue = array_values(array_unique($keepModules));

		while (!empty($queue)) {
			$className = array_shift($queue);
			if (empty($className) || isset($requires[$className])) continue;
			if ($className === $selfClass) continue;
			if ($className === 'ProcessWire' || $className === 'PHP') continue;

			// Only consider modules that actually exist as files
			$path = $modules->getModuleFile($className);
			if (!$path) continue;

			$info = $modules->getModuleInfoVerbose($className);
			$deps = [];

			// Follow 'requires' — hard dependencies that define install order
			foreach ((array) (isset($info['requires']) ? $info['requires'] : []) as $req) {
				if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)/', $req, $m)) {
					$depClass = $m[1];
					if ($depClass === 'ProcessWire' || $depClass === 'PHP') continue;
					if ($depClass === $selfClass) continue;
					$deps[] = $depClass;
					if (!isset($requires[$depClass])) {
						$queue[] = $depClass;
					}
				}
			}

			// Follow 'installs' — bundled co-installed modules. These don't
			// create a hard ordering constraint (the parent installs them),
			// but they must be in the graph so their config gets backed up.
			foreach ((array) (isset($info['installs']) ? $info['installs'] : []) as $coModule) {
				if (empty($coModule)) continue;
				if ($coModule === $selfClass || $coModule === 'ProcessWire' || $coModule === 'PHP') continue;
				if (!isset($requires[$coModule])) {
					$queue[] = $coModule;
				}
			}

			$requires[$className] = $deps;
		}

		// Step 2: Topological sort via Kahn's algorithm
		// in-degree of X = number of X's dependencies that are also in our set
		$inDegree = [];
		foreach ($requires as $class => $deps) {
			$inDegree[$class] = 0;
		}
		foreach ($requires as $class => $deps) {
			foreach ($deps as $dep) {
				if (isset($requires[$dep])) {
					$inDegree[$class]++;
				}
			}
		}

		// Start with modules that have no unresolved dependencies
		$ready = [];
		foreach ($inDegree as $class => $degree) {
			if ($degree === 0) $ready[] = $class;
		}

		$sorted = [];
		while (!empty($ready)) {
			$class = array_shift($ready);
			$sorted[] = $class;
			// Decrement in-degree of modules that depend on $class
			foreach ($requires as $other => $otherDeps) {
				if (!in_array($class, $otherDeps)) continue;
				if (!isset($inDegree[$other]) || $inDegree[$other] <= 0) continue;
				$inDegree[$other]--;
				if ($inDegree[$other] === 0) {
					$ready[] = $other;
				}
			}
		}

		// Append any modules with remaining dependencies (cycles or
		// unresolved refs) at the end as a safety net
		foreach ($requires as $class => $deps) {
			if (!in_array($class, $sorted)) {
				$sorted[] = $class;
			}
		}

		return $sorted;
	}

	/**
	 * Resolve which module directory names to keep in site/modules/
	 *
	 * @param array $data Module config data
	 * @return array Directory/file names to preserve
	 */
	protected function resolveKeepModuleDirs(array $data) {
		$modules = $this->wire('modules');
		$siteModulesPath = $this->wire('config')->paths->siteModules;
		$dirs = [$this->className()];

		foreach ((array) $data['keepModules'] as $className) {
			$path = $modules->getModuleFile($className);
			if (!$path || strpos($path, $siteModulesPath) !== 0) continue;

			$relative = substr($path, strlen($siteModulesPath));
			$parts = explode('/', $relative);
			if (!empty($parts[0])) $dirs[] = $parts[0];
		}

		return array_unique($dirs);
	}

	// =====================================================================
	// Filesystem Helpers
	// =====================================================================

	/**
	 * Record a filesystem operation failure
	 *
	 * Adds the failure to $this->fsFailures so Phase 3 can detect it at
	 * the end and raise a WireException. Also logs to PW's log system.
	 *
	 * @param string $message
	 */
	protected function fsFailure($message) {
		$this->fsFailures[] = $message;
		// Use direct file write as fallback — wire('log') may not work after
		// silenceAutoloadModules() sets error_reporting(0).
		try {
			$this->wire('log')->save('processwirereset', $message);
		} catch (\Exception $e) {
			// Fallback: write directly to log file
			$logDir = $this->wire('config')->paths->assets . 'logs/';
			if (is_dir($logDir)) {
				@file_put_contents(
					$logDir . 'processwirereset.txt',
					date('Y-m-d H:i:s') . " $message\n",
					FILE_APPEND
				);
			}
		}
	}

	/**
	 * Pre-flight filesystem permission check
	 *
	 * Before the destructive phase starts, verify we can actually create
	 * AND delete files in every directory the reset will touch. is_writable()
	 * alone is not enough — on some setups files created by another user
	 * cannot be deleted even if the parent directory is nominally writable.
	 *
	 * The check creates a unique temporary file in each directory, then
	 * immediately deletes it. If either step fails, the path is recorded as
	 * unusable.
	 *
	 * @return string[] Array of human-readable error messages (empty = all OK)
	 */
	protected function preflightCheck() {
		$config = $this->wire('config');
		$sitePath = $config->paths->site;

		// Paths the reset needs to write to / delete from
		$paths = [
			$sitePath . 'assets/files/',
			$sitePath . 'assets/cache/',
			$sitePath . 'assets/logs/',
			$sitePath . 'assets/sessions/',
			$sitePath . 'assets/',        // for installed.php write
			$sitePath . 'templates/',
			$sitePath . 'modules/',
			__DIR__ . '/',                // for pending files
		];

		$errors = [];
		foreach ($paths as $path) {
			if (!is_dir($path)) {
				// Missing dirs for assets are fine — emptyDirectory() will mkdir
				// them. But we still need write access on the parent.
				$parent = dirname(rtrim($path, '/'));
				if (!is_dir($parent) || !is_writable($parent)) {
					$errors[] = "$path does not exist and its parent is not writable";
				}
				continue;
			}

			if (!is_writable($path)) {
				$errors[] = "$path is not writable";
				continue;
			}

			// Try the actual create + delete dance
			$testFile = rtrim($path, '/') . '/.pwreset-preflight-' . uniqid('', true);
			$written = @file_put_contents($testFile, 'ok');
			if ($written === false) {
				$errors[] = "$path: cannot create files (write failed)";
				continue;
			}
			if (!@unlink($testFile)) {
				$errors[] = "$path: cannot delete files (created a test file but could not remove it — $testFile)";
				// Best effort: don't leave the test file behind
				continue;
			}
		}

		// Also verify that the kept-module directories can be traversed
		// (we recursively walk into them during cleanModulesDirectory and need
		// to read file metadata there).
		$modulesDir = $sitePath . 'modules/';
		if (is_dir($modulesDir)) {
			try {
				$iter = new \DirectoryIterator($modulesDir);
				foreach ($iter as $item) {
					if ($item->isDot()) continue;
					if (!$item->isReadable()) {
						$errors[] = "$modulesDir{$item->getFilename()}: not readable";
					}
				}
			} catch (\Exception $e) {
				$errors[] = "$modulesDir: cannot iterate (" . $e->getMessage() . ")";
			}
		}

		return $errors;
	}

	/**
	 * Empty a directory (remove all contents but keep the directory itself)
	 *
	 * Skips any paths listed in $keepDirPaths. Failures are tracked in
	 * $this->fsFailures.
	 *
	 * @param string $dir
	 * @param array $keepDirPaths Map of absolute paths to preserve (optional)
	 */
	protected function emptyDirectory($dir, array $keepDirPaths = []) {
		if (!is_dir($dir)) {
			if (!mkdir($dir, $this->getChmodDir(), true) && !is_dir($dir)) {
				$this->fsFailure("Could not create directory: $dir");
			}
			return;
		}

		try {
			$iterator = new \DirectoryIterator($dir);
			foreach ($iterator as $item) {
				if ($item->isDot()) continue;
				$path = $item->getPathname();
				if ($this->isKeptPath($path, $keepDirPaths)) continue;
				if ($item->isDir()) {
					$this->removeDirectoryRecursive($path);
				} else {
					if (!unlink($path)) {
						$this->fsFailure("Could not delete file: $path");
					}
				}
			}
		} catch (\Exception $e) {
			$this->fsFailure("Could not iterate directory $dir: " . $e->getMessage());
		}
	}

	/**
	 * Recursively remove a directory and all its contents
	 *
	 * Failures are tracked in $this->fsFailures and raised by Phase 3.
	 *
	 * @param string $dir
	 */
	protected function removeDirectoryRecursive($dir) {
		if (!is_dir($dir)) return;

		try {
			$items = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ($items as $item) {
				$path = $item->getPathname();
				$ok = $item->isDir() ? rmdir($path) : unlink($path);
				if (!$ok) {
					$this->fsFailure("Could not remove: $path");
				}
			}
		} catch (\Exception $e) {
			$this->fsFailure("Could not iterate directory $dir: " . $e->getMessage());
			return;
		}

		if (!rmdir($dir)) {
			$this->fsFailure("Could not remove directory: $dir");
		}
	}

	/**
	 * Recursively copy a directory
	 *
	 * @param string $src Source directory
	 * @param string $dst Destination directory
	 */
	protected function copyDirectoryRecursive($src, $dst) {
		if (!is_dir($dst)) {
			if (!mkdir($dst, $this->getChmodDir(), true) && !is_dir($dst)) {
				$this->fsFailure("Could not create directory: $dst");
				return;
			}
		}

		$iterator = new \DirectoryIterator($src);
		foreach ($iterator as $item) {
			if ($item->isDot()) continue;

			$srcPath = $item->getPathname();
			$dstPath = $dst . '/' . $item->getFilename();

			if ($item->isDir()) {
				$this->copyDirectoryRecursive($srcPath, $dstPath);
			} else {
				if (!copy($srcPath, $dstPath)) {
					$this->fsFailure("Could not copy $srcPath to $dstPath");
				} else {
					chmod($dstPath, $this->getChmodFile());
				}
			}
		}
	}

	/**
	 * Parse the keepDirectories textarea into a set of absolute paths
	 *
	 * @param string $textarea Raw textarea content (one path per line)
	 * @param string $sitePath Absolute path to site/ directory
	 * @return array Map of absolute path => true
	 */

	/**
	 * Get the octal permission for directories
	 *
	 * Uses the module config value if set, falls back to PW's $config->chmodDir,
	 * then to 0755 as a last resort.
	 *
	 * @return int Permission as integer (for chmod/mkdir)
	 */
	protected function getChmodDir() {
		$val = $this->chmodDir;
		if (empty($val)) $val = $this->wire('config')->chmodDir;
		if (empty($val)) $val = '0755';
		return octdec(ltrim($val, '0') ?: '755');
	}

	/**
	 * Get the octal permission for files
	 *
	 * Uses the module config value if set, falls back to PW's $config->chmodFile,
	 * then to 0644 as a last resort.
	 *
	 * @return int Permission as integer (for chmod)
	 */
	protected function getChmodFile() {
		$val = $this->chmodFile;
		if (empty($val)) $val = $this->wire('config')->chmodFile;
		if (empty($val)) $val = '0644';
		return octdec(ltrim($val, '0') ?: '644');
	}
	protected function parseKeepDirectories($textarea, $sitePath) {
		$result = [];
		$sitePath = rtrim($sitePath, '/') . '/';

		foreach (explode("\n", (string) $textarea) as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#') continue; // skip blanks and comments

			// Normalize: strip leading site/, leading/trailing slashes
			$line = preg_replace('#^site/#', '', $line);
			$line = trim($line, '/');
			if ($line === '') continue;

			$absPath = $sitePath . $line;
			$result[rtrim($absPath, '/')]  = true;
		}

		return $result;
	}

	/**
	 * Check if a path is inside any of the keep-directory paths
	 *
	 * @param string $path Absolute path to check
	 * @param array $keepDirPaths Map of absolute path => true
	 * @return bool
	 */
	protected function isKeptPath($path, array $keepDirPaths) {
		if (empty($keepDirPaths)) return false;
		$path = rtrim($path, '/');

		// Exact match or is a parent/child of a kept path
		if (isset($keepDirPaths[$path])) return true;
		foreach ($keepDirPaths as $kept => $v) {
			// $path is inside a kept dir
			if (strpos($path, $kept . '/') === 0) return true;
			// $path IS a parent of a kept dir (don't delete parent!)
			if (strpos($kept, $path . '/') === 0) return true;
		}
		return false;
	}

	/**
	 * Clean site/assets/ — empty the standard dirs, wipe everything else
	 *
	 * The four standard PW dirs (files, cache, logs, sessions) have their
	 * contents emptied but the directories themselves are preserved. Any
	 * other entry in site/assets/ (files or directories created by modules,
	 * e.g. TracyDebugger/, backups/, FormBuilder/, SearchEngine/) is
	 * removed entirely — unless listed in $keepDirPaths.
	 *
	 * Preserved as-is (not touched):
	 *   - installed.php (we rewrite it afterwards anyway)
	 *   - index.php (access guard file sometimes placed here)
	 *   - .htaccess (apache config)
	 *
	 * @param string $assetsDir Path to site/assets/
	 * @param array $keepDirPaths Map of absolute paths to preserve
	 */
	protected function cleanAssetsDirectory($assetsDir, array $keepDirPaths = []) {
		if (!is_dir($assetsDir)) return;

		$standardDirs = ['files', 'cache', 'logs', 'sessions'];
		$preserveFiles = ['installed.php', 'index.php', '.htaccess'];

		$iterator = new \DirectoryIterator($assetsDir);
		foreach ($iterator as $item) {
			if ($item->isDot()) continue;

			$name = $item->getFilename();
			$path = $item->getPathname();

			if ($this->isKeptPath($path, $keepDirPaths)) continue;

			if ($item->isDir()) {
				if (in_array($name, $standardDirs, true)) {
					$this->emptyDirectory($path, $keepDirPaths);
				} else {
					$this->removeDirectoryRecursive($path);
				}
			} else {
				if (in_array($name, $preserveFiles, true)) continue;
				if (!unlink($path)) {
					$this->fsFailure("Could not delete file: $path");
				}
			}
		}
	}

	/**
	 * Clean site/modules/ — remove everything except kept module directories
	 *
	 * @param string $modulesDir Path to site/modules/
	 * @param array $keepDirs Directory/file names to preserve
	 */
	protected function cleanModulesDirectory($modulesDir, array $keepDirs) {
		if (!is_dir($modulesDir)) return;

		$iterator = new \DirectoryIterator($modulesDir);
		foreach ($iterator as $item) {
			if ($item->isDot()) continue;

			$name = $item->getFilename();

			// Always keep README files
			$nameLower = strtolower($name);
			if ($nameLower === 'readme.txt' || $nameLower === 'readme.md') continue;

			// Keep if in the preserve list
			$baseName = pathinfo($name, PATHINFO_FILENAME);
			if (in_array($name, $keepDirs) || in_array($baseName, $keepDirs)) continue;

			$path = $item->getPathname();
			if ($item->isDir()) {
				$this->removeDirectoryRecursive($path);
			} else {
				if (!unlink($path)) {
					$this->fsFailure("Could not delete file: $path");
				}
			}
		}
	}
}
