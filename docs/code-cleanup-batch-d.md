# AssocMap - Batch D: Safe Code Cleanup

Assessment date: **2026-11-02, Asia/Manila**. Repository: `C:\dev\AssocMap-STAGING`.
Branch: `feature/member-application-r2`. Starting HEAD: `9dbba51b543a10ac7d42de35933cd6e72cdb1933`.
The working tree was clean before this batch.

Only ten PHP source files and this report changed. The corrections remove one unused import and nine unused exception-variable bindings, and organize the affected controller's imports. No handler body, exception type, method signature, query, route, validation rule or response was changed. No dependency, JavaScript, Dart, Python, migration, seeder, environment file or cloud configuration was changed.

## Inspection scope

| Area | Structure inspected | Findings / disposition |
| --- | --- | --- |
| Laravel | `web/app` (236 PHP files, 25,363 lines before cleanup), controllers, requests, domain services, models, routes, configuration, Composer and relevant tests | One unused production import and nine provably unread catch bindings corrected. Existing services and authorization boundaries retained. |
| JavaScript / frontend | `web/resources/js` (31 files, 3,771 lines), CSS entrypoint, Vite configuration, package manifests and frontend tests | Named-import scan found no confirmed unused imports. Production debug-marker scan found no matching leftover statements. No frontend edit was justified. |
| Flutter | `mobile/lib` (53 Dart files, 7,666 lines), package/configuration files, repository wiring and tests | Analyzer clean. Explicit demo repositories, unavailable-feature states and mock assets have active callers and remain. |
| Python | `reporting/python` (five runtime modules and four test/verification modules), requirements, Laravel reporting adapter references | No unused runtime import confirmed by AST name inspection. Reporting calculations, transport and exporters retained. |
| Supporting files | Tracked filenames, build/dependency references, test harnesses, relevant documentation and secret-pattern candidates | No tracked backup/log/private-key artifact was confirmed for deletion. Ignored caches, installed dependencies and existing build artifacts were left alone. |

This is a targeted source/dependency and local regression review. It is not whole-program dead-code proof, a dependency vulnerability audit, a Git-history secret audit or a review of live services.

## Exact files changed and issues corrected

Paths below are relative to the repository root; line numbers refer to the final source.

| File | Line | Confirmed correction |
| --- | --- | --- |
| `web/app/Http/Controllers/Admin/ProjectController.php` | 5 | Removed `use App\Models\Association;`, whose alias had no usage anywhere else in the file, including annotations. Organized the remaining imports. Every controller statement is unchanged. |
| `web/app/Http/Controllers/Admin/FieldOfficerAssignmentController.php` | 39 | Removed unused `$exception` from the `QueryException` catch. Assignment failure logging and JSON/redirect handling remain identical. |
| `web/app/Http/Controllers/Auth/InvitationRestartController.php` | 38 | Removed unused `$exception` from the `Throwable` catch. Recovery redirect and uncertainty guidance remain identical. |
| `web/app/Http/Controllers/MemberApplicationPhotoController.php` | 36 | Removed unused `$exception` from the photo-unavailable catch. The existing private 503 response remains identical. |
| `web/app/Services/Audit/AuditLogService.php` | 110 | Removed unused `$exception` from the snapshot-formatting catch. Snapshot limits and fallback message remain identical. |
| `web/app/Services/Auth/ProfileAuthIdentityService.php` | 24 | Removed unused `$exception` from the Auth-metadata lookup catch. Query, safe notice and null fallback remain identical. |
| `web/app/Services/Auth/UserInvitationResendService.php` | 70 | Removed unused `$exception` from the reconciliation catch. Exception union, transaction handling and reconciliation response remain identical. |
| `web/app/Services/Auth/UserManagementQueryService.php` | 166 | Removed unused `$exception` from the invitation-reference lookup catch. Lookup and safe logging remain identical. |
| `web/app/Services/DashboardService.php` | 226 | Removed unused `$exception` from the optional-widget catch. Warning and null fallback remain identical. |
| `web/app/Services/GIS/GisGeometry.php` | 22 | Removed unused `$exception` from the JSON-encoding catch. Geometry limits and validation failure remain identical. |
| `docs/code-cleanup-batch-d.md` | 1 | Added this evidence and deployment report. |

Each removed variable occurred exactly once in its original file: its declaration in the catch. The affected code does not inspect dynamic local variables. PHP supports catches without a variable within the existing supported PHP versions. The exceptions are still caught; removing an unread local name does not remove error handling.

An additional mechanical check used the installed `nikic/php-parser` to compare all ten files against their exact HEAD versions. The only permitted AST differences were the unused catch bindings and the controller import removal/order. All ten comparisons passed. The controller import set was separately verified as the original set minus `App\Models\Association`. This supports the narrow preservation claim alongside executed tests; it is not provider or database acceptance.

## Items intentionally retained

- **Dependencies:** no package was removed or installed, and all manifests/lockfiles remain unchanged. Leaflet and Geoman are imported by GIS modules; Vite, its Laravel plugin and Tailwind plugin are referenced by build configuration; `concurrently` is called by Composer's development script. Flutter maps, coordinates, HTTP, Supabase and secure-storage packages have runtime imports/plugin references. ReportLab and openpyxl are called by the exporters.
- **Dependency candidates:** `axios` appears only in `web/package.json` outside the lockfile, and `cupertino_icons` only in `mobile/pubspec.yaml` outside the lockfile. These are candidates, not approved removals. A dedicated removal must also verify installed transitive relationships, generated/native build inputs and fresh production packaging. Sanctum remains referenced by configuration and automatic package discovery; the absence of `HasApiTokens` is insufficient evidence for removal. Composer development tools also retain discovery/CLI purposes.
- **Compatibility fields and similar logic:** the controller's `implementation_date` and `implementation_start_date` projections, role-specific project navigation, validation normalization and resource handling remain. Similar shapes do not prove identical consumers, scope or compatibility requirements. No duplicated business logic was consolidated.
- **Active demo/placeholder behavior:** `RepositoryFactory` calls the explicit demo repositories; public mock assets remain declared in `pubspec.yaml`. `DemoAuthRepository` contains a clearly named sample password for synthetic `.invalid` accounts and denies sign-in in release mode. This is intentional fixture behavior, not a live credential. Unavailable photo/roster/partial-workflow messages describe current API boundaries and were not removed.
- **Tests and harnesses:** the example unit test and homepage feature smoke test are actively discovered. PostgreSQL verification fixtures, deferred SQL and historical refactor scripts retain verification/compatibility purposes. Pint also reported an unused import in `web/tests/Postgres/GisMediaVerification.php`; that native harness was left untouched because its database-provisioning verification is outside this batch's restrictions.
- **Artifacts:** installed `vendor`/`node_modules`, Flutter SDK/generated files, APKs, caches and private local configuration were not deleted or reorganized. No obsolete public feature or uncalled framework hook was inferred from a text search.

The targeted production scan covered PHP dump helpers, JavaScript console/debugger statements, Dart `debugPrint`, and TODO/FIXME/HACK/XXX/Lorem ipsum markers. No matching production statement required removal. A separate tracked-text scan reported only synthetic/demo credential-assignment candidates; reviewed matches were test/provider fakes or the explicit demo password. No real credential exposure was confirmed. Ignored local configuration, repository history, external accounts and all possible secret formats were not audited. No credential values were included in the scan output or this report.

## Verification before and after

Laravel checks used the existing `scripts/storage/verify.ps1` and `Tests\Support\TestEnvironmentGuard`. The runner clears inherited service credentials and supplies synthetic process-only settings, an uncached application, SQLite `:memory:`, array services, blocked stray Laravel HTTP, temporary storage/views/cache and PHPUnit output. Selected tests build synthetic schemas directly; no migration or seeder command was run. No shared Supabase, database, Auth, R2 or staging operation was performed.

| Check | Before | After / final result |
| --- | --- | --- |
| Laravel project/UI group | **231 tests / 3,344 assertions passed** | **231 tests / 3,344 assertions passed** |
| Laravel catch/error-path group | **310 tests / 3,038 assertions passed** | **310 tests / 3,038 assertions passed** |
| PHP syntax, ten edited files | HEAD snapshots: all passed | All ten edited files passed |
| PHP AST preservation | Exact HEAD source captured | All ten comparisons passed with only the approved differences |
| Frontend Node tests | **278 passed, 3 skipped, 0 failed** | **278 passed, 3 skipped, 0 failed** |
| JavaScript syntax | 71 source/config/test files passed | JS files unchanged; no repeated syntax run needed |
| Flutter analysis | **No issues found** | Dart source/configuration unchanged |
| Flutter regressions | One run on the unchanged mobile source, started before the PHP edits | **235 tests passed**; no second run needed for unchanged mobile components |
| Dart formatting, check only | 75 files checked; 8 existing differences | Retained; `--output=none` wrote no source files |
| Python regressions | **26 tests passed** using the existing reporting virtual environment | Python source/requirements unchanged |
| Python syntax | Nine modules compiled in memory successfully | No bytecode or source edit |
| Vite production build | No frontend changes to baseline | **Passed**, 42 modules transformed; empty environment directory and temporary output |
| Pint, check only | 214 files reported existing style differences across `app`, `routes`, `config`, `tests` | Nine of the ten edited files still report existing style differences; no new fixer categories per edited file; controller `no_unused_imports` finding cleared |
| Whitespace / scope | Clean initial working tree | `git diff --check` passed; only the ten listed PHP files and this report changed |

The Laravel groups overlap on the isolation tests; the counts above are separate runs, not a count of distinct tests. Project/UI coverage also retained its bounded-query checks, including eight queries for the Field Officer project register and nine for training registers at both fixture sizes.

Project/UI group:

```text
TestEnvironmentIsolationTest|ProjectClosureTest|AssociationProjectsTest|ProgramManagementUiTest|ProjectCoverTest
```

Catch/error-path group:

```text
TestEnvironmentIsolationTest|DashboardServiceTest|AuditLogAccessTest|ProfileAuthIdentityServiceTest|UserInvitationResendTest|UserManagementTest|GisGeometryTest|MemberApplicationPhotoControllerTest|AssociationOfficerTest|InvitationRestartTest
```

These filters were passed before and after to:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/storage/verify.ps1 -Filter '<group above>'
```

Other executed checks used `node --test` with the explicit `web/tests/Frontend/*.test.mjs` file list, `node --check`, `flutter analyze --no-pub`, `flutter test --no-pub --concurrency=1 --reporter expanded`, and Dart format with `--output=none --set-exit-if-changed`. The three Node skips require the existing optional native-browser fixture; browser acceptance was not claimed. No repository PHPStan/Psalm, ESLint/Prettier or Python lint/format configuration was found. Editor-specific PHP language-server diagnostics were not queried.

The initial system-Python run failed with four failures and one error because that interpreter lacks the report-export packages. Without installing anything, the suite passed with `C:\dev\AssocMap\.venv-reporting\Scripts\python.exe`, whose existing ReportLab **4.4.10** and openpyxl **3.1.5** match the requirements. Tests used synthetic payloads and private temporary artifacts with bytecode disabled. This verifies that local interpreter, not an unknown deployed Python runtime.

Initial Flutter launches could not write the protected shared SDK cache/telemetry paths. Successful analysis and tests used a task-owned temporary SDK cache overlay, read-through SDK links, suppressed analytics and a temporary application-data directory. No shared SDK or project configuration was edited. The first PowerShell script launch was blocked by execution policy; the successful command above used a process-only execution-policy option.

Pint remains **nonzero** for existing formatting debt; it is not reported as a clean formatting gate. Common existing categories include braces, indentation, operator spacing and import order. Default Pint can suggest import ordering after another fixer introduces an import, so the controller still has other baseline fixer findings. Dart's eight existing formatting differences are in:

```text
mobile/lib/core/errors/public_gis_failure.dart
mobile/lib/data/models/analytics_report.dart
mobile/lib/data/models/association_dashboard.dart
mobile/lib/data/models/laravel_me_profile.dart
mobile/test/analytics_report_test.dart
mobile/test/association_dashboard_test.dart
mobile/test/public_gis_api_test.dart
mobile/test/public_gis_feature_test.dart
```

Machine-local evidence is under `%TEMP%\assocmap-cleanup-d-33b606c764854d44ac4973bb8db76515`: before/after PHP and Node logs, Pint JSON, AST verification and HEAD snapshots, Flutter analysis/tests, Dart formatting, Python logs and isolated Vite build output. The PHP logs also identify their individual `assocmap-media2-*` temporary storage/cache directories. These artifacts are outside the tracked deliverable and may be removed by normal temporary-directory retention.

Not executed: the entire unrelated Laravel suite, native PostgreSQL/PostGIS or concurrency harnesses, migrations, seeders, provider calls, real-session browser workflows, Android/iOS release packaging, physical-device UAT or deployed acceptance. No database/provider behavior is inferred from SQLite or mocked mobile tests.

## Deployment and compatibility assessment

| Area | Effect of this batch |
| --- | --- |
| PHP dependencies | None. Composer manifests and lockfile unchanged. No new PHP requirement; variable-free catch syntax is already supported within the existing project requirement. |
| Flutter dependencies | None. Pubspec, lockfile, native/plugin inputs and mobile source unchanged. |
| Vite assets | No source or configuration change; no new asset build is required solely for this cleanup. The isolated production build passed without replacing `web/public/build` or the hot file. |
| Environment variables | None added, removed or changed in project files. Verification overrides existed only in child processes. |
| Hosting | Existing PHP, Python, storage, extension and child-process requirements remain. The batch does not establish that a deployed host satisfies them. |
| Routes / APIs / mobile | No route, middleware, request/response contract, serializer or mobile API change. Exception handlers retain their status codes, logs and responses. |
| Database / media / audit | No schema, SQL, lock order, transaction, Auth/session, private-photo transport or audit-persistence change. |
| Production builds | Local Vite production compilation passed. PHP syntax/tests passed on PHP 8.4.27. Mobile release builds were not repeated because no mobile/native/build input changed. |
| Rollback | Revert only these ten small PHP hunks and optionally this report using the review baseline. No schema, data, environment, dependency or asset rollback is needed. Use the established PHP deployment/OPcache refresh process only if a later deployment is authorized. |

No commit, push, branch change, deployment or shared-service mutation was performed.

## Remaining cleanup opportunities, ranked by change risk

| Change risk | Opportunity / evidence | Smallest safe follow-up and required verification |
| --- | --- | --- |
| Low | Documentation drift: `reporting/README.md` still describes mobile Auth/secure-storage integration as future work, while `mobile/lib/data/remote/api_auth_repository.dart` and `mobile/lib/core/authentication/secure_auth_storage.dart` implement it. `reporting/requirements.txt` also retains provisional-verification commentary. | Reconcile dated documentation with current source and actual acceptance boundaries. Keep historical evidence clearly dated; do not claim hosted/device acceptance. |
| Low | Trivial example unit test and the native harness's unused-import finding remain. | Review test intent separately. Keep the homepage smoke test; edit native verification files only with an approved native verification scope. Neither blocks this cleanup. |
| Low to medium | Existing Pint/Dart formatting debt: 214 PHP files and eight Dart files identified above. | Format a small selected module per batch, review the diff and rerun that module's tests. Avoid a repository-wide formatting commit. No Python/JS formatter was introduced. |
| Medium | `axios` and `cupertino_icons` have no identified direct source consumer outside manifests/locks. | Treat as candidates until dependency graphs, scripts, generated inputs, fresh Vite packaging and relevant Flutter platform builds are checked after removal. Retain Sanctum and discovered development providers unless their lifecycle/configuration uses are also removed under a separate task. |
| Medium | Repeated project navigation code in `Admin/ProjectController.php::update` and `FieldOfficer/ProjectController.php::update`; similar editor/directory projections. | First compare role-specific routes, fields and consumers. An extraction would change shared structure and deserves its own contract/regression batch. Retain compatibility date keys until all consumers are proven. |
| Medium | Large presentation files: `mobile/lib/features/public_map/public_map_screen.dart` (738 lines), `association_analytics_screen.dart` (510 lines), `member_registration_screen.dart` (473 lines); `web/resources/js/pages/admin/reports.js` (423 lines) and `pages/gis-workspace.js` (418 lines). | Consider one pure display helper or private widget extraction at a time. Preserve async state, disposal, focus/scroll behavior and request timing; verify relevant widget/frontend flows and browser/device behavior when affected. |
| High | Large business/validation files: `MonitoringService.php` (1,152 lines), `ProjectService.php` (911 lines), `StoreMonitoringRequest.php` (744 lines). Dense Python calculation/contract code also carries report invariants. | Do not split by size alone. Any future extraction must preserve transactional authorization, lock order, relationship checks, query budgets, monetary precision and report contracts, with focused isolated tests and separately authorized native concurrency checks where needed. |

No additional unreachable-code or obsolete-feature deletion met the required evidence threshold. Larger changes remain opportunities, not defects established by file size or textual similarity.
