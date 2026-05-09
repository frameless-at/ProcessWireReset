<?php namespace ProcessWire;

/**
 * CrashTest — manual test helper for ProcessWireReset's repair.php flow.
 *
 * DO NOT INSTALL IN PRODUCTION.
 *
 * The first install() runs cleanly and writes a marker file in the
 * module directory. The marker arms the trigger: on the NEXT
 * install() — i.e. the deferred re-install run by
 * processPendingInstalls() at the end of a reset — install() consumes
 * the marker and HARD-KILLS the request via die().
 *
 * die() is deliberate. A regular Exception would be caught by
 * processPendingInstalls()' catch(\Exception) block, the row would
 * be rolled back, the reset would finish "normally", and
 * cleanupRecoveryState() would delete recovery.state.php — defeating
 * the whole point of the test. Killing the request mid-way is
 * exactly what a real fatal-error inside an install() looks like,
 * and it's the case repair.php exists for: recovery.state.php is
 * preserved because no cleanup code runs.
 *
 * Marker is consumed on the failing call so a fresh UI install after
 * recovery succeeds again (and re-arms automatically).
 *
 * Usage (no CLI required):
 *   1. Copy `tests/CrashTest/` → `site/modules/CrashTest/`
 *   2. Modules → Refresh → install "Crash Test"
 *      (first install() runs cleanly and arms the trigger)
 *   3. ProcessWire Reset → Configure → tick CrashTest under
 *      "Modules to keep" → trigger the reset
 *   4. Copy the recovery URL from the modal, confirm, execute
 *   5. After redirect, the deferred re-install will throw and the
 *      recovery URL now resolves cleanly via repair.php
 *
 * To re-run the test after recovery: Modules → Refresh → install
 * Crash Test again. The marker is gone (consumed during the crash),
 * so the install succeeds and re-arms.
 *
 * Cleanup: uninstall via Modules screen, or remove the directory.
 */
class CrashTest extends WireData implements Module {

	const ARM_MARKER = '.crash-on-reinstall';

	public static function getModuleInfo() {
		return [
			'title'    => 'Crash Test (ProcessWireReset test helper)',
			'version'  => '0.0.1',
			'summary'  => 'First install arms a trigger; next install throws. Test helper for repair.php.',
			'autoload' => false,
			'singular' => true,
		];
	}

	public function install() {
		$marker = __DIR__ . '/' . self::ARM_MARKER;
		if(is_file($marker)) {
			// Consume the marker so a manual re-install via the modules
			// screen after a successful recovery works without leftover state.
			@unlink($marker);
			// Hard-kill the request. A throw would be caught by
			// processPendingInstalls(); we want the response to die
			// mid-stream so recovery.state.php is preserved for repair.php.
			die("CrashTest: armed re-install fatal triggered for repair.php testing.\n");
		}
		// First install on this filesystem copy — arm for the next call.
		@file_put_contents($marker, "Armed by CrashTest::install(); will trigger on next install().\n");
	}

	public function uninstall() {
		// Defensive: if the user uninstalls before triggering the crash,
		// don't leave an armed marker behind that would break a later
		// fresh install.
		@unlink(__DIR__ . '/' . self::ARM_MARKER);
	}
}
