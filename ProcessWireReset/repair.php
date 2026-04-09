<?php
/**
 * ProcessWireReset Repair Script
 *
 * Fixes a broken PW installation after a failed reset by restoring
 * essential data that the core install.sql provides but was lost
 * during sequential import.
 *
 * Upload this file to your PW root directory and open it in the browser.
 * DELETE THIS FILE AFTER USE.
 */

namespace ProcessWire;

// Load PW config to get DB credentials
$configFile = __DIR__ . '/site/config.php';
if (!file_exists($configFile)) {
    die("Error: site/config.php not found. Place this file in your ProcessWire root directory.");
}

// Define PROCESSWIRE constant that config.php requires
define('PROCESSWIRE', true);

// We need to parse the config file to get DB credentials
// Create a minimal Config object
$config = new \stdClass();
$config->dbHost = '';
$config->dbName = '';
$config->dbUser = '';
$config->dbPass = '';
$config->dbPort = '3306';
$config->dbCharset = 'utf8';
$config->useFunctionsAPI = false;
$config->usePageClasses = false;
$config->useMarkupRegions = false;
$config->prependTemplateFile = '';
$config->appendTemplateFile = '';
$config->templateCompile = false;
$config->debug = false;
$config->dbEngine = 'InnoDB';

// Include config to get DB settings
include $configFile;

if (empty($config->dbName) || empty($config->dbUser)) {
    die("Error: Could not read database credentials from site/config.php");
}

// Connect to database
try {
    $dsn = "mysql:host={$config->dbHost};port={$config->dbPort};dbname={$config->dbName};charset={$config->dbCharset}";
    $pdo = new \PDO($dsn, $config->dbUser, $config->dbPass, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
    ]);
} catch (\PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$results = [];

// --- Fix 1: Restore field_permissions ---
try {
    // Check if field_permissions is empty
    $count = $pdo->query("SELECT COUNT(*) FROM field_permissions")->fetchColumn();
    if ((int) $count === 0) {
        // Guest role (37) gets page-view (36)
        $pdo->exec("INSERT INTO field_permissions (pages_id, data, sort) VALUES (37, 36, 0)");
        // Superuser role (38) gets all default permissions
        $pdo->exec("INSERT INTO field_permissions (pages_id, data, sort) VALUES (38, 32, 1)");
        $pdo->exec("INSERT INTO field_permissions (pages_id, data, sort) VALUES (38, 34, 2)");
        $pdo->exec("INSERT INTO field_permissions (pages_id, data, sort) VALUES (38, 35, 3)");
        $pdo->exec("INSERT INTO field_permissions (pages_id, data, sort) VALUES (38, 36, 0)");
        $pdo->exec("INSERT INTO field_permissions (pages_id, data, sort) VALUES (38, 50, 4)");
        $pdo->exec("INSERT INTO field_permissions (pages_id, data, sort) VALUES (38, 51, 5)");
        $pdo->exec("INSERT INTO field_permissions (pages_id, data, sort) VALUES (38, 52, 7)");
        $pdo->exec("INSERT INTO field_permissions (pages_id, data, sort) VALUES (38, 53, 8)");
        $pdo->exec("INSERT INTO field_permissions (pages_id, data, sort) VALUES (38, 54, 6)");
        $results[] = "FIXED: field_permissions restored (guest role page-view + superuser permissions)";
    } else {
        $results[] = "OK: field_permissions already has data ($count rows)";
    }
} catch (\Exception $e) {
    $results[] = "ERROR field_permissions: " . $e->getMessage();
}

// --- Fix 2: Restore field_roles ---
try {
    $count = $pdo->query("SELECT COUNT(*) FROM field_roles")->fetchColumn();
    if ((int) $count === 0) {
        // Superuser (41) gets superuser role (38)
        $pdo->exec("INSERT INTO field_roles (pages_id, data, sort) VALUES (41, 38, 0)");
        // Guest (40) gets guest role (37)
        $pdo->exec("INSERT INTO field_roles (pages_id, data, sort) VALUES (40, 37, 0)");
        $results[] = "FIXED: field_roles restored (superuser + guest role assignments)";
    } else {
        $results[] = "OK: field_roles already has data ($count rows)";
    }
} catch (\Exception $e) {
    $results[] = "ERROR field_roles: " . $e->getMessage();
}

// --- Fix 3: Restore field_pass (empty defaults if missing) ---
try {
    $count = $pdo->query("SELECT COUNT(*) FROM field_pass")->fetchColumn();
    if ((int) $count === 0) {
        // Insert empty password entries (user will need to use forgot password or DB update)
        $pdo->exec("INSERT INTO field_pass (pages_id, data, salt) VALUES (41, '', '')");
        $pdo->exec("INSERT INTO field_pass (pages_id, data, salt) VALUES (40, '', '')");
        $results[] = "WARNING: field_pass was empty - inserted empty entries. You may need to reset your password via database.";
    } else {
        $results[] = "OK: field_pass already has data ($count rows)";
    }
} catch (\Exception $e) {
    $results[] = "ERROR field_pass: " . $e->getMessage();
}

// --- Fix 4: Restore field_email (empty defaults if missing) ---
try {
    $count = $pdo->query("SELECT COUNT(*) FROM field_email")->fetchColumn();
    if ((int) $count === 0) {
        $pdo->exec("INSERT INTO field_email (pages_id, data) VALUES (41, '')");
        $results[] = "FIXED: field_email restored (empty default for admin user)";
    } else {
        $results[] = "OK: field_email already has data ($count rows)";
    }
} catch (\Exception $e) {
    $results[] = "ERROR field_email: " . $e->getMessage();
}

// --- Fix 5: Verify essential templates exist ---
try {
    $templates = $pdo->query("SELECT id, name FROM templates ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
    $templateNames = array_column($templates, 'name');
    $required = ['admin', 'user', 'role', 'permission'];
    $missing = array_diff($required, $templateNames);
    if (count($missing) > 0) {
        $results[] = "WARNING: Missing templates: " . implode(', ', $missing) . " - you may need to re-import install.sql";
    } else {
        $results[] = "OK: Essential templates exist (" . implode(', ', $templateNames) . ")";
    }
} catch (\Exception $e) {
    $results[] = "ERROR templates check: " . $e->getMessage();
}

// --- Fix 6: Verify home page exists ---
try {
    $home = $pdo->query("SELECT id, name, templates_id FROM pages WHERE id = 1")->fetch(\PDO::FETCH_ASSOC);
    if ($home) {
        // Check if the template exists
        $tmpl = $pdo->prepare("SELECT id, name FROM templates WHERE id = ?");
        $tmpl->execute([$home['templates_id']]);
        $tmplData = $tmpl->fetch(\PDO::FETCH_ASSOC);
        if ($tmplData) {
            $results[] = "OK: Home page exists (template: {$tmplData['name']})";
        } else {
            $results[] = "WARNING: Home page exists but its template (id={$home['templates_id']}) is missing!";
        }
    } else {
        $results[] = "ERROR: Home page (id=1) does not exist!";
    }
} catch (\Exception $e) {
    $results[] = "ERROR home page check: " . $e->getMessage();
}

// --- Output results ---
echo "<!DOCTYPE html><html><head><title>ProcessWireReset Repair</title>";
echo "<style>body{font-family:monospace;padding:20px;background:#1a1a1a;color:#eee}";
echo ".ok{color:#4a4}.fixed{color:#fa0}.warning{color:#f80}.error{color:#f44}";
echo "h1{color:#fff}p{margin:5px 0;padding:5px;background:#222;border-radius:3px}</style></head><body>";
echo "<h1>ProcessWireReset Repair Results</h1>";

foreach ($results as $result) {
    $class = 'ok';
    if (strpos($result, 'FIXED') === 0) $class = 'fixed';
    if (strpos($result, 'WARNING') === 0) $class = 'warning';
    if (strpos($result, 'ERROR') === 0) $class = 'error';
    echo "<p class='$class'>$result</p>";
}

echo "<hr><p><strong>DELETE THIS FILE NOW:</strong> rm " . __FILE__ . "</p>";
echo "<p><a href='/' style='color:#4af'>Try loading your site →</a></p>";
echo "</body></html>";
