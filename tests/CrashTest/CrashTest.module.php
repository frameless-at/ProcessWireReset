<?php namespace ProcessWire;

/**
 * CrashTest — manual test helper for ProcessWireReset's repair.php flow.
 *
 * DO NOT INSTALL IN PRODUCTION.
 *
 * The first install() completes normally so the module can be enabled
 * via Modules → Refresh → Install and selected as a "Keep Module" in
 * ProcessWireReset's config. After arming (touch a marker file), the
 * NEXT install() — i.e. the deferred re-install that runs at the end
 * of a reset via processPendingInstalls() — throws.
 *
 * That throw aborts the deferred-install loop, cleanupRecoveryState()
 * is never reached, recovery.state.php stays on disk, and the recovery
 * URL captured from the confirmation modal can be used to invoke
 * repair.php.
 *
 * Usage:
 *   1. Copy `tests/CrashTest/` → `site/modules/CrashTest/`
 *   2. Modules → Refresh → install "Crash Test"
 *      (first install() runs cleanly, module appears in the modules list)
 *   3. Arm the trigger:
 *        touch site/modules/CrashTest/.crash-on-reinstall
 *   4. ProcessWire Reset → Configure → tick CrashTest under
 *      "Modules to keep" → trigger the reset
 *   5. Copy the recovery URL from the modal, confirm, execute
 *   6. After redirect, the deferred re-install will throw — the
 *      recovery URL now resolves cleanly via repair.php
 *
 * Disarm/cleanup:
 *   - Remove the marker:  rm site/modules/CrashTest/.crash-on-reinstall
 *   - Remove the module:  rm -rf site/modules/CrashTest
 */
class CrashTest extends WireData implements Module {

	const ARM_MARKER = '.crash-on-reinstall';

	public static function getModuleInfo() {
		return [
			'title'    => 'Crash Test (ProcessWireReset test helper)',
			'version'  => '0.0.1',
			'summary'  => 'Throws on re-install when armed. Use only for testing repair.php.',
			'autoload' => false,
			'singular' => true,
		];
	}

	public function install() {
		if(is_file(__DIR__ . '/' . self::ARM_MARKER)) {
			throw new WireException(
				'CrashTest: armed re-install crash (repair.php test). '
				. 'Delete ' . self::ARM_MARKER . ' to disarm.'
			);
		}
	}
}
