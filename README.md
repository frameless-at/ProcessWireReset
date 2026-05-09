# ProcessWireReset

Reset an existing ProcessWire installation back to a clean profile state — while
preserving the current superuser account and any selected modules along with
their configuration. Captured tables from non-canonical (module-specific)
database state are written to a snapshot that the user can selectively restore
afterwards.

> **⚠️ Development tool.** This module is destructive by design. It is intended
> for use during development, testing, and CI workflows. **Do not install on a
> production site without understanding what it does.**

---

## Typical use cases

- **Iterative module / template / profile development.** Wipe pages,
  fields, templates and uploads between test runs without uninstalling
  PW. The superuser stays logged-in-able, which keeps the feedback
  loop tight.
- **Site profile validation.** When building a custom site profile,
  verify it cleanly installs and runs end-to-end by repeatedly
  resetting against it.
- **Domain recycling.** Reuse an existing PW installation (DB, host,
  config) for a new project without redoing the install dance —
  reset to a fresh profile and start over.
- **Cleanup after stress / load tests.** Drop the bulk pages, files
  and modules a test run created and return to a baseline.

The reset is useful any time "uninstall everything except my
superuser and these N modules, then start fresh" describes the goal
more accurately than reinstalling PW from scratch. It is **not**
suitable for fully unattended scenarios (cron-triggered demo
resets, CI pipelines) — the flow requires a confirmation modal and
the user copying the recovery URL before the destructive phase.

---

## What it does

A reset performs the following steps in order:

1. Backs up the current superuser (name, password hash + salt, email, admin
   theme).
2. Backs up the configuration of every kept module (including transitive
   dependencies, site- and core-modules alike).
3. Writes a persistent snapshot of all non-canonical database tables (those
   not present in the freshly imported `install.sql`). Tables backed by a
   PW-core fieldtype are filtered out — they're recreated by the
   SystemUpdater anyway.
4. Drops **all** database tables and re-imports `wire/core/install.sql`
   merged with the configured profile's `install.sql`.
5. Restores the superuser account into the freshly imported tables.
6. Empties `site/assets/files/`, `assets/cache/`, `assets/logs/` and
   `assets/sessions/`.
7. Resets `site/templates/` to the profile state.
8. Removes everything from `site/modules/` except `ProcessWireReset` itself
   and the kept modules.
9. Writes a deferred-install pending file containing kept modules in
   topologically sorted order.
10. Redirects to the admin login.

On the next request, `ProcessWireReset` autoloads (because the pending file
exists), elevates to superuser context, and runs `$modules->install()` for each
pending module — recreating any admin pages, custom fields, and DB tables that
the modules' install hooks normally create.

The snapshot from step 3 is **not** auto-restored. After login, an admin banner
points the user at the snapshot UI where individual tables can be restored on
demand. See [Database snapshot](#database-snapshot) below.

## Requirements

- ProcessWire **3.0.0** or newer
- PHP **7.1** or newer (matches PW's minimum)
- MySQL or MariaDB with `INFORMATION_SCHEMA` access (standard)
- Write permission on `site/modules/ProcessWireReset/` (the module writes its
  pending-installs file and snapshot there)
- Write permission on the PW document root (the module copies `repair.php`
  there during install — see [Recovery](#recovery))

## Installation

1. Copy the `ProcessWireReset/` directory into `site/modules/`.
2. In the PW admin, go to **Modules → Refresh**.
3. Find **ProcessWire Reset** in the list and click **Install**.

`install()` copies `repair.php` from the module directory into the PW document
root and renames it to `pwreset_repair.php`. `uninstall()` removes that file
again. If the document root is not writable, install fails with a clear
message — see [Recovery endpoint location](#recovery-endpoint-location) for
the manual fallback.

The module is **singular** and **non-autoload** in normal operation — it only
loads when its config screen is opened, when a pending install needs
processing, or when an unrestored snapshot exists (so it can show the banner).

## Usage

1. Open **Modules → Configure → ProcessWire Reset**.
2. (Optional) Enter a **Custom Profile Path** if you want to reset to a profile
   other than the bundled `site-blank`. The path must point to a directory
   containing an `install.sql`. A sibling `templates/` directory will be used
   for template files if present.
3. (Optional) Select **Modules to Keep** in the AsmSelect. Transitive
   dependencies are automatically included — if you select Module A which
   requires B which requires C, all three are preserved.
4. Tick **I want to reset this installation** and submit the form.
5. A confirmation modal opens. Inspect the summary, copy the recovery URL
   (single-use, shown only once), tick **I saved the recovery URL**, and
   click **Execute Reset**.

The reset runs, the page redirects to the admin login, and on the next admin
request the deferred install kicks in to restore the kept modules. After
login, the snapshot banner offers selective restoration of any captured
non-canonical tables.

## Custom profiles

A profile is a directory with this structure:

```
my-profile/
├── install/
│   └── install.sql        ← required
└── templates/             ← optional, copied to site/templates/ if present
```

Set the **Custom Profile Path** to the absolute path of `my-profile/install/`.
The module will:

- Use `my-profile/install/install.sql` for the database import.
- Look for `my-profile/templates/` (sibling of `install/`) for the template
  files.
- Validate that the resolved real path lies within the PW root directory
  (directory traversal protection).

If no custom path is set, the module uses its bundled defaults under
`install/install.sql` and `install/site-templates/`.

## How modules and data are preserved

Three independent layers of state need to survive a reset:

1. **Module files** in `site/modules/`. Selected modules and their transitive
   site-module dependencies are kept; all other module directories are
   deleted. Core modules are never touched (they live in `wire/modules/`).
2. **Module configuration** in the `modules` table (`data` and `flags`
   columns). Backed up before the drop, restored after re-install.
3. **Custom database tables** that modules create themselves (e.g.
   `forms_entries`, `process_redirects`, custom fieldtype tables). Identified
   by diffing the live DB against the canonical tables in `install.sql`.
   PW-core field tables (those whose fieldtype lives under `wire/`) are
   excluded — the SystemUpdater re-creates them after install. Everything
   else lands in the snapshot.

What is **not** preserved:

- Custom **fields**, **templates**, and **pages** that modules create via
  their `install()` hook are recreated by re-running `install()` on the
  next request, not preserved verbatim. PW recreates them in their default
  state — any user customizations to those fields/templates after the
  original install will be lost.

## Database snapshot

Captured non-canonical tables are written to `.snapshot.bin` in the module
directory. Format: a `<?php exit;` wrapper around a base64-encoded serialised
payload, `chmod 0600`, denied via the bundled `.htaccess`. Only the latest
snapshot is kept; a new reset overwrites the previous one.

After a reset, the module config screen gains a **Database snapshot** section
listing every captured table with:

- the table name,
- a heuristic owner hint (deterministic for `field_*` tables via the live
  `fields` table; class-name prefix match against the captured keep-modules
  list otherwise; `Unknown owner` as last resort),
- the row count.

The user picks tables and clicks **Restore selected tables** to import them.
Restored tables are removed from the snapshot. **Delete snapshot** discards
the entire backup. Both actions are written to the `processwirereset` log
under *Setup → Logs*.

A non-dismissable admin banner (UIkit warning style) appears on every admin
page while an unacknowledged snapshot exists. It disappears the moment the
user has restored at least one table — even if other tables remain in the
snapshot. The remaining tables stay accessible from the module config screen.

## Recovery

A reset can crash mid-way — typically because a kept module throws inside its
own `install()`. The recovery flow is built around a stand-alone PHP script
in the document root:

1. Before the wipe runs, the confirmation modal shows a one-time **Recovery
   URL** with a freshly generated 256-bit token. The user must tick *"I
   saved the recovery URL"* before the reset can proceed.
2. The token (bcrypt-hashed) plus the captured superuser credentials and SQL
   import paths are written to `recovery.state.php` in the module directory
   (PHP wrapper, `chmod 0600`, denied via `.htaccess`).
3. If the reset finishes successfully — including all deferred module
   installs — `recovery.state.php` is auto-deleted. The token also expires
   after 24 h regardless.
4. If anything crashes (an `install()` calls `die()`, a PHP fatal escapes,
   the request is killed), the recovery state stays on disk. The user opens
   the saved Recovery URL, which invokes `pwreset_repair.php`. That script:
   - runs **without** a ProcessWire bootstrap (raw PDO + a tokenizer-based
     parser of the site's `config.php`),
   - drops every remaining table,
   - re-imports `wire/core/install.sql` and the bundled `site-blank/install.sql`
     via PW's `WireDatabaseBackup`,
   - restores the original superuser name, email, password hash and admin
     theme,
   - does **not** re-install custom modules — a deliberate choice, since a
     misbehaving module is the most common crash cause,
   - deletes recovery state and any pending-task files on success.

After repair the site is at the bundled default profile with the familiar
admin login. Custom templates, fields and modules need to be restored
separately (from version control, a profile, or a database dump if you took
one).

### Recovery endpoint location

The recovery URL points at `https://your-site.tld/pwreset_repair.php?token=…`.
The script lives in the PW document root because many shared-hosting setups
block `.php` execution under `site/modules/` at the server level
(`mod_security`, restrictive `AllowOverride`), and the bundled `.htaccess`
cannot reliably override that. The document root is where `index.php` lives,
so `.php` execution is permitted.

`install()` does the copy automatically. If write fails, `install()` throws
with a clear message. In that case copy `repair.php` from the module
directory to `<docroot>/pwreset_repair.php` manually before triggering a
reset; the script walks up the directory tree to find `site/config.php`
and `wire/core/install.sql`, so it works from either location.

### Diagnostic mode

Calling the recovery URL **without** the `?token=` parameter renders a small
diagnostic page that confirms the script is reachable and reports basic
booleans about the snapshot state — without leaking absolute paths, PHP
versions, or other reconnaissance-relevant data. Useful for verifying that
the file is actually being served before triggering a real reset.

## Tests and dev material

Two artefacts live in the source repository but are **not** shipped in
the distribution archive:

- `TESTING.md` — manual test scenarios used to validate the module
  after code changes. ~20 scenarios, including the recovery flow.
- `tests/CrashTest/` — a deliberately broken helper module used to
  exercise the recovery path. Its first `install()` writes a marker;
  its second `install()` (the deferred re-install after a reset) finds
  the marker and `die()`s, leaving `recovery.state.php` in place so
  the recovery URL has something to consume. See `TESTING.md` scenario
  S17 for the full walkthrough.

Both are available when the module is cloned from its source repository.

## Security notes

- The module is non-autoload in normal operation; it only loads on its own
  config screen and when a pending or snapshot file exists.
- The reset POST is gated by a confirmation modal plus a hidden
  `confirmReset` token — server-side check, not just JS.
- Recovery tokens are 256-bit, bcrypt-hashed at rest, and live for 24 h max.
  Plaintext tokens never reach the server's filesystem; only the URL the
  user copies contains them.
- `recovery.state.php` and `.snapshot.bin` use `<?php exit;` wrappers, file
  permissions `0600`, and `.htaccess` deny rules. Three independent layers.
- Custom profile paths are validated via `realpath()` against the PW root
  to block directory traversal.
- The post-reset redirect URL is validated against the configured admin URL
  to prevent open-redirect / header injection.
- Output buffering during the destructive phase swallows core-module
  warnings (e.g. PW 3.0.218+ ModulesFlags) so the redirect's
  `header()` calls survive.

This module gives any superuser the ability to wipe the database and
filesystem with a single click. **Do not deploy to production unless you have
a very specific reason to.**

## License

The module's own code is released under **MIT** (see `LICENSE` if present, or
this README block as the licence statement).

`vendor/Installer.php` is a near-verbatim copy of the **ProcessWire installer**
(© Ryan Cramer / processwire.com, https://processwire.com), licensed under the
**Mozilla Public License 2.0**. The only modifications are:

- removal of the top-level `define("PROCESSWIRE_INSTALL", ...)` side effect
  so the file can be included safely inside a live PW request,
- replacement of that constant's single in-class consumer with a literal
  string.

A copy of the MPL-2.0 is available at https://www.mozilla.org/MPL/2.0/.
