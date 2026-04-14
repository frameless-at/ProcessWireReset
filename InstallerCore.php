<?php namespace ProcessWire;

/**
 * InstallerCore — Headless ProcessWire Installer
 *
 * Adapted from ProcessWire's install.php (the Installer class). The wizard
 * GUI is stripped out entirely; only the core processing methods remain:
 * SQL import, admin account creation, and filesystem helpers.
 *
 * Caller populates this object's properties from the current installation's
 * state (superuser from the DB, profile path from the module config) instead
 * of the user typing values into the installer's setup forms. The methods
 * themselves are kept as close to the originals as practical so behavior
 * matches a fresh install.
 *
 * Upstream reference:
 * https://github.com/processwire/processwire/blob/master/install.php
 */
class InstallerCore {

	/** @var string Octal directory permission (e.g. '0755') */
	public $chmodDir = '0755';

	/** @var string Octal file permission (e.g. '0644') */
	public $chmodFile = '0644';

	/** @var string DB engine for CREATE TABLE rewrites */
	public $dbEngine = 'InnoDB';

	/** @var string DB charset for CREATE TABLE rewrites */
	public $dbCharset = 'utf8mb4';

	/** @var string[] Info messages collected during run (replaces echo) */
	public $messages = [];

	/** @var string[] Error messages collected during run (replaces echo) */
	public $errors = [];

	/**
	 * Import profile SQL dump
	 *
	 * Verbatim port of Installer::profileImportSQL() from install.php.
	 * Uses WireDatabaseBackup::restoreMerge() with findReplaceCreateTable
	 * rewrites so the profile SQL's ENGINE/CHARSET match the active DB.
	 *
	 * @param \PDO $database PDO or WireDatabasePDO connection
	 * @param string $file1 Absolute path to core install.sql
	 * @param string $file2 Absolute path to profile install.sql
	 * @param array $options Overrides: 'dbEngine', 'dbCharset'
	 * @throws \RuntimeException on import failure
	 */
	public function profileImportSQL($database, $file1, $file2, array $options = []) {
		$defaults = [
			'dbEngine' => $this->dbEngine,
			'dbCharset' => $this->dbCharset,
		];
		$options = array_merge($defaults, $options);

		$restoreOptions = [];
		$replace = [];
		$replace['ENGINE=InnoDB'] = "ENGINE={$options['dbEngine']}";
		$replace['ENGINE=MyISAM'] = "ENGINE={$options['dbEngine']}";
		$replace['CHARSET=utf8mb4;'] = "CHARSET={$options['dbCharset']};";
		$replace['CHARSET=utf8;'] = "CHARSET={$options['dbCharset']};";
		$replace['CHARSET=utf8 COLLATE='] = "CHARSET={$options['dbCharset']} COLLATE=";

		if(strtolower($options['dbCharset']) === 'utf8mb4') {
			if(strtolower($options['dbEngine']) === 'innodb') {
				$replace['(255)'] = '(191)';
				$replace['(250)'] = '(191)';
			} else {
				$replace['(255)'] = '(250)';
			}
		}

		if(!empty($replace)) $restoreOptions['findReplaceCreateTable'] = $replace;

		if(!class_exists('\\ProcessWire\\WireDatabaseBackup', false)) {
			$backupClass = wire('config')->paths->wire . 'core/WireDatabaseBackup.php';
			require_once $backupClass;
		}

		$backup = new WireDatabaseBackup();
		$backup->setDatabase($database);

		if($backup->restoreMerge($file1, $file2, $restoreOptions)) {
			$this->messages[] = "Imported database file: $file1";
			$this->messages[] = "Imported database file: $file2";
			return;
		}

		foreach($backup->errors() as $error) {
			$this->errors[] = $error;
		}
		throw new \RuntimeException(
			'profileImportSQL failed: ' . implode('; ', $backup->errors())
		);
	}

	/**
	 * Save admin account
	 *
	 * Adapted from Installer::adminAccountSave(). The key differences:
	 * 1. Values come from $params (not $_POST), pre-populated by the caller.
	 * 2. $passOverride lets the caller write the original hash+salt pair
	 *    directly to field_pass after the user is saved — necessary for a
	 *    reset, where we want to preserve the existing superuser login but
	 *    don't have the plain-text password.
	 * 3. No echo/HTML output; messages are collected in $this->messages.
	 *
	 * @param ProcessWire $wire Bootstrapped PW instance (API vars available)
	 * @param array $params Admin fields (same keys the installer reads from $_POST):
	 *   - username   string (required)
	 *   - userpass   string (required, min 6 chars; ignored if $passOverride set
	 *                       and a non-empty placeholder is still needed for the
	 *                       $user->pass setter to validate)
	 *   - useremail  string
	 *   - admin_name string (default: 'processwire')
	 * @param array|null $passOverride Optional ['data' => hash, 'salt' => salt]
	 *   — when set, field_pass is UPDATEd with these values after $users->save()
	 *   so the original password hash is preserved across the reset.
	 * @throws \RuntimeException on validation or save failure
	 */
	public function adminAccountSave(ProcessWire $wire, array $params, $passOverride = null) {
		$sanitizer = $wire->wire('sanitizer');
		$modules = $wire->wire('modules');
		$config = $wire->wire('config');

		$adminTheme = $modules->getInstall('AdminThemeUikit');

		if($config->defaultAdminTheme === 'AdminThemeUikit' && $modules->isInstalled('AdminThemeDefault')) {
			$modules->uninstall('AdminThemeDefault');
		}

		$usernameRaw = isset($params['username']) ? (string) $params['username'] : '';
		$userpass = isset($params['userpass']) ? (string) $params['userpass'] : '';
		$useremail = isset($params['useremail']) ? (string) $params['useremail'] : '';
		$adminNameRaw = isset($params['admin_name']) ? (string) $params['admin_name'] : 'processwire';

		if($usernameRaw === '' || $userpass === '') {
			throw new \RuntimeException('adminAccountSave: missing username or password');
		}
		if(strlen($userpass) < 6) {
			throw new \RuntimeException('adminAccountSave: password must be at least 6 characters');
		}

		$username = $sanitizer->pageName($usernameRaw);
		if($username !== $usernameRaw) {
			throw new \RuntimeException("adminAccountSave: username must be only a-z 0-9 (got '$usernameRaw')");
		}
		if(strlen($username) < 2) {
			throw new \RuntimeException('adminAccountSave: username must be at least 2 characters');
		}

		$adminName = $sanitizer->pageName($adminNameRaw);
		if($adminName !== $adminNameRaw) {
			throw new \RuntimeException("adminAccountSave: admin_name must be only a-z 0-9 (got '$adminNameRaw')");
		}
		if($adminName === 'wire' || $adminName === 'site') {
			throw new \RuntimeException("adminAccountSave: admin_name may not be 'wire' or 'site'");
		}
		if(strlen($adminName) < 2) {
			throw new \RuntimeException('adminAccountSave: admin_name must be at least 2 characters');
		}

		$email = strtolower($sanitizer->email($useremail));
		if($useremail !== '' && $email !== strtolower($useremail)) {
			throw new \RuntimeException('adminAccountSave: email did not validate');
		}

		$superuserRole = $wire->wire('roles')->get('name=superuser');
		$user = $wire->wire('users')->get($config->superUserPageID);

		if($user && $user->id) {
			$user->of(false);
		} else {
			$user = $wire->wire(new User());
			$user->id = $config->superUserPageID;
		}

		$user->name = $username;
		$user->pass = $userpass;
		$user->email = $email;
		$user->admin_theme = $adminTheme;

		if(!$user->roles->has('superuser')) $user->roles->add($superuserRole);

		$admin = $wire->wire('pages')->get($config->adminRootPageID);
		$admin->of(false);
		$admin->name = $adminName;

		try {
			$wire->wire('users')->save($user);
			$wire->wire('pages')->save($admin);
		} catch(\Exception $e) {
			throw new \RuntimeException(
				'adminAccountSave: save failed: ' . $e->getMessage(), 0, $e
			);
		}

		// Password hash override — restore the original superuser's hash+salt
		// directly so the existing login keeps working after the reset.
		// $user->pass above set a placeholder so PW's validation and save flow
		// complete normally; we just overwrite the persisted value here.
		if(is_array($passOverride) && isset($passOverride['data']) && $passOverride['data'] !== '') {
			$db = $wire->wire('database');
			$stmt = $db->prepare(
				'UPDATE field_pass SET data = :data, salt = :salt WHERE pages_id = :id'
			);
			$stmt->execute([
				':data' => $passOverride['data'],
				':salt' => isset($passOverride['salt']) ? $passOverride['salt'] : '',
				':id' => (int) $config->superUserPageID,
			]);
		}

		// Email override — if the caller passed a raw email that $sanitizer
		// rejected but we still want to preserve verbatim (e.g. an email
		// already stored in the DB from a prior install), allow a direct write.
		if(!empty($params['email_override'])) {
			$db = $wire->wire('database');
			$stmt = $db->prepare(
				'UPDATE field_email SET data = :data WHERE pages_id = :id'
			);
			$stmt->execute([
				':data' => (string) $params['email_override'],
				':id' => (int) $config->superUserPageID,
			]);
		}

		$this->messages[] = "User account saved: {$user->name}";

		// Signal installation completion — same marker PW's installer writes
		// so the original install.php (if still present) won't re-run.
		$installedMarker = $config->paths->site . 'assets/installed.php';
		file_put_contents(
			$installedMarker,
			"<?php // The existence of this file prevents the installer from running."
		);
	}

	/**
	 * Create a directory with proper permissions.
	 * Port of Installer::mkdir().
	 *
	 * @param string $path Directory path
	 * @param bool $block Also write a blocking .htaccess inside
	 * @return bool
	 */
	public function mkdir($path, $block = false) {
		$path = rtrim($path, '/') . '/';
		$isDir = is_dir($path);
		if($isDir || @mkdir($path, octdec($this->chmodDir), true)) {
			@chmod($path, octdec($this->chmodDir));
			$result = true;
		} else {
			$this->errors[] = "Error creating directory: $path";
			return false;
		}
		if($block) {
			$file = $path . '.htaccess';
			if(!file_exists($file)) {
				$data = [
					'# Start ProcessWire:pwball (install)',
					'# Block all access (fallback if root .htaccess missing)',
					'<IfModule mod_authz_core.c>',
					'  Require all denied',
					'</IfModule>',
					'<IfModule !mod_authz_core.c>',
					'  Order allow,deny',
					'  Deny from all',
					'</IfModule>',
					'# End ProcessWire:pwball',
				];
				file_put_contents($file, implode("\n", $data));
				@chmod($file, octdec($this->chmodFile));
			}
		}
		return $result;
	}

	/**
	 * Copy a single file with chmod.
	 * Port of Installer::copyFile().
	 *
	 * @param string $src
	 * @param string $dst
	 * @return bool
	 */
	public function copyFile($src, $dst) {
		if(!@copy($src, $dst)) {
			$this->errors[] = "Unable to copy $src => $dst";
			return false;
		}
		@chmod($dst, octdec($this->chmodFile));
		return true;
	}

	/**
	 * Recursively copy a directory with chmod on each file.
	 * Port of Installer::copyRecursive().
	 *
	 * @param string $src
	 * @param string $dst
	 * @param bool $overwrite
	 * @return bool
	 */
	public function copyRecursive($src, $dst, $overwrite = true) {
		if(substr($src, -1) !== '/') $src .= '/';
		if(substr($dst, -1) !== '/') $dst .= '/';

		$dir = @opendir($src);
		if(!$dir) return false;
		$this->mkdir($dst);

		while(false !== ($file = readdir($dir))) {
			if($file === '.' || $file === '..') continue;
			if(is_dir($src . $file)) {
				$this->copyRecursive($src . $file, $dst . $file, $overwrite);
			} else {
				if(!$overwrite && file_exists($dst . $file)) continue;
				@copy($src . $file, $dst . $file);
				@chmod($dst . $file, octdec($this->chmodFile));
			}
		}
		closedir($dir);
		return true;
	}
}
