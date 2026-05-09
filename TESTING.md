# ProcessWireReset — Test Scenarios

Practical test scenarios for validating the module after code changes. Each
scenario is self-contained and can be run manually in a PW installation with
the module installed.

> **⚠️ Warning:** Every scenario here performs a destructive reset. **Only run
> these against a disposable test installation.** Never run against a site
> with data you want to keep.

## Preparation (once, before any test run)

1. Clean PW installation on disposable host (Docker container ideal)
2. Install ProcessWireReset
3. Create a "known state" snapshot:
   - Admin user: `testadmin` / `TestPass123!`
   - Admin email: `admin@test.local`
   - Add one custom field `custom_text` (Text)
   - Add one custom template `article` using the custom field
   - Create one page under home: "About", template `article`, body `"sentinel-value-42"`
   - Upload a file to `site/assets/files/1/` (e.g. via image field on home)

These serve as "sentinel" markers to verify whether the reset actually wiped
non-canonical state.

---

## S01 — Clean reset, nothing preserved

**Goal:** Verify a reset without kept modules wipes everything and leaves a
pristine install.

**Preconditions:**
- Known-state snapshot in place
- Module config: `profilePath` empty, `keepModules` empty

**Action:**
1. Open **Modules → Configure → ProcessWire Reset**
2. Do NOT select any modules to keep
3. Type `RESET` in the confirmation field
4. Click **Reset Installation**

**Expected:**
- Redirect to admin login at `/processwire/`
- Login with `testadmin` / `TestPass123!` works
- Home page loads with the default `home.php` template (no "About" child)
- `site/assets/files/` is empty
- `site/templates/` contains only the default blank-profile templates
- `site/modules/` contains only `ProcessWireReset/`
- Custom field `custom_text` does not exist
- Custom template `article` does not exist
- In the DB: `SELECT COUNT(*) FROM pages` returns only the default profile page count
- No `.pending-installs.json` or `.pending-custom-tables.bin` in module directory

**Verify:**
```sql
SELECT COUNT(*) FROM pages;                        -- profile default count
SELECT COUNT(*) FROM field_custom_text;            -- should error "doesn't exist"
SELECT class FROM modules WHERE class = 'ProcessWireReset';  -- exists
```

---

## S02 — Preserve one simple site module (no deps, no custom table)

**Goal:** Verify basic single-module preservation works.

**Preconditions:**
- Known-state snapshot
- Install a simple module, e.g. **ProcessWireUpgrade** or any simple
  Process module. Configure one visible setting.
- ProcessWireReset config: empty

**Action:**
1. Open the config screen
2. Select the test module in the AsmSelect
3. Confirm with `RESET` and click **Reset Installation**

**Expected:**
- After redirect + login, the test module is still installed
- Its configuration is preserved (check via Modules → Configure)
- `site/modules/[TestModule]/` still exists on disk
- `modules` DB table has the module's row with the original `data` column
- All other custom state (fields, templates, pages) is wiped

**Verify:**
```sql
SELECT class, data FROM modules WHERE class = '[TestModule]';
```

---

## S03 — Preserve site module with custom DB table

**Goal:** Verify the custom-tables backup/defer/restore cycle works.

**Preconditions:**
- Install **SessionLoginThrottle** (core module but creates its own table
  `session_login_throttle`) — or any other site module with a custom table
- Do several failed login attempts to populate the table:
  ```sql
  SELECT * FROM session_login_throttle;   -- should have rows
  ```

**Action:**
1. Reset with the module in `keepModules`

**Expected:**
- After reset: `session_login_throttle` table exists
- Has the same rows as before the reset
- Module files and config preserved
- No errors in `site/assets/logs/processwirereset.txt`

**Verify:**
```sql
-- Compare row count and content before and after
SELECT name, attempts, last_attempt FROM session_login_throttle;
```

---

## S04 — Preserve site module with transitive site-module deps (A→B→C)

**Goal:** Verify transitive dependency resolution and install ordering.

**Preconditions:**
- Install three chained site modules where A requires B requires C
  - Example: `BootstrapSections` → `InputfieldRockIcons` → (whatever it needs)
  - Or use your own test modules

**Action:**
1. In the config screen, select ONLY Module A (the top of the chain)
2. Reset

**Expected:**
- All three modules are preserved after reset (files + DB entries)
- `site/modules/` contains directories for A, B, C
- `modules` table has entries for A, B, C
- Each module's original config (`data` column) is restored
- Log shows installation order: C first, then B, then A

**Verify:**
```sql
SELECT class FROM modules WHERE class IN ('A', 'B', 'C');
```
```bash
tail -30 site/assets/logs/processwirereset.txt
# Should show "Re-installed kept modules: C, B, A" or similar
```

---

## S05 — Preserve site module with core-module dependency

**Goal:** Verify that core-module dependencies are explicitly installed.

**Preconditions:**
- Install a site module that depends on a **core module that is not
  installed by default** (e.g. FieldtypeMapMarker, InputfieldCKEditor, or
  any Fieldtype/Inputfield not in the default install.sql)
- Verify the core dep is in `modules` table before reset

**Action:**
1. Select only the site module in the keep list
2. Reset

**Expected:**
- Both the site module AND its core-module dep are installed after reset
- The core module got its default config (no backup data available? — or
  with backup data if it was previously installed)

**Verify:**
```sql
SELECT class FROM modules WHERE class IN ('[SiteMod]', '[CoreDep]');
```

---

## S06 — Preserve module that creates admin pages (FieldtypeRepeaterMatrix)

**Goal:** Verify that modules which create admin pages via `install()` have
those pages recreated after reset.

**Preconditions:**
- Install **FieldtypeRepeaterMatrix** (Pro) or any module with a `'page'`
  entry in its `getModuleInfo()` that lives under `/processwire/setup/`
- Verify the admin page exists and is accessible

**Action:**
1. Select the module + its dependencies in the keep list
2. Reset

**Expected:**
- Admin page exists after reset
- Reachable via the same URL as before
- Custom DB tables of the module (e.g. `field_repeater_matrix_type`)
  exist and contain the original data

**Verify:**
```sql
SELECT id, name FROM pages WHERE name = 'repeater-matrix' OR parent_id IN (SELECT id FROM pages WHERE name = 'setup');
SHOW TABLES LIKE 'field_repeater_matrix_type';
SELECT COUNT(*) FROM field_repeater_matrix_type;
```

---

## S07 — Preserve module with custom fields in use

**Goal:** Verify what happens to custom fields created by a preserved module.

**Known limitation:** custom fields created by a module's `install()` hook
are recreated by re-running `install()`, but any user customization of those
fields (e.g. config changes, assignments to templates) is LOST because the
`fields` table is reset by install.sql.

**Preconditions:**
- Install a module that creates a custom field
- Modify the field's settings after install (e.g. add it to a template)

**Action:**
1. Preserve the module
2. Reset

**Expected:**
- Module is reinstalled, original field is recreated via `install()`
- User customization to the field (template assignment, label override) is
  GONE — this is documented behavior, not a bug

**Verify:** Document the gap in behavior so users don't mistake it for a bug.

---

## S08 — Reset with custom profile path (valid)

**Goal:** Verify custom profile support.

**Preconditions:**
- Create a test profile at `/absolute/path/to/my-profile/`:
  ```
  my-profile/
  ├── install/
  │   └── install.sql        (valid PW install.sql)
  └── templates/
      └── home.php           (with unique marker like <!-- custom-profile-v1 -->)
  ```
- `my-profile/install/install.sql` can be a copy of the bundled one with one
  distinctive page name change

**Action:**
1. Set `profilePath` to `/absolute/path/to/my-profile/install/`
2. Save config
3. Reset

**Expected:**
- After reset, `site/templates/home.php` contains the custom marker
- The distinctive page from the custom install.sql is present
- No errors

**Verify:**
```bash
grep 'custom-profile-v1' site/templates/home.php
```

---

## S09 — Reset with malicious profile path (directory traversal)

**Goal:** Verify `isPathAllowed()` blocks directory traversal attacks.

**Preconditions:**
- Known-state snapshot
- Create a file at `/tmp/evil-install.sql` (outside PW root)

**Action:**
1. In the config screen, set `profilePath` to `../../../../../tmp/` (or the
   absolute path outside the PW root)
2. Save, then Reset

**Expected:**
- `resolveProfileInstallSql()` returns `null` because the realpath is outside
  the PW root
- `executeReset()` errors with "Profile install.sql not found. Reset aborted."
- No database or filesystem changes happen

**Verify:**
- Known-state snapshot is intact
- Error message appears in the admin UI
- Nothing in `site/assets/logs/` indicates a destructive action ran

---

## S10 — Reset with tampered pending file

**Goal:** Verify `unserialize(..., allowed_classes=false)` prevents object
gadget attacks on the pending custom tables file.

**Preconditions:**
- Run a normal reset that produces a `.pending-custom-tables.bin`
- Before the next request completes, replace its contents with a crafted
  PHP serialized payload containing an object like:
  `O:8:"stdClass":1:{s:4:"test";s:4:"evil";}`

**Action:**
1. Trigger the next admin request (loads ProcessWireReset, triggers the hook)

**Expected:**
- No PHP fatal
- `unserialize()` returns `false` or an array (not an object)
- The restore is skipped safely
- Log entry in `processwirereset.txt`

---

## S11 — Superuser credential preservation

**Goal:** Verify the superuser's exact login credentials survive a reset.

**Preconditions:**
- Change superuser username from default to `uniquename123`
- Change password to `MyLong!Pass456`
- Change email to `unique@example.org`
- Log out

**Action:**
1. Log back in
2. Reset (no kept modules)
3. Try to log in with `uniquename123` / `MyLong!Pass456`

**Expected:**
- Login succeeds
- `wire('user')->email` is `unique@example.org`
- Pages.name for user id 41 is `uniquename123`

**Verify:**
```sql
SELECT name FROM pages WHERE id = 41;
SELECT data FROM field_email WHERE pages_id = 41;
```

---

## S12 — Debug tool active during reset (TracyDebugger)

**Goal:** Verify the reset doesn't crash when an autoload debug module's
files are deleted.

**Preconditions:**
- Install TracyDebugger
- Have Tracy bar visible on all pages
- Do NOT include TracyDebugger in keepModules

**Action:**
1. Reset

**Expected:**
- Reset completes, redirect to admin login works
- No visible Tracy bar output after redirect
- No fatal "Failed opening required bar.phtml" error
- `silenceAutoloadModules()` successfully suppressed Tracy's shutdown handler

---

## S13 — Consecutive resets

**Goal:** Verify state doesn't accumulate across multiple resets and no
pending files get stranded.

**Action:**
1. Reset with module X preserved
2. Log in, verify X works
3. Reset again with different module Y preserved (unselect X first!)
4. Log in, verify Y works and X is gone
5. Reset clean (nothing preserved)

**Expected:**
- Each reset is independent
- No leftover pending files between resets
- No accumulated data from previous resets

**Verify:**
```bash
ls -la site/modules/ProcessWireReset/.pending-*
# Should be empty after each reset completes
```

---

## S14 — AsmSelect deselect vs saved config

**Goal:** Regression test for the stale-config bug fixed in commit `1a9a182`.

**Preconditions:**
- Previously saved config has `keepModules = ['SomeModule']`
- Don't click the main "Submit" button

**Action:**
1. Open the config screen (saved config shows SomeModule selected in AsmSelect)
2. Deselect SomeModule in the AsmSelect (visual change only)
3. Do NOT click the main Submit button
4. Type `RESET` in the confirmation field
5. Click **Reset Installation**

**Expected:**
- Reset treats this as "keep nothing" — POST values are authoritative
- SomeModule is NOT preserved
- Custom tables of SomeModule are NOT backed up

**Verify:**
- SomeModule files gone from `site/modules/`
- SomeModule row gone from `modules` table
- SomeModule custom tables gone from DB

---

## S15 — Large dataset performance

**Goal:** Rough performance check; verify no timeout on large resets.

**Preconditions:**
- Create ~500 pages under home
- Upload ~100 files to various assets directories
- Install 5-10 modules

**Action:**
1. Reset preserving all installed modules

**Expected:**
- Reset completes within `set_time_limit(300)` — 5 minutes
- No OOM errors
- All preserved modules work afterward
- Log shows all phases completed

**Monitoring:**
```bash
# Watch the error log during reset
tail -f site/assets/logs/errors.txt
```

---

## S16 — Reset failure recovery

**Goal:** Verify that a failed reset aborts cleanly and leaves no partial state
that would block a subsequent recovery from a database backup.

**Simulate failure:**
- Manually create a table that cannot be dropped (e.g. by revoking DROP
  privilege on it temporarily, or by creating a view that references it)
- Run a reset

**Expected:**
- `dropAllTables()` throws `WireException` after exhausting retries
- `executeReset()` catches the exception, clears any partial pending files
- User sees a clear error message
- A `mysqldump` taken before the reset can be restored without conflicts

---

## Test matrix

| Scenario | Critical path coverage                                  |
| -------- | ------------------------------------------------------- |
| S01      | Full clean reset, filesystem cleanup                    |
| S02      | Basic module preservation                               |
| S03      | Custom DB table preservation                            |
| S04      | Transitive site-module dependencies                     |
| S05      | Core-module dependency installation                     |
| S06      | install() side effects (admin pages, custom tables)     |
| S07      | Custom field handling (known gap)                       |
| S08      | Custom profile resolution                               |
| S09      | Directory traversal protection                          |
| S10      | Serialized pending file security                        |
| S11      | Superuser credential preservation                       |
| S12      | Debug tool shutdown handler edge case                   |
| S13      | Consecutive reset state cleanup                         |
| S14      | POST-authoritative keepModules (regression)             |
| S15      | Performance under load                                  |
| S16      | Error recovery from database backup                     |

## Minimum smoke test

If you only have time for a few scenarios, run these in order:

1. **S01** — Clean reset works
2. **S11** — Superuser survives
3. **S04** — Dependency chain works
4. **S06** — Complex module with admin page + custom tables

If all four pass, the module is likely functional for the common cases.
