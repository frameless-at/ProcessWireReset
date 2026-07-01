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
 *   - `wire/core/WireDatabaseBackup.php` is intact (SQL importer; dev builds
 *     keep it under wire/core/WireDatabase/)
 *   - the bundled core + profile install.sql exist (dev ships no
 *     wire/core/install.sql, so the module carries its own copy)
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
// site/config.php and the core bootstrap. We probe wire/core/ProcessWire.php
// (present in every layout) rather than wire/core/install.sql, which dev
// builds do not ship.
$pwRoot = null;
$probe  = realpath(__DIR__) ?: __DIR__;
for($i = 0; $i < 5; $i++) {
	if($probe && is_file($probe . '/site/config.php')
	          && is_file($probe . '/wire/core/ProcessWire.php')) {
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
// Two render paths:
//   * Pre-stream (no heartbeat yet, e.g. diagnostic page or token-format
//     reject): full standalone HTML page.
//   * Post-stream (heartbeat already opened steps list): close the list
//     and append a status banner so the same page reads as one document.
function repair_response($status, $title, $body, $loginUrl = '', $variant = 'ok') {
	$h     = function($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
	$login = $loginUrl ? '<p><a href="' . $h($loginUrl) . '">Continue to login &rarr;</a></p>' : '';
	$cls   = $variant === 'ok' ? 'banner ok' : 'banner err';
	if(!headers_sent()) {
		http_response_code($status);
		header('Content-Type: text/html; charset=utf-8');
		header('Cache-Control: no-store, no-cache, must-revalidate');
		header('Pragma: no-cache');
		header('X-Robots-Tag: noindex, nofollow');
		echo '<!doctype html><html lang=en><head><meta charset="utf-8">'
			. '<title>' . $h($title) . '</title>'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<style>'
			. '*{box-sizing:border-box}'
			. 'body{font:14px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;'
			. 'max-width:560px;margin:3em auto;padding:0 1.5em;color:#1f2937;background:#f7f8fa}'
			. 'h1{font-size:1.45rem;font-weight:600;margin:0 0 .25em;color:#111827}'
			. '.lede{color:#6b7280;margin:0 0 1.5em;font-size:.95rem}'
			. 'table{border-collapse:collapse;width:100%;background:#fff;border:1px solid #e5e7eb;'
			. 'border-radius:8px;overflow:hidden}'
			. 'th,td{padding:.6em .9em;border-bottom:1px solid #f3f4f6;text-align:left;font-size:.92rem}'
			. 'tr:last-child th,tr:last-child td{border-bottom:0}'
			. 'th{font-weight:500;color:#374151;width:60%}'
			. '.banner{margin-top:0;padding:1.1em 1.4em;border-radius:8px}'
			. '.banner h1{margin:0 0 .35em;font-size:1.15rem}'
			. '.banner.ok{background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46}'
			. '.banner.ok a{color:#065f46;font-weight:600}'
			. '.banner.err{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}'
			. '.ok{color:#065f46}.err{color:#991b1b}'
			. 'code{font:12.5px ui-monospace,Menlo,Consolas,monospace;background:#f3f4f6;'
			. 'padding:1px 5px;border-radius:3px}'
			. '</style></head><body>'
			. '<div class="' . $cls . '"><h1>' . $h($title) . '</h1>' . $body . $login . '</div>'
			. '</body></html>';
	} else {
		// Streaming mode — close the steps list and append the banner.
		echo '</ul>'
			. '<div class="' . $cls . '"><h2>' . $h($title) . '</h2>'
			. $body . $login . '</div></body></html>';
	}
	exit;
}

function repair_fail($msg, $code = 403) {
	$h = function($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
	repair_response($code, 'Recovery unavailable', '<p>' . $h($msg) . '</p>', '', 'err');
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

// ─── PW config.php parser ────────────────────────────────────────────────
// Walks the PHP token stream of config.php and lifts top-level scalar
// assignments to $config->KEY. More robust than regex: tolerates
// conditionals, heredoc/nowdoc strings, comments, multiple reassignments
// (last wins). Constants on the right-hand side are not resolved.
function repair_parse_pw_config($source) {
	$tokens = @token_get_all($source);
	if(!is_array($tokens)) return [];
	$out = [];
	$n   = count($tokens);
	for($i = 0; $i < $n; $i++) {
		$t = $tokens[$i];
		if(!is_array($t) || $t[0] !== T_VARIABLE || $t[1] !== '$config') continue;
		$j = $i + 1;
		while($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
		if($j >= $n || !is_array($tokens[$j]) || $tokens[$j][0] !== T_OBJECT_OPERATOR) continue;
		$j++;
		while($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
		if($j >= $n || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) continue;
		$key = $tokens[$j][1];
		$j++;
		while($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
		if($j >= $n || $tokens[$j] !== '=') continue;
		$j++;
		while($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
		if($j >= $n) continue;
		$v = $tokens[$j];
		if(!is_array($v)) continue;
		switch($v[0]) {
			case T_CONSTANT_ENCAPSED_STRING:
				// Strip surrounding quote and unescape
				$lit = $v[1];
				$quote = $lit[0] ?? '';
				if($quote === '"' || $quote === "'") {
					$inner = substr($lit, 1, -1);
					$out[$key] = $quote === "'"
						? str_replace(["\\'", "\\\\"], ["'", "\\"], $inner)
						: stripcslashes($inner);
				}
				break;
			case T_LNUMBER: $out[$key] = (int)   $v[1]; break;
			case T_DNUMBER: $out[$key] = (float) $v[1]; break;
			case T_STRING:
				$lc = strtolower($v[1]);
				if($lc === 'true')  $out[$key] = true;
				elseif($lc === 'false') $out[$key] = false;
				elseif($lc === 'null')  $out[$key] = null;
				break;
		}
	}
	return $out;
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
header('X-Accel-Buffering: no');                   // disable nginx/proxy buffering
echo str_repeat(' ', 4096) . "\n";                 // some buffers need a kick of >=4KB
echo "<!doctype html><html lang=en><head><meta charset=utf-8>"
   . "<title>ProcessWire Recovery</title>"
   . "<meta name=viewport content='width=device-width,initial-scale=1'>"
   . "<style>"
   . "*{box-sizing:border-box}"
   . "body{font:14px/1.55 system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;"
   . "max-width:560px;margin:3em auto;padding:0 1.5em;color:#1f2937;background:#f7f8fa}"
   . "h1{font-size:1.45rem;font-weight:600;margin:0 0 .25em;color:#111827}"
   . ".lede{color:#6b7280;margin:0 0 1.75em;font-size:.95rem}"
   . ".steps{background:#fff;border:1px solid #e5e7eb;border-radius:8px;"
   . "padding:.25em 1.1em;margin:0;list-style:none;box-shadow:0 1px 2px rgba(0,0,0,.03)}"
   . ".step{display:flex;align-items:center;gap:.7em;padding:.65em 0;"
   . "border-bottom:1px solid #f3f4f6;font-size:.95rem}"
   . ".step:last-child{border-bottom:0}"
   . ".step .ic{flex:0 0 18px;width:18px;height:18px;border-radius:50%;"
   . "display:inline-flex;align-items:center;justify-content:center;"
   . "font-size:11px;font-weight:700;color:#fff;background:#10b981}"
   . ".banner{margin-top:1.5em;padding:1.1em 1.4em;border-radius:8px;"
   . "box-shadow:0 1px 2px rgba(0,0,0,.04)}"
   . ".banner h2{margin:0 0 .35em;font-size:1.1rem;font-weight:600}"
   . ".banner p{margin:.25em 0}"
   . ".banner.ok{background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46}"
   . ".banner.ok a{color:#065f46;font-weight:600}"
   . ".banner.err{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}"
   . "code{font:12.5px ui-monospace,Menlo,Consolas,monospace;background:#f3f4f6;"
   . "padding:1px 5px;border-radius:3px}"
   . "</style></head><body>"
   . "<h1>ProcessWire Recovery</h1>"
   . "<p class=lede>Restoring the installation to a clean default state with the original superuser credentials.</p>"
   . "<ul class=steps>";
while(ob_get_level() > 0) ob_end_flush();
@ob_implicit_flush(true);
@flush();
function repair_step($msg) {
	echo '<li class="step"><span class="ic">&#10003;</span><span>'
	   . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "</span></li>\n"
	   . str_repeat(' ', 256) . "\n";
	@flush();
}

if(!preg_match('/^[a-f0-9]{40,128}$/', $token)) {
	repair_fail('Invalid recovery token format. Append the URL exactly as shown in the modal.');
}
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
	usleep(500000);
	repair_fail('Token mismatch.');
}
repair_step('Token verified');

if(isset($payload['expires_at']) && time() > (int) $payload['expires_at']) {
	@unlink($statePath);
	repair_fail('Recovery token expired. Restore the database from backup or re-install manually.');
}

$superuser  = (array) $payload['superuser'];
$coreSQL    = (string) ($payload['core_sql']    ?? '');
$profileSQL = (string) ($payload['profile_sql'] ?? '');

if(!is_file($coreSQL))    repair_fail('Core install.sql missing.', 500);
if(!is_file($profileSQL)) repair_fail('Profile install.sql missing.', 500);
repair_step('Install profiles located');

// ─── 2. Load config & connect ────────────────────────────────────────────
if(!is_file($configPhp)) repair_fail('config.php not found at the expected path.', 500);

// We do NOT require() config.php — some installations have config.php
// pull in further files, call into PW classes, or otherwise crash a
// stand-alone include in ways that bypass try/catch and the shutdown
// handler (FastCGI stream abort). The PHP tokenizer walks the file
// instead and lifts top-level scalar assignments to $config->*.
$configRaw = (string) @file_get_contents($configPhp);
if($configRaw === '') repair_fail('config.php is empty or unreadable', 500);

$cfgValues = repair_parse_pw_config($configRaw);

$dbHost    = (string) ($cfgValues['dbHost']    ?? '');
$dbName    = (string) ($cfgValues['dbName']    ?? '');
$dbUser    = (string) ($cfgValues['dbUser']    ?? '');
$dbPass    = (string) ($cfgValues['dbPass']    ?? '');
$dbPort    = (int)    ($cfgValues['dbPort']    ?? 3306);
$dbCharset = (string) ($cfgValues['dbCharset'] ?? 'utf8');
$dbEngine  = (string) ($cfgValues['dbEngine']  ?? 'InnoDB');

if($dbHost === '' || $dbName === '' || $dbUser === '') {
	repair_fail(
		'Could not extract DB credentials from config.php — they may be set '
		. 'conditionally or via constants. Verify that $config->dbHost / dbName / '
		. 'dbUser are simple literal assignments.',
		500
	);
}
repair_step('Database credentials read from config.php');

$dsn = 'mysql:host=' . $dbHost . ';dbname=' . $dbName
	. ';port=' . $dbPort
	. ';charset=' . $dbCharset;
try {
	$pdo = new \PDO($dsn, $dbUser, $dbPass, [
		\PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
		\PDO::ATTR_EMULATE_PREPARES   => false,
		\PDO::MYSQL_ATTR_FOUND_ROWS   => true,
	]);
} catch(\PDOException $e) {
	repair_fail('DB connect failed: ' . $e->getMessage(), 500);
}
repair_step('Database connected');

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
	$remaining = repair_drop_all($pdo, $dbName);
	if($remaining > 0) repair_fail("Could not drop all tables ($remaining remain). Aborting.", 500);
} catch(\PDOException $e) {
	repair_fail('DROP TABLES failed: ' . $e->getMessage(), 500);
}
repair_step('All tables dropped');

// ─── 4. Import core + profile install.sql via WireDatabaseBackup ─────────
// Stable ProcessWire keeps WireDatabaseBackup at wire/core/WireDatabaseBackup.php;
// the dev branch moved it to wire/core/WireDatabase/WireDatabaseBackup.php.
$wdbFile = '';
foreach(['/core/WireDatabaseBackup.php', '/core/WireDatabase/WireDatabaseBackup.php'] as $rel) {
	if(is_file($wireDir . $rel)) { $wdbFile = $wireDir . $rel; break; }
}
if($wdbFile === '') repair_fail('WireDatabaseBackup.php not found in wire/core.', 500);

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
repair_step('Fresh schema imported');

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
repair_step('Superuser restored');

// ─── 6. Cleanup state + pending files ────────────────────────────────────
@unlink($statePath);
@unlink($moduleDir . '/' . PENDING_FILE);
@unlink($moduleDir . '/' . PENDING_TABLES_FILE);
repair_step('Recovery state cleared');

// ─── 7. Success ──────────────────────────────────────────────────────────
$adminUrl = '/processwire/'; // best-effort default; admin page name was restored above
if(!empty($superuser['admin_name'])) {
	$adminUrl = '/' . trim((string) $superuser['admin_name'], '/') . '/';
}

repair_response(
	200,
	'Recovery complete',
	'<p>The installation is back to a clean default state and your original '
	. 'superuser credentials are valid again. Custom modules, templates and '
	. 'fields were not restored — re-install them from your sources after '
	. 'logging in.</p>',
	$adminUrl
);
