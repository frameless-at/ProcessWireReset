# ProcessWireReset — Test Scenarios

Practical test scenarios for validating the module after code changes. Each
scenario is self-contained and can be run manually in a PW installation with
the module installed.

> **⚠️ Warning:** Every scenario here performs a destructive reset. **Only run
> these against a disposable test installation.** Never run against a site
> with data you want to keep.

## Preparation (once, before any test run)

1. Clean PW installation on disposable host (Docker container ideal).
2. Install ProcessWireReset. The install hook copies
   `repair.php` from the module directory to `<docroot>/pwreset_repair.php`.
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
3. Tick **I want to reset this installation** and submit the form
4. In the confirmation modal, copy the recovery URL, tick **I saved the
   recovery URL**, click **Execute Reset**

**Expected:**
- Redirect to admin login at `/processwire/`
- Login with `testadmin` / `TestPass123!` works
- Home page loads with the default template (no "About" child)
- `site/assets/files/` is empty
- `site/templates/` contains only the default blank-profile templates
- `site/modules/` contains only `ProcessWireReset/`
- Custom field `custom_text` does not exist
- Custom template `article` does not exist
- `<docroot>/pwreset_repair.php` still exists (it survives resets — only
  uninstall removes it)
- No `.pending-installs.json`, no `.snapshot.bin`, no `recovery.state.php`
  in the module directory
- No snapshot banner is shown

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
3. Tick the reset checkbox, submit, confirm in the modal

**Expected:**
- After redirect + login, the test module is still installed
- Its configuration is preserved (check via Modules → Configure)
- `site/modules/[TestModule]/` still exists on disk
- `modules` DB table has the module's row with the original `data` column
- All other custom state (fields, templates, pages) is wiped
- A snapshot banner *may* appear on the admin if PW's SystemUpdater added
  any non-canonical tables since the imported install.sql was generated
  (e.g. `modules_flags` on PW 3.0.218+). Either resolve via the snapshot
  UI or ignore — these tables are reproducible.

**Verify:**
```sql
SELECT class, data FROM modules WHERE class = '[TestModule]';
```

---

## S03 — Module with custom DB table → snapshot + restore

**Goal:** Verify the snapshot capture + opt-in restore cycle for
module-specific tables.

**Preconditions:**
- Install **ProcessRedirects** (Teppo Koivula) or another site module that
  creates its own non-canonical table. ProcessRedirects creates
  `process_redirects`.
- Add at least three redirect entries via the admin so the table has rows.

**Action:**
1. Open ProcessWireReset config, select the module under *Modules to Keep*
2. Trigger a reset, copy the recovery URL, confirm in the modal
3. Log back in

**Expected (immediately after login):**
- Module files and config preserved (`site/modules/ProcessRedirects/` still
  exists, module is enabled)
- Module's table (`process_redirects`) **exists but is empty** — module's
  `install()` recreates the schema, the rows are not auto-restored
- Snapshot banner appears at the top of every admin page
- `.snapshot.bin` exists in the module directory
- Log entry under *Setup → Logs → processwirereset* mentions the module's
  re-installation

**Action — restore:**
4. Click **Review & restore** in the banner (or open Modules → Configure →
   ProcessWire Reset)
5. In the snapshot section, the table appears with its row count and a
   heuristic owner hint (`Likely module: ProcessRedirects` for prefix-match)
6. Tick the table's checkbox, click **Restore selected tables**

**Expected (after restore):**
- Original rows are back: `SELECT COUNT(*) FROM process_redirects` matches
  the pre-reset count
- Banner disappears on the next page load
- Log entry: `Snapshot restore: table 'process_redirects' (N rows) by user testadmin`
- Snapshot file `.snapshot.bin` is gone (only one table was in the
  snapshot, restoring it empties the file)

---

## S04 — Preserve site module with transitive site-module deps (A→B→C)

**Goal:** Verify transitive dependency resolution and install ordering.

**Preconditions:**
- Install three chained site modules where A requires B requires C
  (e.g. some inputfield that brings its own dependencies).

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
```

---

## S05 — Preserve site module with core-module dependency

**Goal:** Verify that core-module dependencies are explicitly installed.

**Preconditions:**
- Install a site module that depends on a core module not installed by
  default (e.g. an Inputfield/Fieldtype not in the default install.sql).
- Verify the core dep is in the `modules` table before reset.

**Action:**
1. Select only the site module in the keep list
2. Reset

**Expected:**
- Both the site module AND its core dep are installed after reset.
- The core module gets either its default config or the backed-up data,
  depending on whether it had pre-reset config.

**Verify:**
```sql
SELECT class FROM modules WHERE class IN ('[SiteMod]', '[CoreDep]');
```

---

## S06 — Module that creates admin pages + custom tables (RepeaterMatrix-style)

**Goal:** Verify modules that create admin pages and field-specific tables
via `install()` come back correctly.

**Preconditions:**
- Install **FieldtypeRepeaterMatrix** (Pro) or any module with a `'page'`
  entry in `getModuleInfo()` and at least one user-defined field
  (e.g. one repeater-matrix field with sample data).

**Action:**
1. Select the module + its dependencies in the keep list
2. Reset, log back in

**Expected:**
- Admin page exists at the same URL as before
- `field_<userdef>` tables exist (recreated by the module's `install()`
  on the field) but are **empty**
- Snapshot contains the captured data with heuristic owner hint
  *Field "<name>" (Fieldtype: FieldtypeRepeaterMatrix)* via the live
  `fields` table lookup
- After restoring the table from the snapshot UI, the original repeater
  data is back

**Verify:**
```sql
SHOW TABLES LIKE 'field_%';
SELECT COUNT(*) FROM field_<your_repeater_field>;
```

---

## S07 — Custom field user customization is NOT preserved

**Goal:** Document the known limitation around field customization.

**Known limitation:** custom fields created by a module's `install()` hook
are recreated by re-running `install()`. Any *user customization* of those
fields (label override, template assignments, custom properties set via
the admin) is LOST because PW's `fields` table is reset by `install.sql`.

**Preconditions:**
- Install a module that creates a custom field
- Modify the field's settings after install (e.g. add it to a template,
  change its label)

**Action:**
1. Preserve the module
2. Reset

**Expected:**
- Module is reinstalled, original field is recreated via `install()`
- User customization to the field is GONE — this is documented behavior,
  not a bug

**Verify:** Confirm the field settings revert to what `install()` would
write on a fresh module install.

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

**Action:**
1. Set `profilePath` to `/absolute/path/to/my-profile/install/`
2. Save config
3. Reset

**Expected:**
- After reset, `site/templates/home.php` contains the custom marker
- The distinctive page from the custom install.sql is present

**Verify:**
```bash
grep 'custom-profile-v1' site/templates/home.php
```

---

## S09 — Reset with malicious profile path (directory traversal)

**Goal:** Verify `resolveProfileInstallSql()` blocks directory traversal.

**Preconditions:**
- Known-state snapshot
- Create `/tmp/evil-install.sql` (outside PW root)

**Action:**
1. Set `profilePath` to `../../../../../tmp/` or the absolute path outside
   the PW root
2. Save, then Reset

**Expected:**
- `executeReset()` aborts with "Profile install.sql not found"
- No database or filesystem changes happen
- Known-state snapshot is intact

**Verify:**
- Sentinel page "About" still exists
- `field_custom_text` still exists

---

## S10 — Tampered snapshot file (deserialization safety)

**Goal:** Verify `unserialize(..., allowed_classes=false)` prevents object
gadget attacks on the snapshot file.

**Preconditions:**
- Run a normal reset that produces a `.snapshot.bin`

**Action:**
1. On the server, edit `.snapshot.bin` and replace the base64-encoded
   payload section between the `==SNAPSHOT==` markers with a base64-encoded
   serialized object, e.g.:
   ```
   echo -n 'O:8:"stdClass":1:{s:4:"test";s:4:"evil";}' | base64
   ```
2. Open the module config screen

**Expected:**
- No PHP fatal
- `readCustomTablesSnapshot()` returns `null` (object not allowed by
  `unserialize(..., ['allowed_classes' => false])`, so `is_array($payload)`
  is false)
- Snapshot section does not render (or renders empty)
- No restore action does anything destructive

---

## S11 — Superuser credential preservation

**Goal:** Verify the superuser's exact login credentials survive a reset.

**Preconditions:**
- Change superuser username from default to `uniquename123`
- Change password to `MyLong!Pass456`
- Change email to `unique@example.org`
- Log out, then log back in to confirm the credentials work

**Action:**
1. Reset (no kept modules)
2. Try to log in with `uniquename123` / `MyLong!Pass456`

**Expected:**
- Login succeeds
- `wire('user')->email` is `unique@example.org`
- `pages.name` for user id 41 is `uniquename123`

**Verify:**
```sql
SELECT name FROM pages WHERE id = 41;
SELECT data FROM field_email WHERE pages_id = 41;
```

---

## S12 — Debug tool active during reset (TracyDebugger)

**Goal:** Verify the reset doesn't crash when an autoload debug module's
files are deleted, and that `silenceAutoloadModules()` handles older Tracy
versions (constant `PRODUCTION` vs. newer `ProductionMode`).

**Preconditions:**
- Install TracyDebugger
- Have Tracy bar visible on all pages
- Do NOT include TracyDebugger in keepModules

**Action:**
1. Reset

**Expected:**
- Reset completes, redirect to admin login works
- No visible Tracy bar output after redirect
- No fatal "Failed opening required" or "Undefined constant" error
- `silenceAutoloadModules()` successfully suppressed Tracy's shutdown
  handler regardless of Tracy version

---

## S13 — Consecutive resets

**Goal:** Verify no state accumulates across multiple resets and no
pending or snapshot files get stranded incorrectly.

**Action:**
1. Reset with module X preserved (X has a custom table with rows)
2. Log in, restore X's table from the snapshot UI
3. Reset again with module Y preserved (unselect X first!)
4. Log in, verify Y works and X is gone (files + DB)
5. Reset clean (nothing preserved)

**Expected:**
- Each reset is independent
- `.pending-installs.json` is gone after every successful reset
- `.snapshot.bin` is overwritten by each reset (only the latest snapshot
  is kept)
- Step 5 produces no `.snapshot.bin` (no kept modules → no backup)

**Verify:**
```bash
ls -la site/modules/ProcessWireReset/.pending-* site/modules/ProcessWireReset/.snapshot.bin
# After step 2: only .snapshot.bin gone (consumed by restore)
# After step 4: .snapshot.bin from step 3, no leftover from step 1
# After step 5: no .pending-installs.json, no .snapshot.bin
```

---

## S14 — AsmSelect deselect vs saved config (regression)

**Goal:** Regression test for stale-config handling.

**Preconditions:**
- Previously saved config has `keepModules = ['SomeModule']`
- SomeModule has a custom table with rows

**Action:**
1. Open the config screen (saved config shows SomeModule selected)
2. Deselect SomeModule in the AsmSelect (visual change only)
3. Do NOT click the main Submit button
4. Tick the reset checkbox, submit, confirm in the modal

**Expected:**
- Reset treats this as "keep nothing" — POST values are authoritative.
- SomeModule files are gone from `site/modules/`.
- SomeModule row gone from `modules` table.
- **No snapshot is written** — capture only happens when keepModules is
  non-empty. SomeModule's data is therefore lost (intended for this code
  path; the user explicitly removed it from the keep list).

**Verify:**
- `ls site/modules/[SomeModule]/` → does not exist
- `SELECT class FROM modules WHERE class = '[SomeModule]'` → no row
- `ls site/modules/ProcessWireReset/.snapshot.bin` → does not exist

---

## S15 — Large dataset performance

**Goal:** Rough performance check; verify no timeout on large resets.

**Preconditions:**
- ~500 pages under home
- ~100 files in various assets directories
- 5–10 modules installed

**Action:**
1. Reset preserving all installed modules

**Expected:**
- Reset completes within `set_time_limit(300)` (5 minutes)
- No OOM errors
- All preserved modules work afterward
- Snapshot file size is proportional to the captured table data

**Monitoring:**
```bash
tail -f site/assets/logs/errors.txt
ls -lah site/modules/ProcessWireReset/.snapshot.bin
```

---

## S16 — Reset failure during DB phase

**Goal:** Verify a failed reset aborts cleanly without stranding pending
files.

**Simulate failure:**
- Manually create a table that cannot be dropped (revoke DROP privilege,
  or create a view that references it)
- Run a reset

**Expected:**
- `dropAllTables()` throws `WireException` after exhausting retries.
- `executeReset()` catches the exception, deletes any half-written
  `.pending-installs.json` (and its `.processing` sibling, if a previous
  attempt left one).
- User sees a clear error message in the admin UI.
- A `mysqldump` taken before the reset can be restored without conflicts.
- `.snapshot.bin` may or may not exist (depending on which phase failed)
  but is never partial — the wrapper format requires the `==SNAPSHOT==`
  closing marker to parse, so a partial write is treated as no snapshot.

---

## S17 — Recovery via pwreset_repair.php (token-gated standard install)

**Goal:** Verify that the recovery URL captured in the confirmation modal
recovers from a crash inside the deferred-install phase, by invoking the
recovery endpoint and producing a working clean install with the original
superuser credentials.

**Setup (no CLI required):**
- Copy `tests/CrashTest/` into `site/modules/CrashTest/`
- Modules → Refresh → install **Crash Test**
  (the first `install()` runs cleanly and silently arms the trigger for
  the next `install()` call)
- ProcessWire Reset → Configure → select `CrashTest` under
  *Modules to keep*

**Action:**
1. Trigger the reset (tick the enable checkbox, submit)
2. In the confirmation modal:
   - Click the copy icon next to the recovery URL
   - Paste it somewhere outside the browser (sticky note, password
     manager, second tab — anywhere it survives a PW outage)
   - Tick **I saved the recovery URL**
3. Click **Execute Reset**
4. After redirect, observe that the response dies mid-stream —
   `processPendingInstalls()` re-runs `CrashTest::install()`, which
   finds the marker and `die()`s. This is intentional: a regular
   `throw` would be caught by the install-loop's `catch(\Exception)`
   block, the row would be rolled back, and the reset would finish
   "successfully" — defeating the test.

**Expected (failure phase):**
- The browser shows a partial / broken admin page or the `die()` message.
- `site/modules/ProcessWireReset/recovery.state.php` exists.
- `.pending-installs.json` is gone (deleted at the top of
  `processPendingInstalls()` via the atomic-rename claim — that's expected).
- `.snapshot.bin` may exist if other modules have non-canonical tables; not
  relevant for this test.
- Direct HTTP access to `recovery.state.php` returns 403 (PHP wrapper).
- The `<docroot>/pwreset_repair.php` URL still works — the file is from
  the module install and is independent of the reset state.

**Action — recovery:**
5. Open the saved recovery URL (`https://your-site.tld/pwreset_repair.php?token=…`)

**Expected (recovery phase):**
- The endpoint streams a step list with green checkmarks: Token verified,
  Install profiles located, DB credentials read, Database connected,
  All tables dropped, Fresh schema imported, Superuser restored, Recovery
  state cleared.
- A green banner *Recovery complete* with a "Continue to login" link.
- `recovery.state.php` and `.pending-installs.json` are gone afterwards.
- Login works with the **pre-reset** username + password.
- The admin shows the bundled `site-blank` profile (no CrashTest, no
  other custom modules — by design).

**Negative checks (defense):**
- Calling the endpoint without `?token=` → 200 diagnostic page (booleans
  only, no paths/usernames/PHP version).
- Calling with a wrong token → 403 with ~500 ms artificial delay.
- Calling after success → 200 diagnostic page saying "no recovery in
  progress".
- Calling 24 h after the reset (TTL expiry) → 403, state file is
  auto-deleted on the failed verify attempt.

**Re-running:**
- After a successful recovery the marker has been consumed by the crashing
  `install()` call. Modules → Refresh → install **Crash Test** again to
  re-arm.

**Cleanup:**
- Modules → uninstall **Crash Test** (uninstall hook clears any leftover
  marker), then delete `site/modules/CrashTest/`.

---

## S18 — Snapshot UI: PW-core field tables are filtered out

**Goal:** Verify that field tables backed by core fieldtypes (e.g.
`field_admin_theme`, added by the SystemUpdater post-install) do **not**
appear in the snapshot — they're recreated automatically anyway.

**Preconditions:**
- Fresh install with no custom site-module fieldtypes.
- At least one core-fieldtype field exists (e.g. `admin_theme`,
  `roles`, `permissions` — these are PW defaults).

**Action:**
1. Reset with at least one module in keepModules (so a snapshot is
   captured at all).
2. Log in, open the snapshot UI.

**Expected:**
- The snapshot table list does **not** include `field_admin_theme`,
  `field_roles`, `field_permissions`, etc.
- It does include any `field_*` whose fieldtype lives under
  `site/modules/` (e.g. `field_customMap` for FieldtypeMapMarker).
- No errors in the log.

---

## S19 — Snapshot banner: dismiss after first restore

**Goal:** Verify the admin banner disappears after one restore, even if
tables remain in the snapshot.

**Preconditions:**
- Reset that produced a snapshot with at least 2 captured tables (e.g.
  install ProcessRedirects + ProcessChangelog, give them rows, keep
  both).

**Action:**
1. Log in. Banner is visible on every admin page.
2. Open the snapshot UI, tick **only one** of the two tables, click
   **Restore selected tables**.
3. Navigate to any admin page.

**Expected:**
- Banner is gone on every admin page.
- Snapshot UI still shows the second (unrestored) table.
- `.snapshot.bin` is still present, with `acknowledged: true` set in the
  payload and the restored table removed.
- The user can later restore the second table from the module config
  screen — banner stays gone.

---

## S20 — Snapshot UI: delete snapshot

**Goal:** Verify that **Delete snapshot** removes the file and the banner.

**Preconditions:**
- Reset that produced a snapshot.

**Action:**
1. Log in, open the snapshot UI.
2. Click **Delete snapshot**, confirm in the JS prompt.

**Expected:**
- `.snapshot.bin` is gone from the module directory.
- Banner disappears from all admin pages.
- A success notice appears.
- Log entry: `Snapshot discarded by user testadmin`.

---

## S21 — Recovery endpoint deployment + diagnostic mode

**Goal:** Verify `pwreset_repair.php` is auto-deployed and removed correctly.

**Action:**
1. Uninstall ProcessWireReset.
2. Verify `<docroot>/pwreset_repair.php` is gone.
3. Re-install ProcessWireReset.
4. Verify `<docroot>/pwreset_repair.php` exists, `chmod 0644`.
5. Open `https://your-site.tld/pwreset_repair.php` (no token).

**Expected:**
- Step 5 returns a 200 page titled "Recovery endpoint" saying "No
  recovery is currently in progress" — booleans only, no paths or
  versions disclosed.
- Re-installing while the file already exists should overwrite it cleanly.
- If the docroot is non-writable, `install()` throws with a clear
  message naming the expected path; module is not installed.

---

## Test matrix

| Scenario | Critical path coverage                                         |
| -------- | -------------------------------------------------------------- |
| S01      | Full clean reset, filesystem cleanup                           |
| S02      | Basic module preservation                                      |
| S03      | Custom DB table → snapshot + opt-in restore                    |
| S04      | Transitive site-module dependencies                            |
| S05      | Core-module dependency installation                            |
| S06      | install() side effects (admin pages, field-tables, snapshot)   |
| S07      | Custom field handling (known gap)                              |
| S08      | Custom profile resolution                                      |
| S09      | Directory traversal protection                                 |
| S10      | Snapshot file deserialization safety                           |
| S11      | Superuser credential preservation                              |
| S12      | Debug tool shutdown handler edge case                          |
| S13      | Consecutive reset state cleanup                                |
| S14      | POST-authoritative keepModules (regression)                    |
| S15      | Performance under load                                         |
| S16      | Error abort during DB phase                                    |
| S17      | Recovery via pwreset_repair.php (token-gated standard install) |
| S18      | PW-core field tables filtered from snapshot                    |
| S19      | Snapshot banner auto-dismiss on first restore                  |
| S20      | Snapshot delete                                                |
| S21      | Recovery endpoint auto-deploy + diagnostic mode                |

## Minimum smoke test

If you only have time for a few scenarios, run these in order:

1. **S01** — Clean reset works
2. **S11** — Superuser survives
3. **S03** — Snapshot capture + restore (single-table case)
4. **S04** — Dependency chain works
5. **S17** — Recovery flow (the safety net)

If all five pass, the module is functional for the common cases plus its
recovery path.
