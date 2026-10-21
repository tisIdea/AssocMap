# Disposable local PostgreSQL harness

`association-geography.php <verified fresh run.json>` checks the Batch 1 geographic SQL in a newly created empty disposable database. It uses the exact baseline geographic, Association and registration table definitions, an ancillary synthetic profile stub, and migrations 017 plus the deferred 018 script. It never boots Laravel or reads application environment files. It exercises expansion compatibility, rejected approval/conflict/orphan cases, transactional backfill preservation, final constraints and existing uniqueness. This is focused schema verification, not a full Supabase/RLS or module integration test. The approved cutover setting is used only inside that disposable session; it does not authorize deployment.

`geography-integration.php <verified fresh run.json>` requires a separate empty target and the trusted local PostgreSQL 18 `psql.exe`. It prepares synthetic Auth roles/data and actual application/framework prerequisites, runs fixture safety and migrations 011-016, then compares actual Laravel service reads before/after 017/018. It checks State 3 writes, State 4 combinations, child/geometry preservation, scoped GIS, publication privacy, Python analytics/PDF/XLSX generation, authorization rechecks and two concurrent writers. Its internal worker mode is launched only by the runner on the same verified target. It uses isolated application configuration, never the project's `.env`, and writes evidence, generated exports and worker files beside the manifest. The configured repository reporting virtual environment must already exist; no dependencies are installed. These checks do not prove shared Supabase RLS, HTTP/browser downloads, device sign-in or hosted latency.

After a successful integration run, reopen its synthetic exports with an existing verification Python runtime containing `openpyxl`, `pypdf` and `pdfplumber`:

```powershell
& '<existing verification python.exe>' -I -B reporting/python/tests/verify_exports.py '<run directory>/export-evidence'
```

This verifies exact XLSX cells/types/formula safety and PDF readability, Unicode, headings and page bounds. Browser appearance and downloads still require real-session acceptance.

These scripts make real schema and record changes. Run them only through a separately approved, newly prepared disposable PostgreSQL cluster with synthetic data. Never use a Supabase endpoint, an application database, or a restored real-data backup.

`ScratchDatabase.php` requires an explicit `C:\dev\.codex-tmp\assocmap-pg-YYYYMMDD_hex8\run.json` manifest, loopback identity, an integer port from 1024 through 65535, and a matching canonical data directory inside that run. It does not create the cluster or prove that its contents are synthetic. Keep manifests, passwords, pgpass files, database files, worker files, logs and generated reports outside the repository.

Prepare the existing baseline prerequisites through the isolated harness before loading fixtures: synthetic Auth UUID stub, test roles, migrations 001–006 and 008–009, framework prerequisites, then 007. Do not replay this setup on an existing application database. Run fixture safety coverage before loading `fixtures.sql` or applying 011–014:

```powershell
php .\supabase\tests\local\safety.php '<verified disposable run.json path>' 'C:\Program Files\PostgreSQL\18\bin\psql.exe'
```

The runner validates the target before invoking `psql` with explicit host, port, database, user and the scratch passfile. Normal fixture execution must use `psql -X -v ON_ERROR_STOP=1 -v ON_ERROR_ROLLBACK=off` through that verified harness. Do not load `fixtures.sql` directly in a database editor or an unverified connection. Its `BEGIN` precedes all safety checks and writes; a rejected check leaves the transaction aborted, so the final `COMMIT` cannot retain fixture writes. Do not enable automatic savepoint recovery (`ON_ERROR_ROLLBACK=on`) around these checks.

`monitoring-materials.php <verified fresh run.json>` is a separate Materials runner for an empty disposable database. It creates synthetic prerequisites, installs the exact Materials table/project validator and relevant 014 history guards, and executes the actual 016 SQL locally. It supplies the existing Income column through the fixture and never executes 015. It tests real constraints, three two-connection lock races, finalized history and rollback; results are written beside the manifest. See `docs/monitoring-materials-concurrency.md` for executed evidence and scope limitations. Do not run it after the general fixtures or against an existing schema.

Only the regression runner's intentional negative cases use `ON_ERROR_STOP=0`, with automatic error rollback disabled, to prove that continued statement processing cannot commit rejected fixtures. It then checks a valid fixture load with stop-on-error enabled and verifies that replay is rejected without changing records. The runner leaves successful synthetic fixtures in the disposable database and writes its evidence under the scratch run directory.
