<?php
/**
 * ProcessWireReset Repair Script
 *
 * Fixes a broken PW installation after a failed reset.
 *
 * MODES:
 *  - ?mode=check          Show status of the database (default)
 *  - ?mode=fix            Fix missing field_permissions / field_roles data
 *  - ?mode=password       Set a new password for the superuser (use with &pass=NEW&user=USERNAME)
 *
 * Upload this file to your PW root directory and open it in the browser.
 * DELETE THIS FILE AFTER USE.
 */

// Extract DB credentials + userAuthSalt from site/config.php via regex
$configFile = __DIR__ . '/site/config.php';
if (!file_exists($configFile)) {
    die("Error: site/config.php not found. Place this file in your ProcessWire root directory.");
}

$configContent = file_get_contents($configFile);

$dbHost = ''; $dbName = ''; $dbUser = ''; $dbPass = ''; $dbPort = '3306'; $dbCharset = 'utf8';
$userAuthSalt = '';

if (preg_match('/\$config\s*->\s*dbHost\s*=\s*[\'"](.+?)[\'"]\s*;/', $configContent, $m)) $dbHost = $m[1];
if (preg_match('/\$config\s*->\s*dbName\s*=\s*[\'"](.+?)[\'"]\s*;/', $configContent, $m)) $dbName = $m[1];
if (preg_match('/\$config\s*->\s*dbUser\s*=\s*[\'"](.+?)[\'"]\s*;/', $configContent, $m)) $dbUser = $m[1];
if (preg_match('/\$config\s*->\s*dbPass\s*=\s*[\'"](.+?)[\'"]\s*;/', $configContent, $m)) $dbPass = $m[1];
if (preg_match('/\$config\s*->\s*dbPort\s*=\s*[\'"]?(\d+)[\'"]?\s*;/', $configContent, $m)) $dbPort = $m[1];
if (preg_match('/\$config\s*->\s*dbCharset\s*=\s*[\'"](.+?)[\'"]\s*;/', $configContent, $m)) $dbCharset = $m[1];
if (preg_match('/\$config\s*->\s*userAuthSalt\s*=\s*[\'"](.+?)[\'"]\s*;/', $configContent, $m)) $userAuthSalt = $m[1];

if (empty($dbName) || empty($dbUser)) {
    die("Error: Could not parse database credentials from site/config.php");
}

// Connect
try {
    $dsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=$dbCharset";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$mode = isset($_GET['mode']) ? $_GET['mode'] : 'check';
$results = [];

// ════════════════════════════════════════════════════════════════════════
// MODE: password — set a new password for the superuser
// ════════════════════════════════════════════════════════════════════════
if ($mode === 'password') {
    $newPass = isset($_GET['pass']) ? $_GET['pass'] : '';
    $userName = isset($_GET['user']) ? $_GET['user'] : '';

    if (empty($newPass) || strlen($newPass) < 6) {
        $results[] = "ERROR: Provide &pass=YOUR_NEW_PASSWORD (min 6 chars) in the URL";
    } elseif (empty($userName)) {
        $results[] = "ERROR: Provide &user=YOUR_USERNAME in the URL";
    } elseif (empty($userAuthSalt)) {
        $results[] = "ERROR: Could not read userAuthSalt from site/config.php — cannot hash password";
    } else {
        try {
            // Find user by name (column in pages table)
            $stmt = $pdo->prepare("SELECT id FROM pages WHERE name = :name AND parent_id = 29");
            $stmt->execute([':name' => $userName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $results[] = "ERROR: User '$userName' not found";
            } else {
                $userId = (int) $row['id'];

                // Replicate PW's Password::hash algorithm
                // crypt($pass . $userAuthSalt, $blowfishSalt)
                // Stored: salt = first 29 chars, data = remaining 31 chars
                $bcryptSalt = '$2y$11$' . substr(strtr(base64_encode(random_bytes(16)), '+', '.'), 0, 22);
                $fullHash = crypt($newPass . $userAuthSalt, $bcryptSalt);

                if (strlen($fullHash) < 60) {
                    $results[] = "ERROR: Password hashing failed (crypt returned: " . strlen($fullHash) . " chars)";
                } else {
                    $passSalt = substr($fullHash, 0, 29);
                    $passData = substr($fullHash, 29);

                    // Check column sizes and expand if needed
                    $pdo->exec("ALTER TABLE field_pass MODIFY data VARCHAR(255) NOT NULL");
                    $pdo->exec("ALTER TABLE field_pass MODIFY salt VARCHAR(255) NOT NULL");

                    // Delete existing entry and insert fresh
                    $pdo->prepare("DELETE FROM field_pass WHERE pages_id = :id")->execute([':id' => $userId]);
                    $stmt = $pdo->prepare(
                        "INSERT INTO field_pass (pages_id, data, salt) VALUES (:id, :data, :salt)"
                    );
                    $stmt->execute([':id' => $userId, ':data' => $passData, ':salt' => $passSalt]);

                    $results[] = "FIXED: Password updated for user '$userName' (id=$userId)";
                    $results[] = "OK: You can now log in with the new password";
                }
            }
        } catch (Exception $e) {
            $results[] = "ERROR: " . $e->getMessage();
        }
    }
}

// ════════════════════════════════════════════════════════════════════════
// MODE: fix — restore missing field_permissions / field_roles data
// ════════════════════════════════════════════════════════════════════════
if ($mode === 'fix') {
    try {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM field_permissions")->fetchColumn();
        if ($count === 0) {
            $pdo->exec("INSERT INTO field_permissions (pages_id, data, sort) VALUES (37, 36, 0)");
            $pdo->exec("INSERT INTO field_permissions (pages_id, data, sort) VALUES (38, 32, 1)");
            $pdo->exec("INSERT INTO field_permissions (pages_id, data, sort) VALUES (38, 34, 2)");
            $pdo->exec("INSERT INTO field_permissions (pages_id, data, sort) VALUES (38, 35, 3)");
            $pdo->exec("INSERT INTO field_permissions (pages_id, data, sort) VALUES (38, 36, 0)");
            $pdo->exec("INSERT INTO field_permissions (pages_id, data, sort) VALUES (38, 50, 4)");
            $pdo->exec("INSERT INTO field_permissions (pages_id, data, sort) VALUES (38, 51, 5)");
            $pdo->exec("INSERT INTO field_permissions (pages_id, data, sort) VALUES (38, 52, 7)");
            $pdo->exec("INSERT INTO field_permissions (pages_id, data, sort) VALUES (38, 53, 8)");
            $pdo->exec("INSERT INTO field_permissions (pages_id, data, sort) VALUES (38, 54, 6)");
            $results[] = "FIXED: field_permissions restored";
        } else {
            $results[] = "OK: field_permissions has $count rows";
        }
    } catch (Exception $e) {
        $results[] = "ERROR field_permissions: " . $e->getMessage();
    }

    try {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM field_roles")->fetchColumn();
        if ($count === 0) {
            $pdo->exec("INSERT INTO field_roles (pages_id, data, sort) VALUES (40, 37, 0)");
            $pdo->exec("INSERT INTO field_roles (pages_id, data, sort) VALUES (41, 37, 0)");
            $pdo->exec("INSERT INTO field_roles (pages_id, data, sort) VALUES (41, 38, 2)");
            $results[] = "FIXED: field_roles restored";
        } else {
            $results[] = "OK: field_roles has $count rows";
        }
    } catch (Exception $e) {
        $results[] = "ERROR field_roles: " . $e->getMessage();
    }
}

// ════════════════════════════════════════════════════════════════════════
// MODE: check — report current state
// ════════════════════════════════════════════════════════════════════════
if ($mode === 'check' || !empty($results)) {
    try {
        // field_pass schema and data
        $createPass = $pdo->query("SHOW CREATE TABLE field_pass")->fetch(PDO::FETCH_NUM);
        $results[] = "INFO: field_pass schema → " . preg_replace('/\s+/', ' ', $createPass[1]);

        $stmt = $pdo->query("SELECT pages_id, LENGTH(data) AS dl, LENGTH(salt) AS sl, data, salt FROM field_pass");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = "INFO: field_pass[{$row['pages_id']}] data_len={$row['dl']} salt_len={$row['sl']}";
        }

        // User pages
        $stmt = $pdo->query("SELECT id, name, status FROM pages WHERE parent_id = 29");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = "INFO: user page id={$row['id']} name='{$row['name']}' status={$row['status']}";
        }

        // Row counts
        foreach (['field_permissions', 'field_roles', 'field_email', 'field_pass'] as $tbl) {
            $c = (int) $pdo->query("SELECT COUNT(*) FROM $tbl")->fetchColumn();
            $results[] = "INFO: $tbl has $c rows";
        }

        $results[] = "INFO: userAuthSalt " . (empty($userAuthSalt) ? "NOT FOUND in config.php" : "found (" . strlen($userAuthSalt) . " chars)");

    } catch (Exception $e) {
        $results[] = "ERROR check: " . $e->getMessage();
    }
}

// ════════════════════════════════════════════════════════════════════════
// Output
// ════════════════════════════════════════════════════════════════════════
echo "<!DOCTYPE html><html><head><title>ProcessWireReset Repair</title>";
echo "<style>body{font-family:monospace;padding:20px;background:#1a1a1a;color:#eee;font-size:13px}";
echo ".ok{color:#4a4}.info{color:#48f}.fixed{color:#fa0}.warning{color:#f80}.error{color:#f44}";
echo "h1{color:#fff}p{margin:3px 0;padding:5px;background:#222;border-radius:3px}";
echo "a{color:#4af} code{background:#333;padding:2px 6px;border-radius:2px}</style></head><body>";
echo "<h1>ProcessWireReset Repair — mode: $mode</h1>";

foreach ($results as $result) {
    $class = 'ok';
    if (strpos($result, 'FIXED') === 0) $class = 'fixed';
    if (strpos($result, 'WARNING') === 0) $class = 'warning';
    if (strpos($result, 'ERROR') === 0) $class = 'error';
    if (strpos($result, 'INFO') === 0) $class = 'info';
    echo "<p class='$class'>" . htmlspecialchars($result) . "</p>";
}

echo "<hr><h3>Available actions:</h3>";
echo "<p><a href='?mode=check'>Check status</a></p>";
echo "<p><a href='?mode=fix'>Fix missing permissions/roles data</a></p>";
echo "<p>Set new password: <code>?mode=password&user=YOUR_USERNAME&pass=NEW_PASSWORD</code></p>";
echo "<p><strong>DELETE THIS FILE NOW:</strong> <code>rm " . htmlspecialchars(__FILE__) . "</code></p>";
echo "</body></html>";
