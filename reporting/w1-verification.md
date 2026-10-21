# W1 report download diagnosis and verification

Verified October 20, 2026, on `development`. Verdict: **VERIFIED LOCALLY / READY FOR BROWSER ACCEPTANCE**.

## A. Root cause and scope

No current PDF/XLSX download defect was reproduced in local HTTP-kernel integration. The existing engine, controller, frontend download handler and reporting formulas were preserved. The older `python_not_configured` finding does not establish the current staging runtime's state; this run explicitly configured the existing reporting virtual environment only inside isolated tests.

The earlier evidence gap was real browser acceptance. This task closes the local HTTP response and file-readability gap, but authenticated browser downloads through Cloudflare remain unverified. Local HTTP-kernel requests do not exercise a browser, socket transfer, the live identity provider or the staging PHP process.

Association Accounts are intentionally view-only in the current implementation. `ReportScope::actor(..., true)` denies their exports before extraction/Python. Their own-association preview succeeds. No permission was expanded.

## B. Files added

| File | Purpose |
| --- | --- |
| `supabase/tests/local/report-download.php` | Guarded real-route HTTP regression runner using synthetic PostgreSQL data, the actual Python engine, enforced CSRF, response checks, revocation tests and measured timings |
| `reporting/python/tests/verify_http_exports.py` | Reopens HTTP response bodies; reuses existing canonical XLSX/PDF verification and adds exact scope/filter metadata, PDF cell values and cross-association checks |
| `reporting/w1-verification.md` | Diagnosis, executed commands, evidence limits and browser acceptance handoff |

No production file was changed. SHA256 comparison confirmed all 88 pre-existing modified/untracked files remained unchanged. The Git index remained empty; no staging, commit, push, reset, stash or branch change occurred. Both geography flags remain false. No shared database, environment credential file, migration 017/018, staging service or Cloudflare configuration was changed. No package was installed.

## C. Browser-to-file flow

1. `web/resources/views/admin/reports/index.blade.php`, included by `reports/page.blade.php`, renders a GET filter form and a separate POST export form with `@csrf`, applied filters and PDF/XLSX button `formaction` URLs. Association Accounts receive no export controls.
2. `web/resources/js/pages/admin/reports.js` initializes the workspace and `report-download.js`. Filter changes invalidate exports; a successful preview refreshes the hidden applied filters. Duplicate pending exports are suppressed and controls expose `aria-busy`.
3. `report-download.js` sends `FormData` using POST, same-origin session credentials, `redirect: error` and `Accept: application/json`. Success is read as a Blob after checking the actual PDF/XLSX MIME type and nonempty size. Redirect/network/HTML/JSON/error responses produce feedback instead of files. Safe server filenames are used; object URLs are revoked. Success says that download was requested, without claiming a file was saved.
4. `web/routes/web.php` maps each role prefix to `ReportController::export`, with format allowlisting, the reporting guard, existing Supabase session/role middleware and separate read/export throttles. Laravel's web middleware validates CSRF.
5. `ReportRequest` validates report types, scalar/range/UUID filter shapes, supported filter names, report-specific applicability and quarter/month compatibility. `ReportScope` rereads profile/role and resolves current authorized associations.
6. `ReportService::generate` extracts `ReportDataset` inside PostgreSQL `REPEATABLE READ READ ONLY`, with scope checked inside the snapshot and a fixed statement timeout. Each child register is aggregated independently. Python receives only the scoped, bounded server-selected JSON contract.
7. `PythonReportEngine` invokes the approved absolute executable using Symfony Process's argument-array API, with a fixed entrypoint, isolated Python flags and restricted environment. It enforces per-user/global locks, output/time/size bounds and expected artifact names/paths/signatures. `reporting/python/main.py` validates the contract; `exporters.py` renders the canonical result with ReportLab/openpyxl.
8. The engine reads the bounded artifact (maximum 12 MiB), validates it, and removes its private UUID workspace in `finally`. The existing response owns the bytes, so removal does not delete a file still needed by a stream. This is bounded in-memory delivery, not a persistent file URL or a file-backed streaming response.
9. `ReportService` rechecks current profile, role, link/assignment and scope after Python. `ReportController` returns the raw bytes with attachment filename, exact MIME type, content length, private/no-store caching and `nosniff`. No HTML/JSON wrapper is added to a successful download.

The private workspace root is `web/storage/app/private/reporting`; no public URL is created. `CleanupReportWorkspaces` handles old UUID orphans left by hard termination; it was inspected but not executed or scheduled. Live Windows NTFS permissions were not audited.

## D. Executed checks

| Check | Final result |
| --- | --- |
| Existing Laravel report authorization/UI/filter/rate-limit suite | 40 tests, 349 assertions, zero failures/errors |
| Existing report frontend tests | 15 passed, zero failed/skipped |
| Existing Python calculation/transport/export tests | 24 passed, zero failed |
| Disposable fixture safety | 16 checks, zero failed |
| Existing native PostgreSQL/Laravel/Python reporting runner | 17 checks, zero failed |
| New real-route HTTP runner, final complete invocation | 39 checks, zero failed |
| Reopen existing native exports | 16 checks, zero failed; 16 files |
| Reopen HTTP exports plus scope/filter/PDF-cell verification | 78 checks, zero failed; 52 files |
| PHP syntax and `git diff --check` | Passed; Git emitted only existing line-ending notices |

The eight catalog types are `comprehensive`, `associations`, `members`, `projects`, `trainings`, `production`, `income`, and `activities`. Each was exported in both PDF and XLSX for Admin and Field Officer (32 files), and with an empty authorized scope (16 files). Two filtered Income files retained association, project, year, quarter and month. Two Project Portfolio files included 500 additional synthetic projects, which were removed afterward with the exact pre-volume operational snapshot restored.

HTTP checks require correct status, MIME, attachment filename/extension, content length, cache/privacy headers, file signature, zero unexpected output, and SHA256 identity with the real engine artifact. `sendContent()` must emit exactly those same bytes. All files are reopened using pypdf/pdfplumber/openpyxl; verification checks canonical XLSX detail/summary cells, types, formula safety, PDF readability/headings/page bounds, scope and filter metadata, and PDF cell values. Representative Comprehensive, filtered Income, empty Income and larger Project Portfolio pages were rendered with Poppler and visually inspected; this is not an exhaustive visual inspection of every page.

The first Laravel invocation had 33 setup errors because the isolated test environment had no encryption key. The successful rerun supplied a process-only synthetic key; no application key or environment file was changed. Early new-runner failures were corrected in the runner's bootstrap, standalone assertions, negative-case rate isolation and synthetic fixture restoration. The final full invocation passed all 39 checks. Production code was not changed to satisfy these tests.

Executed commands from `C:\dev\AssocMap` unless indicated:

```powershell
# Existing isolated Laravel suite; cwd=C:\dev\AssocMap\web
$env:APP_KEY='base64:QUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUE='
php vendor/phpunit/phpunit/phpunit tests/Feature/ReportAuthorizationTest.php --no-progress
node --test tests/Frontend/report-feedback.test.mjs tests/Frontend/reports-workspace.test.mjs

# Repository root; existing reporting and verification runtimes
& reporting/.venv/Scripts/python.exe -I -B -m unittest discover -s reporting/python/tests -p 'test_*.py'
php supabase/tests/local/safety.php C:/dev/.codex-tmp/assocmap-pg-20261020_d76293b0/run.json 'C:/Program Files/PostgreSQL/18/bin/psql.exe'
php supabase/tests/local/migrate-pending.php C:/dev/.codex-tmp/assocmap-pg-20261020_d76293b0/run.json
php supabase/tests/local/reporting.php C:/dev/.codex-tmp/assocmap-pg-20261020_d76293b0/run.json
php supabase/tests/local/report-download.php C:/dev/.codex-tmp/assocmap-pg-20261020_d76293b0/run.json
& C:/Users/Dea/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe -I -B reporting/python/tests/verify_exports.py C:/dev/.codex-tmp/assocmap-pg-20261020_d76293b0/export-evidence
& C:/Users/Dea/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe -I -B reporting/python/tests/verify_http_exports.py C:/dev/.codex-tmp/assocmap-pg-20261020_d76293b0/http-export-evidence
php -l supabase/tests/local/report-download.php
git diff --check
```

These record the executed target, not permission to reuse a stopped manifest. The fresh cluster was initialized by the task-local `C:\dev\.codex-tmp\report-w1\start.ps1` / `prepare.php` helpers, with synthetic Auth stubs, actual baseline 001-006/008-009, isolated framework prerequisites, 007, guarded fixture safety and 011-014. Neither geographic migration was applied. The private cluster was stopped after verification. Follow `supabase/tests/local/README.md` and prepare another verified fresh target before a future run. The HTTP runner accepts an optional second substring argument for focused checks and is deliberately excluded from normal SQLite PHPUnit discovery.

## E. Security evidence

Admin visibility, current Field Officer assignment isolation and Association Account ownership/preview-only permissions passed. Cross-association filters, wrong roles, invalid filters, wrong methods and missing CSRF were denied. The new runner forces CSRF validation even in its testing environment. Real Python exports were denied with JSON 403 when assignment or active profile access changed immediately before release, for both formats. Private workspaces were empty afterward. Pure exports preserved the full operational snapshot.

Only the external Supabase identity lookup was stubbed; actual session middleware, database profiles, role checks, request validation, controllers, report services and Python remained active. Stray Laravel HTTP requests were blocked. This verifies application authorization with synthetic identities, not live Supabase authentication/RLS. Error responses do not contain Python reasons, tracebacks or application paths. The process receives a fixed executable/entrypoint with JSON over stdin, not user-built shell commands. Server allowlists choose paths and filenames. Raw datasets, credentials, tokens and process stderr are not logged by the reporting path.

## F. Measured performance and limits

For the final 32 standard synthetic HTTP exports:

| Metric | Minimum | Maximum | Mean |
| --- | --- | --- | --- |
| HTTP kernel, milliseconds | 811.32 | 2057.58 | 1357.11 |
| SQL execution, milliseconds | 13.11 | 38.61 | 23.51 |
| Python bridge, milliseconds | 778.50 | 2013.24 | 1313.87 |
| Response `sendContent()` to a local buffer, milliseconds | 0.007 | 0.119 | 0.029 |
| SQL queries per request | 15 | 26 | 18.5 |

The 500-additional-project PDF took 2313.71 ms (109,444 bytes); XLSX took 1798.64 ms (24,630 bytes). These are single synthetic local observations, not production percentiles. SQL totals include profile/scoping/release queries; pure dataset CPU time was not separately isolated. Python-bridge time includes process startup, calculation/rendering, validation, artifact reading and cleanup. Buffer emission is not network delivery. Bootstrap and a real identity-provider round trip are not represented.

The Python bridge dominates these local request measurements. Source inspection also shows distinct dependent-option requests and repeated authorization/scope reads, but their real provider/network cost and staging filter latency remain unmeasured. No filter-performance defect was reproduced or optimized in this task.

Existing limits remain unchanged: Python 45 seconds, SQL statements 10 seconds, extraction deadline checked between queries, per-dataset/total row bounds, two engine slots and separate export throttling. CLI PHP reported `max_execution_time=0`, `memory_limit=512M` and no disabled functions; staging's web-process INI was not inspected. Actual Tunnel/origin deadlines remain unverified. [Cloudflare's connection-limit guidance](https://developers.cloudflare.com/fundamentals/reference/connection-limits/) distinguishes Tunnel origin settings; generic proxy limits do not prove this site's configured budget. No timeout was increased.

## G. Staging checks

Unauthenticated read-only requests were made to `https://staging.assocmap.app/admin/reports`, `/login` and the public JS asset. Reports redirected once to the HTTPS login page; final status was 200 in 1.718 seconds. Login returned `Cache-Control: no-cache, private`, `CF-Cache-Status: DYNAMIC` and HTTPS asset URLs.

`/build/assets/app-CTmDrKUw.js` returned 200/application/javascript and contains the report download handler. Its bytes matched the local existing build exactly: SHA256 `EE55A71C8E25DB8F4AC94FD779C7FEE3CCB9F176ED4BC601652A81F818C0E4F8`. An initial text comparison differed because of response text decoding; the byte comparison resolved it. Initial sandbox requests could not connect; the permitted read-only network check succeeded outside that sandbox.

No authenticated staging request, real report dataset query, staging export POST, download, deployment, cache refresh or service restart was performed. Public asset equality does not prove the deployed backend or reporting executable configuration.

## H. Manual browser acceptance

Use your own normal session; do not share passwords, cookies, bearer tokens or complete private response bodies. No supported-browser matrix was found in the inspected documentation. Record the browser/version used for the demonstration and repeat in each browser the team intends to support.

1. **Administrator:** sign in to staging, open Reports & Analytics, apply a known Association/project/period, compare preview totals with known authorized records, and export PDF then XLSX. Repeat for all eight report types. Observe the six-exports-per-minute limit: pause at least a minute after each six exports; also pace preview/options requests. Open each downloaded file and check title, scope, filters, filename/extension, Unicode and numeric values. Confirm XLSX cells are usable numeric/date cells.
2. **Field Officer:** repeat all types/formats for an assigned active Association. Verify choices and file contents exclude unassigned/inactive/archived Associations. Use a separately authorized test fixture to submit an unassigned Association ID; expect 403 and no file. Do not alter shared assignments merely for this acceptance check.
3. **Association Account:** confirm only its own Association is shown and no Export PDF/XLSX controls exist. A direct export attempt must be denied with 403; another Association's scope must be denied. These are the current intended permissions.
4. In browser DevTools Network, inspect export POST status, MIME, attachment disposition and private/no-store cache policy. Confirm the actual Downloads entry exists and both files open. A 200 status alone is insufficient. Do not copy authenticated request headers into reports/chat.
5. Verify pending controls prevent duplicate submissions; changing filters requires applying them again; errors are understandable and do not create a `.pdf`/`.xlsx` containing HTML or JSON. Check empty periods and an invalid filter using approved test data. Session expiry should lead to visible sign-in/error feedback and no fake download. Reassignment/deactivation race tests already passed locally; use an isolated acceptance fixture if repeating them.

## I-J. Release decision and smallest next action

**READY FOR BROWSER ACCEPTANCE.** Local application authorization, real Python generation, HTTP binary responses, file readability and private cleanup are verified. A production code repair was unnecessary based on the reproduced behavior.

The remaining gate is one normal authenticated staging browser session for Admin/Field Officer, followed by the role checklist above. If a download fails, record only report type/format, non-sensitive filters, status, MIME, elapsed time and sanitized reporting stage/reason. That evidence will distinguish environment configuration, dataset generation, HTTP delivery, frontend handling and Tunnel behavior. Do not deploy, restart services, expose a debug endpoint or change timeouts based only on the older audit.
