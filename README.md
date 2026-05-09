# ProcessWireReset

Reset an existing ProcessWire installation back to a clean profile state — while
preserving the current superuser account and any selected site or core modules
along with their configuration and custom data.

> **⚠️ Development tool.** This module is destructive by design. It is intended
> for use during development, testing, and CI workflows. **Do not install on a
> production site without understanding what it does.**

---

## What it does

A reset performs the following steps in order:

1. Backs up the current superuser (name, password hash + salt, email)
2. Backs up the configuration of every kept module (including transitive
   dependencies, site- and core-modules alike)
3. Backs up the contents of any non-canonical DB tables that belong to kept
   modules (e.g. login throttle, log tables, custom storage)
4. Drops **all** database tables and re-imports `wire/core/install.sql`
   merged with the configured profile's `install.sql`
5. Restores the superuser account into the freshly imported tables
6. Empties `site/assets/files/`, `assets/cache/`, `assets/logs/` and
   `assets/sessions/`
7. Resets `site/templates/` to the profile state
8. Removes everything from `site/modules/` except `ProcessWireReset` itself
   and the kept modules
9. Writes a deferred-install pending file containing kept modules in
   topologically sorted order
10. Redirects to the admin login

On the next request, `ProcessWireReset` autoloads (because the pending file
exists), elevates to superuser context, and runs `$modules->install()` for each
pending module — recreating any admin pages, custom fields, and DB tables that
the modules' install hooks normally create. After processing, the pending file
is deleted and ProcessWireReset returns to non-autoload state.

## Requirements

- ProcessWire **3.0.0** or newer
- PHP **7.1** or newer (matches PW's minimum)
- MySQL or MariaDB with `INFORMATION_SCHEMA` access (standard)
- Write permission on `site/modules/ProcessWireReset/` (the module writes its
  pending-installs file there)

## Installation

1. Copy the `ProcessWireReset/` directory into `site/modules/`
2. In the PW admin, go to **Modules → Refresh**
3. Find **ProcessWire Reset** in the list and click **Install**

The module is **singular** and **non-autoload** by default — it only loads
when its config screen is opened or when a pending install needs processing.

## Usage

1. Open **Modules → Configure → ProcessWire Reset**
2. (Optional) Enter a **Custom Profile Path** if you want to reset to a profile
   other than the bundled `site-blank`. The path must point to a directory
   containing an `install.sql`. A sibling `templates/` directory will be used
   for template files if present.
3. (Optional) Select **Modules to Keep** in the AsmSelect. Transitive
   dependencies are automatically included — if you select Module A which
   requires B which requires C, all three are preserved.
4. Tick **I want to reset this installation** and submit the form
5. A confirmation modal opens — review the summary and click **Execute Reset**

The reset runs, the page redirects to the admin login, and on the next admin
request the deferred install kicks in to restore the kept modules.

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

- Use `my-profile/install/install.sql` for the database import
- Look for `my-profile/templates/` (sibling of `install/`) for the template
  files
- Validate that the resolved real path lies within the PW root directory
  (directory traversal protection)

If no custom path is set, the module uses its bundled defaults under
`install/install.sql` and `install/site-templates/`.

## How modules are preserved

Three independent layers of state need to survive a reset:

1. **Module files** in `site/modules/`. Selected modules and their transitive
   site-module dependencies are kept; all other module directories are deleted.
   Core modules are never touched (they live in `wire/modules/`).
2. **Module configuration** in the `modules` table (`data` and `flags`
   columns). Backed up before the drop, restored after install.
3. **Custom DB tables** that modules create themselves (e.g.
   `session_login_throttle`, `field_repeater_matrix_type`). Identified by
   diffing the live DB against the canonical tables in `install.sql`,
   dumped with their `CREATE TABLE` and rows, and recreated after the reset.

What is **not** preserved:

- Custom **fields**, **templates**, and **pages** that modules create via
  their `install()` hook are recreated by re-running `install()` on the
  next request, not preserved verbatim. This means PW will recreate them
  in their default state — any user customizations to those fields/templates
  after the original install will be lost.

## Recovery

A reset can crash mid-way — typically because one of the kept modules
throws inside its own `install()`. To make recovery a one-click operation,
the module ships with a stand-alone `repair.php` endpoint:

1. **Before** the wipe runs, the confirmation modal shows a one-time
   **Recovery URL** with a fresh random token. The user must tick
   *"I saved the recovery URL"* before the reset can proceed.
2. The same token (bcrypt-hashed) plus the captured superuser
   credentials are written to `recovery.state.php` in the module
   directory. The file uses a `<?php exit;` wrapper and is `chmod 0600`;
   defense-in-depth deny rules live in `.htaccess`.
3. If the reset finishes successfully (including any deferred module
   installs), `recovery.state.php` is auto-deleted. The token expires
   after 24 h regardless.
4. If anything crashes, opening the saved Recovery URL invokes
   `repair.php`. The endpoint:
   - runs **without** a working ProcessWire bootstrap (raw PDO + the
     site's `config.php`),
   - drops every remaining table, re-imports `wire/core/install.sql`
     and the bundled `site-blank/install.sql`,
   - restores the **original** superuser name, email, password hash and
     admin theme,
   - **does not** re-install any custom modules — a deliberate choice,
     since a misbehaving module is the most common crash cause,
   - deletes the recovery state and any pending-task files on success.

After repair the site is at the bundled default profile with the
familiar admin login. Custom templates, fields and modules need to be
restored separately (from version control, a profile, or a database
dump if you took one).

## Security notes

- The module is non-autoload by default and only acts on POST submissions to
  its own config form
- The reset is gated by a JavaScript-driven confirmation modal that only
  submits after explicit user confirmation; the server additionally requires
  a hidden `confirmReset` token in the POST payload
- Custom profile paths are validated via `realpath()` against the PW root
- The redirect URL is validated to prevent open-redirect / header injection
- Output errors during the destructive phase are suppressed to prevent
  shutdown handlers of deleted modules (e.g. TracyDebugger) from breaking
  the redirect

This module gives any superuser the ability to wipe the database and
filesystem with a single click. **Do not deploy to production unless you have
a very specific reason to.**

## License

MIT
