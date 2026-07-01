<?php namespace ProcessWire;

// Load the upstream Installer class verbatim from vendor/Installer.php.
// Only lines 1-2117 (the class body) are kept — the procedural self-execution
// at the bottom is stripped so including the file is safe inside a live PW request.
if(!class_exists('\\ProcessWire\\Installer', false)) {
	require_once __DIR__ . '/vendor/Installer.php';
}

/**
 * InstallerCore — headless subclass of ProcessWire's own Installer.
 *
 * Overrides only the parts that cannot work inside a live PW instance:
 *
 *  Output / UI methods  — silenced; errors collected in $this->errors[].
 *  profileImportSQL()   — require_once + absolute path (plain require() causes
 *                         "Cannot redeclare class WireDatabaseBackup" in live PW).
 *  getRemoveableItems() — disabled (must not delete install.php / site/install/
 *                         during a reset; those files are the profile).
 *  finish()             — path-corrected (__DIR__ would resolve to vendor/).
 *
 * All other logic — including the DB-import mechanics of profileImportSQL() —
 * runs verbatim from the upstream class.
 */
class InstallerCore extends Installer {

	// Re-declared public so executeReset() can read/write them.
	// Parent declares all four as protected; PHP allows widening visibility
	// in a subclass (protected → public is valid).
	public $chmodDir   = '0755';
	public $chmodFile  = '0644';
	public $numErrors  = 0;
	public $inSection  = false;

	// Not declared by the parent Installer; declared here so executeReset() can
	// set them without triggering PHP 8.2 "dynamic property" deprecations.
	public $dbEngine   = 'InnoDB';
	public $dbCharset  = 'utf8mb4';

	/** @var string[] Messages collected during import (replaces HTML output) */
	public $messages = [];

	/** @var string[] Error messages collected during import */
	public $errors = [];

	// ── Output / UI methods — all silenced ───────────────────────────────────

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

	// ── Reset-specific overrides ──────────────────────────────────────────────

	/**
	 * getRemoveableItems — disabled for reset context.
	 *
	 * The real installer uses this to delete install.php and site/install/ after
	 * a fresh install. During a reset those files are the profile that was just
	 * consumed and may be needed again for subsequent resets.
	 */
	public function getRemoveableItems($getMarkup = false, $removeNow = false) {
		return $getMarkup ? '' : [];
	}

	/**
	 * finish — path-corrected port of the original.
	 *
	 * The upstream finish() uses __DIR__ which resolves to our vendor/ folder.
	 * We use $config->paths->root so site/install/finish.php is found correctly.
	 */
	public function finish($wire, $user) {
		$file = $wire->wire('config')->paths->root . 'site/install/finish.php';
		if(is_file($file)) {
			$fuel = array_merge($wire->wire('all')->getArray(), ['user' => $user]);
			$installer = $this;
			if($installer) {} // suppress "unused variable" notice
			extract($fuel);
			include($file);
		}
	}

	/**
	 * profileImportSQL — public + require_once fix for live-PW context.
	 *
	 * The original is protected and does `require("./wire/core/WireDatabaseBackup.php")`.
	 * Inside a running PW instance WireDatabaseBackup is already loaded, so a bare
	 * require() triggers "Cannot redeclare class". We guard with class_exists() and
	 * use an absolute path via $config->paths->wire. Everything else is identical.
	 */
	public function profileImportSQL($database, $file1, $file2, array $options = []) {
		$defaults = [
			'dbEngine'  => 'InnoDB',
			'dbCharset' => 'utf8mb4',
		];
		$options = array_merge($defaults, $options);
		if(self::TEST_MODE) return;

		$restoreOptions = [];
		$replace = [];
		$replace['ENGINE=InnoDB']        = "ENGINE={$options['dbEngine']}";
		$replace['ENGINE=MyISAM']        = "ENGINE={$options['dbEngine']}";
		$replace['CHARSET=utf8mb4;']     = "CHARSET={$options['dbCharset']};";
		$replace['CHARSET=utf8;']        = "CHARSET={$options['dbCharset']};";
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

		// Load WireDatabaseBackup. Stable ProcessWire keeps it at
		// wire/core/WireDatabaseBackup.php; the dev branch moved it to
		// wire/core/WireDatabase/WireDatabaseBackup.php. Prefer PW's autoloader,
		// then fall back to a manual require across both known locations.
		if(!class_exists('\\ProcessWire\\WireDatabaseBackup')) {
			$coreDir = wire('config')->paths->wire . 'core/';
			foreach(['WireDatabaseBackup.php', 'WireDatabase/WireDatabaseBackup.php'] as $rel) {
				if(is_file($coreDir . $rel)) { require_once $coreDir . $rel; break; }
			}
		}
		if(!class_exists('\\ProcessWire\\WireDatabaseBackup', false)) {
			$this->alertErr('WireDatabaseBackup class not found in ProcessWire core.');
			return;
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
