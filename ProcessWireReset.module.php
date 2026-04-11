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

	public static function getModuleInfo() {
		return [
			'title' => 'ProcessWire Reset',
			'version' => '0.0.1',
			'summary' => 'Resets a ProcessWire installation to a clean profile state while preserving the current superuser and selected modules.',
			'author' => 'frameless',
			'icon' => 'refresh',
			'singular' => true,
			// Conditional autoload: only load automatically when there are
			// pending module installs to process after a reset.
			'autoload' => function() {
				return file_exists(__DIR__ . '/.pending-installs.json');
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
	}

	/**
	 * Initialize — processes pending module installs if present
	 *
	 * When a reset has kept modules, their re-install is deferred to the
	 * next request (when PW has a clean state). The pending file triggers
	 * conditional autoload of this module, and init() runs the installs.
	 */
	public function init() {
		$pendingFile = __DIR__ . '/' . self::PENDING_FILE;
		if (!file_exists($pendingFile)) return;

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

		$pending = @json_decode(@file_get_contents($pendingFile), true);
		// Delete file first to prevent infinite retries on error
		@unlink($pendingFile);

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

		// Restore user context
		$this->wire('user', $savedUser);
	}

	/**
	 * Build the module config screen and handle reset action
	 *
	 * @param array $data Saved config data
	 * @return InputfieldWrapper
	 */
	public function getModuleConfigInputfields(array $data) {
		$input = $this->wire('input');
		$modules = $this->wire('modules');

		if (!isset($data['profilePath'])) $data['profilePath'] = '';
		if (!isset($data['keepModules'])) $data['keepModules'] = [];

		// Handle reset action on POST
		if ($input->requestMethod('POST') && $input->post('submit_reset') !== null) {
			$confirm = $input->post('confirmReset');
			if ($confirm === self::CONFIRM_TEXT) {
				$this->executeReset($data);
				// executeReset exits; this line is never reached
			}
			$this->error($this->_('Confirmation text did not match. Reset was not executed.'));
		}

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
	 * @param array $data Current module config data
	 */
	protected function executeReset(array $data) {
		set_time_limit(300);

		$database = $this->wire('database');
		$config = $this->wire('config');
		$input = $this->wire('input');

		// Read current form selection from POST (not yet saved to $data)
		$postProfilePath = $input->post('profilePath');
		if ($postProfilePath !== null) {
			$data['profilePath'] = (string) $postProfilePath;
		}
		$postKeepModules = $input->post('keepModules');
		if ($postKeepModules !== null) {
			if (is_array($postKeepModules)) {
				$data['keepModules'] = $postKeepModules;
			} else {
				$data['keepModules'] = array_values(array_filter(
					array_map('trim', explode(',', (string) $postKeepModules))
				));
			}
		}

		// Automatically include all transitive site-module dependencies so
		// preserving Module A also preserves the modules it requires.
		$data['keepModules'] = $this->expandKeepModules((array) $data['keepModules']);

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

		$this->dropAllTables($database);
		$this->importSqlMerged($database, $coreInstallSql, $profileInstallSql, $config);
		$this->restoreSuperuser($database, $superuser, $config);
		// Only re-register ProcessWireReset itself directly so PW can autoload
		// it on the next request. Other kept modules are deferred — their
		// install() is called in processPendingInstalls() on the next request,
		// so admin pages, custom fields, DB tables etc. are recreated.
		$this->restoreSelfModule($database, $keptModuleData);
		$this->restoreCustomTables($database, $customTables);
		$this->writePendingInstalls($keptModuleData, $installOrder);

		// ── Phase 3: Filesystem reset ────────────────────────────────────

		// Disable autoloaded debug tools and suppress errors from their
		// shutdown handlers — module files are about to be deleted.
		$this->silenceAutoloadModules();

		$sitePath = $config->paths->site;

		$this->emptyDirectory($sitePath . 'assets/files/');
		$this->emptyDirectory($sitePath . 'assets/cache/');
		$this->emptyDirectory($sitePath . 'assets/logs/');
		$this->emptyDirectory($sitePath . 'assets/sessions/');

		// Reset templates to profile state
		$this->emptyDirectory($sitePath . 'templates/');
		if ($profileTemplatesPath && is_dir($profileTemplatesPath)) {
			$this->copyDirectoryRecursive($profileTemplatesPath, $sitePath . 'templates/');
		}

		// Clean site/modules/ — keep only self + selected
		$this->cleanModulesDirectory($sitePath . 'modules/', $keepModuleDirs);

		// Ensure installed.php exists (prevents PW installer from running)
		file_put_contents(
			$sitePath . 'assets/installed.php',
			"<?php // The existence of this file prevents the installer from running."
		);

		// ── Phase 4: Redirect to login ───────────────────────────────────

		$adminUrl = $config->urls->admin;

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
		} catch (\Exception $e) {
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
			} catch (\Exception $e) {
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
		} catch (\Exception $e) {
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
			$content = @file_get_contents($file);
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
	 * Back up all tables not present in the install.sql files
	 *
	 * Dumps structure (CREATE TABLE) and all rows so they can be
	 * recreated after the reset.
	 *
	 * @param WireDatabasePDO $database
	 * @param array $canonicalTables Map of table_name => true from getCanonicalTables
	 * @return array Array of [table => ['create' => ..., 'rows' => [...]]]
	 */
	protected function backupCustomTables($database, array $canonicalTables) {
		$backup = [];
		$allTables = $database->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);

		foreach ($allTables as $table) {
			if (isset($canonicalTables[$table])) continue;

			$createSql = $this->getCreateTable($database, $table);
			if (empty($createSql)) continue;

			$safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
			$rows = [];
			try {
				$stmt = $database->query("SELECT * FROM `$safeTable`");
				$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
			} catch (\Exception $e) {
				// Skip tables we can't read
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
	 * Recreates each table with its original CREATE TABLE statement and
	 * re-inserts all rows. Uses FOREIGN_KEY_CHECKS=0 to avoid FK issues
	 * with data that may reference freshly-imported canonical tables.
	 *
	 * @param WireDatabasePDO $database
	 * @param array $customTables Output of backupCustomTables()
	 */
	protected function restoreCustomTables($database, array $customTables) {
		if (empty($customTables)) return;

		$database->exec("SET FOREIGN_KEY_CHECKS = 0");

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
				} catch (\Exception $e) {
					// Continue on row errors — don't fail the whole table
				}
			}
		}

		$database->exec("SET FOREIGN_KEY_CHECKS = 1");
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
				} catch (\Exception $e) {
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
		} catch (\Exception $e) {
			// Ignore
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
			@unlink($pendingFile);
			return;
		}
		@file_put_contents($pendingFile, json_encode($pending, JSON_PRETTY_PRINT));
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
	 * @param array $data Module config data
	 * @return string|null
	 */
	protected function resolveProfileInstallSql(array $data) {
		if (!empty($data['profilePath'])) {
			$path = rtrim($data['profilePath'], '/') . '/install.sql';
			return is_file($path) ? $path : null;
		}
		return __DIR__ . '/install/install.sql';
	}

	/**
	 * Resolve the path to the profile's template files
	 *
	 * For the bundled default, templates live in install/site-templates/.
	 * For custom profiles, templates are expected at ../templates/ relative to the install dir.
	 *
	 * @param array $data Module config data
	 * @return string|null
	 */
	protected function resolveProfileTemplatesPath(array $data) {
		if (!empty($data['profilePath'])) {
			$path = dirname(rtrim($data['profilePath'], '/')) . '/templates/';
			return is_dir($path) ? $path : null;
		}
		$path = __DIR__ . '/install/site-templates/';
		return is_dir($path) ? $path : null;
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

			// Inspect dependencies via verbose info
			$info = $modules->getModuleInfoVerbose($className);
			$requires = isset($info['requires']) ? (array) $info['requires'] : [];

			foreach ($requires as $req) {
				// Strip optional version constraint: "ModuleName>=1.0.0" → "ModuleName"
				if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)/', $req, $m)) {
					$depClass = $m[1];
					if (!isset($resolved[$depClass])) {
						$stack[] = $depClass;
					}
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

		// Step 1: BFS through dependency graph, collecting requires info
		$requires = [];  // class => [dep1, dep2, ...]
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
			$moduleRequires = isset($info['requires']) ? (array) $info['requires'] : [];

			foreach ($moduleRequires as $req) {
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
	 * Empty a directory (remove all contents but keep the directory itself)
	 *
	 * @param string $dir
	 */
	protected function emptyDirectory($dir) {
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
			return;
		}

		$iterator = new \DirectoryIterator($dir);
		foreach ($iterator as $item) {
			if ($item->isDot()) continue;
			$path = $item->getPathname();
			if ($item->isDir()) {
				$this->removeDirectoryRecursive($path);
			} else {
				@unlink($path);
			}
		}
	}

	/**
	 * Recursively remove a directory and all its contents
	 *
	 * @param string $dir
	 */
	protected function removeDirectoryRecursive($dir) {
		if (!is_dir($dir)) return;

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($items as $item) {
			if ($item->isDir()) {
				@rmdir($item->getPathname());
			} else {
				@unlink($item->getPathname());
			}
		}

		@rmdir($dir);
	}

	/**
	 * Recursively copy a directory
	 *
	 * @param string $src Source directory
	 * @param string $dst Destination directory
	 */
	protected function copyDirectoryRecursive($src, $dst) {
		if (!is_dir($dst)) mkdir($dst, 0755, true);

		$iterator = new \DirectoryIterator($src);
		foreach ($iterator as $item) {
			if ($item->isDot()) continue;

			$srcPath = $item->getPathname();
			$dstPath = $dst . '/' . $item->getFilename();

			if ($item->isDir()) {
				$this->copyDirectoryRecursive($srcPath, $dstPath);
			} else {
				copy($srcPath, $dstPath);
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
			if (strtolower($name) === 'readme.txt' || strtolower($name) === 'readme.md') continue;

			// Keep if in the preserve list
			$baseName = pathinfo($name, PATHINFO_FILENAME);
			if (in_array($name, $keepDirs) || in_array($baseName, $keepDirs)) continue;

			$path = $item->getPathname();
			if ($item->isDir()) {
				$this->removeDirectoryRecursive($path);
			} else {
				@unlink($path);
			}
		}
	}
}
