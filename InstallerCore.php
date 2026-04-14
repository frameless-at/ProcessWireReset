<?php namespace ProcessWire;

// Load the exact upstream Installer class from ProcessWire's install.php.
// Only lines 1-2117 (the class body) are kept — the procedural self-execution
// at the bottom (which would immediately run the installer) is stripped.
// The file is in vendor/Installer.php and stays in the ProcessWire namespace.
if(!class_exists('\\ProcessWire\\Installer', false)) {
	require_once __DIR__ . '/vendor/Installer.php';
}

/**
 * InstallerCore — headless extension of ProcessWire's own Installer class.
 *
 * Only the parts that don't work when called from inside a running PW instance
 * are overridden here:
 *
 *  - HTML/output methods (alert, ok, err, h, p, btn, …)
 *      Silenced — messages collected in $this->messages / $this->errors instead.
 *
 *  - getRemoveableItems()
 *      Disabled — a reset must not delete install.php or site/install/, since
 *      those files are needed for subsequent resets.
 *
 *  - finish()
 *      Path corrected — the original uses __DIR__ which would point to the
 *      module's vendor/ folder. We resolve the path via $config->paths->root.
 *
 *  - profileImportSQL()
 *      require_once replaces require — WireDatabaseBackup is already loaded
 *      inside a live PW instance; a plain require() would trigger a fatal
 *      "Cannot redeclare class" error.
 *
 * Everything else — especially adminAccountSave(), which calls
 * getInstall('AdminThemeUikit') and sets useLoginScreen — runs verbatim from
 * the upstream Installer class.
 */
class InstallerCore extends Installer {

	/** @var string[] Info/ok messages collected during run (replaces echo) */
	public $messages = [];

	/** @var string[] Error messages collected during run (replaces echo) */
	public $errors = [];

	// ── Output / GUI methods — all silenced ──────────────────────────────

	protected function alert($str, $type = 'primary', $icon = 'check') {}
	protected function alertOk($str, $icon = 'check') {}
	protected function alertWarn($str) {}
	protected function alertErr($str) {
		$this->numErrors++;
		$this->errors[] = strip_tags((string) $str);
	}
	public function err($str) {
		$this->numErrors++;
		$this->errors[] = strip_tags((string) $str);
		return false;
	}
	public function warn($str) {
		$this->messages[] = 'WARN: ' . strip_tags((string) $str);
	}
	public function ok($str, $icon = 'check') {
		$this->messages[] = strip_tags((string) $str);
	}
	public function icon($name, $fw = true) { return ''; }
	protected function iconize($label, $icon = '') { return strip_tags((string) $label); }
	public function btn($label, array $options = []) {}
	public function btnContinue(array $options = []) {}
	public function h($label, $icon = '') {}
	public function p($text, $class = '') {}
	public function input($name, $label, $value, array $options = []) {}
	public function select($name, $label, $value, array $options, $width = 150) {}
	protected function selectTimezone($value) { return ''; }
	public function textarea($name, $label, $value, $rows = 0) {}
	public function sectionStart($headline = '', $type = 'muted') {}
	public function sectionStop() {}
	public function clear() {}

	// ── Reset-specific overrides ──────────────────────────────────────────

	/**
	 * getRemoveableItems — disabled for reset context.
	 *
	 * The real installer uses this to delete install.php, site/install/, and
	 * other one-time-use files after a fresh install. During a reset those
	 * files must be kept: they're the profile that the reset just consumed
	 * and may be used again for subsequent resets.
	 */
	protected function getRemoveableItems($getMarkup = false, $removeNow = false) {
		return $getMarkup ? '' : [];
	}

	/**
	 * finish — path-corrected port of the original.
	 *
	 * The upstream version uses __DIR__ which resolves to our vendor/ folder
	 * when the file is loaded from there. We use $config->paths->root instead
	 * so site/install/finish.php (e.g. from site-default) is found correctly.
	 */
	protected function finish($wire, $user) {
		$file = $wire->wire('config')->paths->root . 'site/install/finish.php';
		if(is_file($file)) {
			$fuel = array_merge($wire->wire('all')->getArray(), ['user' => $user]);
			$installer = $this;
			if($installer) {} // suppress "unused variable" notices
			extract($fuel);
			include($file);
		}
	}

	/**
	 * profileImportSQL — require_once fix for live-PW context.
	 *
	 * The original does plain require("./wire/core/WireDatabaseBackup.php").
	 * Inside a running ProcessWire instance WireDatabaseBackup is already
	 * loaded, so a bare require() triggers "Cannot redeclare class". We guard
	 * with class_exists() and use an absolute path via $config->paths->wire.
	 * The rest of the method is identical to the upstream version.
	 */
	protected function profileImportSQL($database, $file1, $file2, array $options = []) {
		$defaults = [
			'dbEngine' => 'InnoDB',
			'dbCharset' => 'utf8mb4',
		];
		$options = array_merge($defaults, $options);
		if(self::TEST_MODE) return;

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
		if(count($replace)) $restoreOptions['findReplaceCreateTable'] = $replace;

		// Fix: use require_once + absolute path (plain require() would cause
		// "Cannot redeclare class WireDatabaseBackup" in a live PW instance)
		if(!class_exists('\\ProcessWire\\WireDatabaseBackup', false)) {
			require_once wire('config')->paths->wire . 'core/WireDatabaseBackup.php';
		}
		$backup = new WireDatabaseBackup();
		$backup->setDatabase($database);
		if($backup->restoreMerge($file1, $file2, $restoreOptions)) {
			$this->ok("Imported database file: $file1");
			$this->ok("Imported database file: $file2");
		} else {
			foreach($backup->errors() as $error) $this->alertErr($error);
		}
	}
}
