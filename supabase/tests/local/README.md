# Disposable local PostgreSQL harness

These scripts make real schema and record changes. Run them only through a separately approved, newly prepared disposable PostgreSQL cluster with synthetic data. Never use a Supabase endpoint, an application database, or a restored real-data backup.

`ScratchDatabase.php` requires an explicit `C:\dev\.codex-tmp\assocmap-pg-YYYYMMDD_hex8\run.json` manifest, loopback identity, an integer port from 1024 through 65535, and a matching canonical data directory inside that run. It does not create the cluster or prove that its contents are synthetic. Keep manifests, passwords, pgpass files, database files, worker files, logs and generated reports outside the repository.

Prepare the existing baseline prerequisites through the isolated harness before loading fixtures: synthetic Auth UUID stub, test roles, migrations 001–006 and 008–009, framework prerequisites, then 007. Do not replay this setup on an existing application database. Run fixture safety coverage before loading `fixtures.sql` or applying 011–014:

```powershell
php .\supabase\tests\local\safety.php '<verified disposable run.json path>' 'C:\Program Files\PostgreSQL\18\bin\psql.exe'
```

The runner validates the target before invoking `psql` with explicit host, port, database, user and the scratch passfile. Normal fixture execution must use `psql -X -v ON_ERROR_STOP=1 -v ON_ERROR_ROLLBACK=off` through that verified harness. Do not load `fixtures.sql` directly in a database editor or an unverified connection. Its `BEGIN` precedes all safety checks and writes; a rejected check leaves the transaction aborted, so the final `COMMIT` cannot retain fixture writes. Do not enable automatic savepoint recovery (`ON_ERROR_ROLLBACK=on`) around these checks.

`monitoring-materials.php <verified fresh run.json>` is a separate Materials runner for an empty disposable database. It creates synthetic prerequisites, installs the exact Materials table/project validator and relevant 014 history guards, and executes the actual 016 SQL locally. It supplies the existing Income column through the fixture and never executes 015. It tests real constraints, three two-connection lock races, finalized history and rollback; results are written beside the manifest. See `docs/monitoring-materials-concurrency.md` for executed evidence and scope limitations. Do not run it after the general fixtures or against an existing schema.

Only the regression runner's intentional negative cases use `ON_ERROR_STOP=0`, with automatic error rollback disabled, to prove that continued statement processing cannot commit rejected fixtures. It then checks a valid fixture load with stop-on-error enabled and verifies that replay is rejected without changing records. The runner leaves successful synthetic fixtures in the disposable database and writes its evidence under the scratch run directory.
