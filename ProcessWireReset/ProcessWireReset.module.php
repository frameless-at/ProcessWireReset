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

	public static function getModuleInfo() {
		return [
			'title' => 'ProcessWire Reset',
			'version' => '0.0.1',
			'summary' => 'Resets a ProcessWire installation to a clean profile state while preserving the current superuser and selected modules.',
			'author' => 'frameless',
			'icon' => 'refresh',
			'singular' => true,
			'autoload' => false,
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

	public function init() {}

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
		$keptModuleData = $this->backupModuleData($database, $data);
		$profileTemplatesPath = $this->resolveProfileTemplatesPath($data);

		// ── Phase 2: Database reset ──────────────────────────────────────

		$this->dropAllTables($database);
		$this->importSqlMerged($database, $coreInstallSql, $profileInstallSql, $config);
		$this->restoreSuperuser($database, $superuser, $config);
		$this->restoreModules($database, $keptModuleData);

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
	 * Backup module DB entries for modules we want to keep
	 *
	 * @param WireDatabasePDO $database
	 * @param array $data Module config data
	 * @return array
	 */
	protected function backupModuleData($database, array $data) {
		$moduleNames = array_merge([$this->className()], (array) $data['keepModules']);
		$result = [];

		foreach (array_unique($moduleNames) as $className) {
			try {
				$stmt = $database->prepare("SELECT class, flags, data FROM modules WHERE class = :class");
				$stmt->execute([':class' => $className]);
				$row = $stmt->fetch(\PDO::FETCH_ASSOC);
				if ($row) $result[$className] = $row;
			} catch (\Exception $e) {
				// Module not in DB, will be inserted with defaults
				$result[$className] = [
					'class' => $className,
					'flags' => 0,
					'data' => '',
				];
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
	 * Drop ALL tables in the current database
	 *
	 * @param WireDatabasePDO $database
	 */
	protected function dropAllTables($database) {
		$database->exec("SET FOREIGN_KEY_CHECKS = 0");

		$tables = $database->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
		foreach ($tables as $table) {
			$table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
			$database->exec("DROP TABLE IF EXISTS `$table`");
		}

		$database->exec("SET FOREIGN_KEY_CHECKS = 1");
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
	 * Re-register kept modules in the freshly imported modules table
	 *
	 * @param WireDatabasePDO $database
	 * @param array $keptModuleData
	 */
	protected function restoreModules($database, array $keptModuleData) {
		foreach ($keptModuleData as $className => $moduleData) {
			// Check if module already exists in the fresh DB (e.g. core module)
			$stmt = $database->prepare("SELECT id FROM modules WHERE class = :class");
			$stmt->execute([':class' => $moduleData['class']]);
			if ($stmt->fetch()) continue;

			$stmt = $database->prepare(
				"INSERT INTO modules (class, flags, data, created) VALUES (:class, :flags, :data, NOW())"
			);
			$stmt->execute([
				':class' => $moduleData['class'],
				':flags' => $moduleData['flags'],
				':data' => $moduleData['data'],
			]);
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
