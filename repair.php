<?php namespace ProcessWire;

/**
 * ProcessWireReset — repair.php
 *
 * Stand-alone recovery endpoint. Performs a clean default install
 * (bundled site-blank profile, no keepModules) using the original
 * superuser credentials captured before the failed reset.
 *
 * Triggered exclusively by the recovery URL shown in the reset
 * confirmation modal. Without a matching token in `recovery.state.php`
 * any access is rejected with 403 (no information leaked).
 *
 * Runs WITHOUT a working ProcessWire bootstrap — uses raw PDO and a
 * stubbed config object. Only requires that
 *   - `site/config.php` is intact (DB credentials)
 *   - `wire/core/WireDatabaseBackup.php` is intact (SQL importer)
 *   - `wire/core/install.sql` and the bundled profile install.sql exist
 */

// ─── Stub config object ──────────────────────────────────────────────────
// PW's config.php sets `$config->dbHost = ...` etc. on whatever object is
// in scope. Magic accessors absorb any property the config touches without
// us having to mirror PW's full Config class.
class RepairConfigStub {
	private $data = [
		'dbPort'     => 3306,
		'dbCharset'  => 'utf8',
		'dbEngine'   => 'InnoDB',
		'dbSocket'   => '',
		'tableSalt'  => '',
		'urls'       => null,
		'paths'      => null,
		'chmodDir'   => '0755',
		'chmodFile'  => '0644',
	];
	public function __get($k)         { return $this->data[$k] ?? null; }
	public function __set($k, $v)     { $this->data[$k] = $v; }
	public function __isset($k)       { return isset($this->data[$k]); }
}

// ─── Constants ───────────────────────────────────────────────────────────
const RECOVERY_STATE_FILE = 'recovery.state.php';
const PENDING_FILE        = '.pending-installs.json';
const PENDING_TABLES_FILE = '.pending-custom-tables.bin';
const STATE_MARKER        = '==RECOVERY-STATE==';

// ─── Bootstrap paths ─────────────────────────────────────────────────────
$moduleDir = __DIR__;
$siteDir   = realpath($moduleDir . '/../../');           // …/site/
$pwRoot    = $siteDir ? realpath($siteDir . '/../') : ''; // …/<pw-root>/
$configPhp = $siteDir . '/config.php';
$wireDir   = $pwRoot ? $pwRoot . '/wire' : '';

// ─── Render helpers ──────────────────────────────────────────────────────
function repair_response($status, $title, $body, $loginUrl = '') {
	http_response_code($status);
	header('Content-Type: text/html; charset=utf-8');
	header('X-Robots-Tag: noindex, nofollow');
	$h     = function($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
	$login = $loginUrl ? '<p><a href="' . $h($loginUrl) . '">Continue to login →</a></p>' : '';
	echo '<!doctype html><html><head><meta charset="utf-8"><title>' . $h($title)
		. '</title><meta name="viewport" content="width=device-width,initial-scale=1">'
		. '<style>body{font:14px/1.5 system-ui,sans-serif;max-width:640px;margin:3em auto;padding:0 1em;color:#333}'
		. 'h1{font-size:1.4em} pre{background:#f4f4f4;padding:1em;overflow:auto;font-size:12px}'
		. '.ok{color:#1a7f37} .err{color:#c00}</style></head><body>'
		. '<h1>' . $h($title) . '</h1>' . $body . $login . '</body></html>';
	exit;
}

function repair_fail($msg, $code = 403) {
	$h = function($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
	repair_response($code, 'Recovery unavailable', '<p class="err">' . $h($msg) . '</p>');
}

// ─── 1. Token & state file ───────────────────────────────────────────────
$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
if(!preg_match('/^[a-f0-9]{40,128}$/', $token)) {
	repair_fail('Invalid or missing recovery token.');
}

$statePath = $moduleDir . '/' . RECOVERY_STATE_FILE;
if(!is_file($statePath)) {
	repair_fail('No recovery state available. Either no reset is in progress, or recovery already completed.');
}

$raw = @file_get_contents($statePath);
if($raw === false) repair_fail('Recovery state unreadable.', 500);

$parts = explode(STATE_MARKER, $raw);
if(count($parts) < 3) repair_fail('Recovery state malformed.', 500);

$payload = @json_decode((string) base64_decode(trim($parts[1]), true), true);
if(!is_array($payload) || empty($payload['token_hash']) || empty($payload['superuser'])) {
	repair_fail('Recovery state malformed.', 500);
}

if(!password_verify($token, (string) $payload['token_hash'])) {
	// Constant-ish delay against rapid brute-force on the bcrypt hash.
	usleep(500000);
	repair_fail('Token mismatch.');
}

if(isset($payload['expires_at']) && time() > (int) $payload['expires_at']) {
	@unlink($statePath);
	repair_fail('Recovery token expired. Restore the database from backup or re-install manually.');
}

$superuser  = (array) $payload['superuser'];
$coreSQL    = (string) ($payload['core_sql']    ?? '');
$profileSQL = (string) ($payload['profile_sql'] ?? '');

if(!is_file($coreSQL))    repair_fail('Core install.sql missing: ' . $coreSQL, 500);
if(!is_file($profileSQL)) repair_fail('Profile install.sql missing: ' . $profileSQL, 500);

// ─── 2. Load config & connect ────────────────────────────────────────────
if(!is_file($configPhp)) repair_fail('site/config.php not found at ' . $configPhp, 500);

$config = new RepairConfigStub();
require $configPhp;

if(empty($config->dbHost) || empty($config->dbName) || empty($config->dbUser)) {
	repair_fail('Database credentials missing in config.php', 500);
}

$dsn = 'mysql:host=' . $config->dbHost . ';dbname=' . $config->dbName
	. ';port=' . (int) ($config->dbPort ?: 3306)
	. ';charset=' . ($config->dbCharset ?: 'utf8');
try {
	$pdo = new \PDO($dsn, (string) $config->dbUser, (string) $config->dbPass, [
		\PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
		\PDO::ATTR_EMULATE_PREPARES   => false,
		\PDO::MYSQL_ATTR_FOUND_ROWS   => true,
	]);
} catch(\PDOException $e) {
	repair_fail('DB connect failed: ' . $e->getMessage(), 500);
}

// ─── 3. Drop all tables (multi-pass for FKs) ─────────────────────────────
function repair_drop_all(\PDO $pdo, $dbName) {
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
	for($pass = 0; $pass < 5; $pass++) {
		$stmt = $pdo->prepare(
			"SELECT TABLE_NAME FROM information_schema.TABLES
			 WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = 'BASE TABLE'"
		);
		$stmt->execute([':db' => $dbName]);
		$tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
		if(empty($tables)) break;
		foreach($tables as $t) {
			$safe = preg_replace('/[^a-zA-Z0-9_]/', '', $t);
			if($safe === '') continue;
			try { $pdo->exec("DROP TABLE IF EXISTS `$safe`"); }
			catch(\PDOException $e) { /* retry next pass */ }
		}
	}
	try {
		$stmt = $pdo->prepare(
			"SELECT TABLE_NAME FROM information_schema.TABLES
			 WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = 'VIEW'"
		);
		$stmt->execute([':db' => $dbName]);
		foreach($stmt->fetchAll(\PDO::FETCH_COLUMN) as $v) {
			$safe = preg_replace('/[^a-zA-Z0-9_]/', '', $v);
			if($safe !== '') $pdo->exec("DROP VIEW IF EXISTS `$safe`");
		}
	} catch(\PDOException $e) { /* views optional */ }
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

	$stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db");
	$stmt->execute([':db' => $dbName]);
	return (int) $stmt->fetchColumn();
}

try {
	$remaining = repair_drop_all($pdo, $config->dbName);
	if($remaining > 0) repair_fail("Could not drop all tables ($remaining remain). Aborting.", 500);
} catch(\PDOException $e) {
	repair_fail('DROP TABLES failed: ' . $e->getMessage(), 500);
}

// ─── 4. Import core + profile install.sql via WireDatabaseBackup ─────────
$wdbFile = $wireDir . '/core/WireDatabaseBackup.php';
if(!is_file($wdbFile)) repair_fail('wire/core/WireDatabaseBackup.php not found.', 500);

if(!class_exists('\\ProcessWire\\WireDatabaseBackup', false)) {
	require_once $wdbFile;
}

$replace = [
	'ENGINE=InnoDB'         => 'ENGINE=' . ($config->dbEngine ?: 'InnoDB'),
	'ENGINE=MyISAM'         => 'ENGINE=' . ($config->dbEngine ?: 'InnoDB'),
	'CHARSET=utf8mb4;'      => 'CHARSET=' . ($config->dbCharset ?: 'utf8') . ';',
	'CHARSET=utf8;'         => 'CHARSET=' . ($config->dbCharset ?: 'utf8') . ';',
	'CHARSET=utf8 COLLATE=' => 'CHARSET=' . ($config->dbCharset ?: 'utf8') . ' COLLATE=',
];
if(strtolower((string) $config->dbCharset) === 'utf8mb4') {
	if(strtolower((string) $config->dbEngine) === 'innodb') {
		$replace['(255)'] = '(191)';
		$replace['(250)'] = '(191)';
	} else {
		$replace['(255)'] = '(250)';
	}
}

try {
	$backup = new WireDatabaseBackup();
	$backup->setDatabase($pdo);
	if(!$backup->restoreMerge($coreSQL, $profileSQL, ['findReplaceCreateTable' => $replace])) {
		$errs = method_exists($backup, 'errors') ? implode('; ', (array) $backup->errors()) : 'unknown';
		repair_fail('SQL import failed: ' . $errs, 500);
	}
} catch(\Throwable $e) {
	repair_fail('SQL import threw: ' . $e->getMessage(), 500);
}

// ─── 5. Restore superuser ────────────────────────────────────────────────
try {
	$userId  = (int) ($superuser['id'] ?? 41);
	$adminId = 2; // PW admin root page id is fixed in install.sql

	if(!empty($superuser['pass_schema'])) {
		$pdo->exec('DROP TABLE IF EXISTS `field_pass`');
		$pdo->exec((string) $superuser['pass_schema']);
		// Seed guest user row (pages_id=40) provided by core install.sql
		$pdo->exec("INSERT INTO field_pass (pages_id, data, salt) VALUES (40, '', '')");
	}
	if(!empty($superuser['email_schema'])) {
		$pdo->exec('DROP TABLE IF EXISTS `field_email`');
		$pdo->exec((string) $superuser['email_schema']);
	}

	$pdo->prepare('UPDATE pages SET name = :name WHERE id = :id')
		->execute([':name' => (string) $superuser['name'], ':id' => $userId]);

	$pdo->prepare('UPDATE pages SET name = :name WHERE id = :id')
		->execute([':name' => (string) ($superuser['admin_name'] ?: 'processwire'), ':id' => $adminId]);

	if(!empty($superuser['pass_data'])) {
		$pdo->prepare(
			'INSERT INTO field_pass (pages_id, data, salt)
			 VALUES (:id, :data, :salt)
			 ON DUPLICATE KEY UPDATE data = VALUES(data), salt = VALUES(salt)'
		)->execute([
			':id'   => $userId,
			':data' => (string) $superuser['pass_data'],
			':salt' => (string) $superuser['pass_salt'],
		]);
	}
	if(!empty($superuser['email'])) {
		$pdo->prepare(
			'INSERT INTO field_email (pages_id, data)
			 VALUES (:id, :data)
			 ON DUPLICATE KEY UPDATE data = VALUES(data)'
		)->execute([':id' => $userId, ':data' => (string) $superuser['email']]);
	}
	try {
		$pdo->prepare(
			'INSERT INTO field_admin_theme (pages_id, data)
			 VALUES (:id, :data)
			 ON DUPLICATE KEY UPDATE data = VALUES(data)'
		)->execute([
			':id'   => $userId,
			':data' => (string) ($superuser['admin_theme'] ?: 'AdminThemeUikit'),
		]);
	} catch(\PDOException $e) { /* field_admin_theme may not exist */ }

	// Clear stale login throttle from before the crash
	try { $pdo->exec('DELETE FROM session_login_throttle'); }
	catch(\PDOException $e) { /* table may not exist */ }
} catch(\PDOException $e) {
	repair_fail('Superuser restore failed: ' . $e->getMessage(), 500);
}

// ─── 6. Cleanup state + pending files ────────────────────────────────────
@unlink($statePath);
@unlink($moduleDir . '/' . PENDING_FILE);
@unlink($moduleDir . '/' . PENDING_TABLES_FILE);

// ─── 7. Success ──────────────────────────────────────────────────────────
$adminUrl = '/processwire/'; // best-effort default; admin page name was restored above
if(!empty($superuser['admin_name'])) {
	$adminUrl = '/' . trim((string) $superuser['admin_name'], '/') . '/';
}

repair_response(
	200,
	'Recovery complete',
	'<p class="ok">A clean default install was performed using your original superuser credentials.</p>'
	. '<p>Custom modules, templates and fields were <strong>not</strong> restored. '
	. 'If a specific module caused the original crash, you can re-install it manually after logging in.</p>',
	$adminUrl
);
