<?php namespace ProcessWire;

/**
 * CrashTest — manual test helper for ProcessWireReset's repair.php flow.
 *
 * DO NOT INSTALL IN PRODUCTION.
 *
 * Throws inside install(), which is exactly what processPendingInstalls()
 * runs at the end of a reset to restore kept modules. With this module
 * selected as "Keep Module", a reset will:
 *
 *   1. Wipe the database.
 *   2. Re-import core + profile install.sql.
 *   3. Restore the superuser.
 *   4. Redirect to the admin login.
 *   5. On the next request, processPendingInstalls() tries to re-install
 *      this module and throws → cleanupRecoveryState() is NOT reached →
 *      recovery.state.php stays on disk → the recovery URL captured
 *      from the confirmation modal works against repair.php.
 *
 * Usage:
 *   1. Copy the parent directory (`tests/CrashTest/`) into
 *      `site/modules/CrashTest/`.
 *   2. Modules → Refresh → install "Crash Test".
 *   3. ProcessWire Reset → Configure → tick CrashTest under
 *      "Modules to keep" → trigger the reset.
 *   4. Copy the recovery URL shown in the confirmation modal.
 *   5. Confirm + execute. After redirect, the deferred install will fail.
 *   6. Open the recovery URL → repair.php performs a clean default
 *      install with the original superuser credentials.
 *
 * Remove this module from `site/modules/` afterwards.
 */
class CrashTest extends WireData implements Module {

	public static function getModuleInfo() {
		return [
			'title'    => 'Crash Test (ProcessWireReset test helper)',
			'version'  => '0.0.1',
			'summary'  => 'Throws on install(). Use only for testing repair.php.',
			'autoload' => false,
			'singular' => true,
		];
	}

	public function install() {
		throw new WireException('CrashTest: intentional install() failure for repair.php testing.');
	}
}
