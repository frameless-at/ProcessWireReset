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

// Recovery is a destructive last-resort tool — surface every error.
// Otherwise a silent fatal during DROP TABLES / SQL import / restore
// leaves the user staring at a blank page with no clue what went wrong.
error_reporting(E_ALL);
ini_set('display_errors',         '1');
ini_set('display_startup_errors', '1');
@set_time_limit(300);
@ini_set('memory_limit', '512M');

// Shutdown trap so a fatal that escapes the try/catch blocks below
// still produces a visible error message instead of an empty 200.
register_shutdown_function(function() {
	$err = error_get_last();
	if(!$err) return;
	$fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
	if(!in_array($err['type'], $fatalTypes, true)) return;
	if(!headers_sent()) {
		http_response_code(500);
		header('Content-Type: text/plain; charset=utf-8');
	}
	echo "\n\n--- repair.php fatal ---\n"
		. "type:    " . $err['type'] . "\n"
		. "message: " . $err['message'] . "\n"
		. "file:    " . $err['file'] . "\n"
		. "line:    " . $err['line'] . "\n";
});

// (Previously used a magic-accessor stub object + require config.php.
//  That blew up on hosts where config.php pulls in further files or
//  hits a non-catchable fatal. We now lift the DB credentials with a
//  regex over file_get_contents() — see step 2 below.)

// ─── Constants ───────────────────────────────────────────────────────────
const RECOVERY_STATE_FILE = 'recovery.state.php';
const PENDING_FILE        = '.pending-installs.json';
const PENDING_TABLES_FILE = '.pending-custom-tables.bin';
const STATE_MARKER        = '==RECOVERY-STATE==';

// ─── Bootstrap paths ─────────────────────────────────────────────────────
// repair.php may be installed in two places:
//   a) site/modules/ProcessWireReset/repair.php — the default
//   b) directly next to index.php in the PW root — fallback for hosters
//      that block .php under site/modules/ at the webserver level
// Find the PW root by walking up from __DIR__ until we see both
// site/config.php and wire/core/install.sql.
$pwRoot = null;
$probe  = realpath(__DIR__) ?: __DIR__;
for($i = 0; $i < 5; $i++) {
	if($probe && is_file($probe . '/site/config.php')
	          && is_file($probe . '/wire/core/install.sql')) {
		$pwRoot = $probe;
		break;
	}
	$parent = dirname($probe);
	if($parent === $probe) break;
	$probe = $parent;
}
if(!$pwRoot) {
	http_response_code(500);
	header('Content-Type: text/plain; charset=utf-8');
	echo "repair.php: could not locate ProcessWire root.\n"
		. "Place this file either in site/modules/ProcessWireReset/ or in the PW root next to index.php.";
	exit;
}

$siteDir   = $pwRoot . '/site';
$wireDir   = $pwRoot . '/wire';
$configPhp = $siteDir . '/config.php';
$moduleDir = $siteDir . '/modules/ProcessWireReset';

// ─── Render helpers ──────────────────────────────────────────────────────
function repair_response($status, $title, $body, $loginUrl = '') {
	$h     = function($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
	$login = $loginUrl ? '<p><a href="' . $h($loginUrl) . '">Continue to login →</a></p>' : '';
	if(!headers_sent()) {
		http_response_code($status);
		header('Content-Type: text/html; charset=utf-8');
		header('Cache-Control: no-store, no-cache, must-revalidate');
		header('Pragma: no-cache');
		header('X-Robots-Tag: noindex, nofollow');
		echo '<!doctype html><html><head><meta charset="utf-8"><title>' . $h($title)
			. '</title><meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<style>body{font:14px/1.5 system-ui,sans-serif;max-width:640px;margin:3em auto;padding:0 1em;color:#333}'
			. 'h1{font-size:1.4em} pre{background:#f4f4f4;padding:1em;overflow:auto;font-size:12px}'
			. 'table{border-collapse:collapse;width:100%} td,th{padding:.4em .6em;border-bottom:1px solid #eee;text-align:left}'
			. '.ok{color:#1a7f37} .err{color:#c00} .warn{color:#a07000}</style></head><body>'
			. '<h1>' . $h($title) . '</h1>' . $body . $login . '</body></html>';
	} else {
		// Streaming mode: heartbeat already opened the page. Append a
		// closing block instead of starting a new <html> wrapper.
		echo '<hr><h2>' . $h($title) . '</h2>' . $body . $login . '</body></html>';
	}
	exit;
}

function repair_fail($msg, $code = 403) {
	$h = function($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
	repair_response($code, 'Recovery unavailable', '<p class="err">' . $h($msg) . '</p>');
}

function repair_diagnose($statePath, $configPhp, $wireDir) {
	// repair.php sits in the document root and is reachable by anyone,
	// authenticated or not. The diagnostic must therefore leak nothing
	// useful for reconnaissance: no absolute paths, no PHP version, no
	// usernames, no existence-checks against internal PW files. Only
	// booleans, only when a recovery is actually in progress.
	$h   = function($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
	$row = function($k, $v) use ($h) {
		return '<tr><th>' . $h($k) . '</th><td>' . $v . '</td></tr>';
	};
	$yn = function($b) { return $b ? '<span class="ok">yes</span>' : '<span class="err">no</span>'; };

	$intro = '<p>Recovery endpoint reachable. Append <code>?token=YOUR_TOKEN</code> to invoke recovery.</p>';

	if(!is_file($statePath)) {
		// No active recovery — nothing to disclose.
		repair_response(200, 'Recovery endpoint',
			$intro . '<p>No recovery is currently in progress.</p>'
		);
	}

	// Active recovery — show booleans about the state file only. Anybody
	// hitting this page already knows a reset has happened (the
	// confirmation modal showed them the URL); confirming the abstract
	// shape of the state file does not give an attacker anything new.
	$rows = $row('Recovery in progress', $yn(true));

	$raw        = (string) @file_get_contents($statePath);
	$parts      = explode('==RECOVERY-STATE==', $raw);
	$wellFormed = count($parts) >= 3;
	$rows .= $row('State file format ok', $yn($wellFormed));

	if($wellFormed) {
		$payload = @json_decode((string) base64_decode(trim($parts[1]), true), true);
		$valid   = is_array($payload) && !empty($payload['token_hash']) && !empty($payload['superuser']);
		$rows .= $row('State payload decodes', $yn($valid));
		if($valid) {
			$exp     = (int) ($payload['expires_at'] ?? 0);
			$expired = $exp > 0 && time() > $exp;
			$rows .= $row('Token still valid', $expired
				? '<span class="err">no (expired)</span>'
				: '<span class="ok">yes</span>');
		}
	}

	repair_response(200, 'Recovery endpoint',
		$intro . '<table>' . $rows . '</table>'
	);
}

// ─── 1. Token & state file ───────────────────────────────────────────────
$token     = isset($_GET['token']) ? (string) $_GET['token'] : '';
$statePath = $moduleDir . '/' . RECOVERY_STATE_FILE;

// No token → diagnostic page (status 200) so the user can see exactly
// why a previous attempt failed without having to inspect the server.
if($token === '') {
	repair_diagnose($statePath, $configPhp, $wireDir);
}

// Stream a minimal heartbeat so a stalling step (DROP TABLES, SQL
// import) is visible instead of looking like "nothing happens".
// repair_response() at the end discards the buffer and writes the
// real success page. If a fatal kills us mid-stream the captured
// progress text + the shutdown trap above is what the user sees.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Accel-Buffering: no'); // disable nginx/proxy buffering
echo str_repeat(' ', 4096) . "\n"; // some buffers need a kick of >=4KB
echo "<!doctype html><html><head><meta charset=utf-8><title>Recovery in progress</title>"
   . "<style>body{font:13px/1.5 monospace;max-width:720px;margin:2em auto;padding:0 1em}"
   . ".ok{color:#1a7f37}.err{color:#c00}</style></head><body>"
   . "<p>repair.php starting…</p>";
while(ob_get_level() > 0) ob_end_flush();
@ob_implicit_flush(true);
@flush();
function repair_step($msg) {
	echo '<p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "</p>\n"
	   . str_repeat(' ', 256) . "\n";
	@flush();
}

repair_step('Validating token format…');
if(!preg_match('/^[a-f0-9]{40,128}$/', $token)) {
	repair_fail('Invalid recovery token format. Append the URL exactly as shown in the modal.');
}

repair_step('Locating recovery state file…');
if(!is_file($statePath)) {
	repair_fail('No recovery state available. Either no reset is in progress, or recovery already completed.');
}

repair_step('Reading state file…');
$raw = @file_get_contents($statePath);
if($raw === false) repair_fail('Recovery state unreadable.', 500);

$parts = explode(STATE_MARKER, $raw);
if(count($parts) < 3) repair_fail('Recovery state malformed.', 500);

$payload = @json_decode((string) base64_decode(trim($parts[1]), true), true);
if(!is_array($payload) || empty($payload['token_hash']) || empty($payload['superuser'])) {
	repair_fail('Recovery state malformed.', 500);
}

repair_step('Verifying token (bcrypt)…');
if(!password_verify($token, (string) $payload['token_hash'])) {
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

repair_step('Checking install.sql files (core: ' . basename($coreSQL) . ', profile: ' . basename($profileSQL) . ')…');
if(!is_file($coreSQL))    repair_fail('Core install.sql missing: ' . $coreSQL, 500);
if(!is_file($profileSQL)) repair_fail('Profile install.sql missing: ' . $profileSQL, 500);

// ─── 2. Load config & connect ────────────────────────────────────────────
repair_step('Loading config.php…');
if(!is_file($configPhp)) repair_fail('site/config.php not found at ' . $configPhp, 500);

// We do NOT require() config.php — some installations have config.php
// pull in further files, call into PW classes, or otherwise crash a
// stand-alone include in ways that bypass try/catch and the shutdown
// handler (FastCGI stream abort). Lift the DB credentials with a
// regex instead. Works for the (overwhelmingly common) case where
// they are plain string / int literals.
$configRaw = (string) @file_get_contents($configPhp);
if($configRaw === '') repair_fail('config.php is empty or unreadable', 500);

$cfgVal = function($key, $default = null) use ($configRaw) {
	$q = preg_quote($key, '/');
	if(preg_match('/\$config->' . $q . '\s*=\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*;/s', $configRaw, $m)) {
		return stripcslashes($m[2]);
	}
	if(preg_match('/\$config->' . $q . '\s*=\s*(\d+)\s*;/', $configRaw, $m)) {
		return (int) $m[1];
	}
	return $default;
};

$dbHost    = (string) $cfgVal('dbHost');
$dbName    = (string) $cfgVal('dbName');
$dbUser    = (string) $cfgVal('dbUser');
$dbPass    = (string) $cfgVal('dbPass', '');
$dbPort    = (int)    $cfgVal('dbPort', 3306);
$dbCharset = (string) $cfgVal('dbCharset', 'utf8');
$dbEngine  = (string) $cfgVal('dbEngine', 'InnoDB');

repair_step('Parsed credentials from config.php: ' . $dbUser . '@' . $dbHost . '/' . $dbName . ' (port ' . $dbPort . ', charset ' . $dbCharset . ', engine ' . $dbEngine . ')');

if($dbHost === '' || $dbName === '' || $dbUser === '') {
	repair_fail('Could not parse DB credentials out of config.php — they may be set conditionally or via constants. Open the file and confirm $config->dbHost / dbName / dbUser are simple literal assignments.', 500);
}

$dsn = 'mysql:host=' . $dbHost . ';dbname=' . $dbName
	. ';port=' . $dbPort
	. ';charset=' . $dbCharset;
repair_step('Connecting to database…');
try {
	$pdo = new \PDO($dsn, $dbUser, $dbPass, [
		\PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
		\PDO::ATTR_EMULATE_PREPARES   => false,
		\PDO::MYSQL_ATTR_FOUND_ROWS   => true,
	]);
} catch(\PDOException $e) {
	repair_fail('DB connect failed: ' . $e->getMessage(), 500);
}
repair_step('DB connection ok.');

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

repair_step('Dropping all tables…');
try {
	$remaining = repair_drop_all($pdo, $dbName);
	if($remaining > 0) repair_fail("Could not drop all tables ($remaining remain). Aborting.", 500);
} catch(\PDOException $e) {
	repair_fail('DROP TABLES failed: ' . $e->getMessage(), 500);
}
repair_step('All tables dropped.');

// ─── 4. Import core + profile install.sql via WireDatabaseBackup ─────────
$wdbFile = $wireDir . '/core/WireDatabaseBackup.php';
if(!is_file($wdbFile)) repair_fail('wire/core/WireDatabaseBackup.php not found.', 500);

if(!class_exists('\\ProcessWire\\WireDatabaseBackup', false)) {
	require_once $wdbFile;
}

$replace = [
	'ENGINE=InnoDB'         => 'ENGINE=' . ($dbEngine ?: 'InnoDB'),
	'ENGINE=MyISAM'         => 'ENGINE=' . ($dbEngine ?: 'InnoDB'),
	'CHARSET=utf8mb4;'      => 'CHARSET=' . ($dbCharset ?: 'utf8') . ';',
	'CHARSET=utf8;'         => 'CHARSET=' . ($dbCharset ?: 'utf8') . ';',
	'CHARSET=utf8 COLLATE=' => 'CHARSET=' . ($dbCharset ?: 'utf8') . ' COLLATE=',
];
if(strtolower($dbCharset) === 'utf8mb4') {
	if(strtolower($dbEngine) === 'innodb') {
		$replace['(255)'] = '(191)';
		$replace['(250)'] = '(191)';
	} else {
		$replace['(255)'] = '(250)';
	}
}

repair_step('Importing core install.sql + profile install.sql…');
try {
	$backup = new WireDatabaseBackup();
	$backup->setDatabase($pdo);
	if(!$backup->restoreMerge($coreSQL, $profileSQL, ['findReplaceCreateTable' => $replace])) {
		$errs = method_exists($backup, 'errors') ? implode('; ', (array) $backup->errors()) : 'unknown';
		repair_fail('SQL import failed: ' . $errs, 500);
	}
} catch(\Throwable $e) {
	repair_fail('SQL import threw: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), 500);
}
repair_step('SQL import complete.');
repair_step('Restoring superuser (' . ($superuser['name'] ?? '?') . ')…');

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
