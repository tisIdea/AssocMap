# Deployment Readiness Checklist and Manual Verification

Audit date: 2026-10-13. **No deployment was attempted. Source implementation is not production readiness.** Effective environment/credentials, hosting, database state, provider settings and backups were not inspected. Cloudflare Quick Tunnel access is user-reported temporary development/testing exposure; bootstrap trusts loopback proxy addresses only.

Priorities: P0 = production sign-off gate; P1 = resolve before broad organizational use; P2 = planned improvement. All current runtime rows are Pending Verification unless explicitly marked source-confirmed.

## Readiness checklist

| Requirement | Current source evidence | Verified/unverified status | Risk if unresolved | Required manual action | Priority |
|---|---|---|---|---|---|
| Approved release/module scope | Reports/user-admin UI/mobile gaps documented in module inventory | Source-confirmed gaps; scope unapproved | Promised functions unavailable | Obtain explicit scope acceptance or implement approved gaps and test | P0 |
| Dependency advisories | Manifest/locks exist; user reported two critical npm findings without advisories | Unverified security report | Unknown vulnerable dependency paths | Run npm/Composer audits personally; assess actual advisories and proposed updates | P0 |
| Stable hosting/domain | No project deployment runbook/pipeline found; Quick Tunnel user context | Unverified | Temporary origin/callback disruption | Select/approve hosting separately; stable DNS and TLS certificate; do not deploy from this audit | P0 |
| Public-only document root | public/index.php loads app from above public | Source expectation; actual web server unknown | Exposing source/config/storage/vendor | Serve only web/public; block secret/source files and disable directory indexing | P0 |
| Production env/debug/URL/key | config/app.php env production/debug false defaults, URL env-dependent | Effective settings unknown | Error disclosure, incorrect URLs, encryption loss | Confirm APP_ENV production, APP_DEBUG false, correct HTTPS URL, stable protected APP_KEY; never copy key into reports | P0 |
| HTTPS, proxy and host policy | bootstrap/app.php trusts 127.0.0.1/::1 and forwarded host/proto/IP headers | Source loopback trust only | Wrong cookies/callbacks/rate identities or header trust | Review actual proxy chain; trust exact required proxies; verify forwarded headers/host allowlist | P0 |
| Sessions/cookies | Database session default; HttpOnly true, SameSite lax; secure env-dependent/encrypt false default | Source defaults only | Token/session exposure or lost sessions | Confirm HTTPS Secure cookie, HttpOnly/SameSite policy, session encryption/protected store and TTL | P0 |
| PostgreSQL connection/TLS | pgsql config; sslmode default prefer; GIS requires PostgreSQL/PostGIS | Server/cert behavior unknown | Wrong database/unenforced certificate trust | Confirm approved TLS validation, network restrictions and correct driver; check privileges without recording credentials | P0 |
| Database schema/migration histories | SQL 001-007 plus Laravel infrastructure/status migrations | Source known; deployed history unknown | Missing tables, status errors, rejected Auth/profile access | Compare schema-only definitions/history; approved backup and ordered one-time plan; no automatic replay | P0 |
| RLS/grants/function permissions | SQL scripts enable RLS/revoke client access/helpers; server ownership choices not automatic | Live policies/owners/default grants unknown | Direct client exposure or app access failure | Verify anon/authenticated denial, trusted server access, helper revokes and least privilege | P0 |
| Audit history/tombstone retention | GIS deletion checks retained audit event; model append guards only | DB immutability/retention unknown | Replay restoration and history tampering | Approve retention and role privileges; test required INSERT and denial of unintended UPDATE/DELETE | P0 |
| Core GIS workflows | Project-only scoped writes/read/public allowlist; actual DELETE privilege/FK/trigger gate | Source implemented; runtime untested | Invalid geometry, unauthorized access or blocked deletion | Execute PostGIS/read/write/rollback/concurrency and role tests before sign-off | P0 |
| Invitation Auth/SMTP/callback | Provider invitation API, fragment token_hash page, query discard; no Laravel Mail producer | Provider contract/settings/delivery unknown | Inactive accounts cannot activate or ambiguous identity outcomes | Verify provider template/redirect allowlist/key format, sender/SMTP/delivery and actual end-to-end link; don't assume MAIL_MAILER controls provider invites | P0 |
| Persistent activation/session locks | Context permits persistent stores and route blocking; default activation cache database | Effective cache/session tables/locks unknown | Replay, failed activation/recovery, multi-instance lost context | Confirm persistent shared store and lock permissions; file storage only with approved single-host semantics | P0 |
| PHP/extensions | Composer requires ^8.2; PostgreSQL, encryption/mbstring/http used | Installed platform unknown | Bootstrap/runtime errors | Run platform requirement checks; ensure pdo_pgsql and PostgreSQL TLS, required Composer extensions, mbstring/OpenSSL; SQLite for isolated tests | P0 |
| Production frontend assets | Vite entries and locked libraries; public/build ignored | Current build/manifest not checked | Broken imports/map/editor/CSS | Reproducible approved lockfile build; upload public/build; ensure no stale public/hot development reference; test all role pages | P0 |
| Core functional/UAT evidence | Isolated test source, no current execution artifacts | Not Executed | Static-only false confidence | Execute test plan and retain redacted current-source evidence | P0 |
| Member transition race | approve/reject pending checks not serialized (F-005) | Source gap confirmed | Contradictory membership state | Approve atomic transition patch and concurrent tests | P1, gate if decision workflow in release |
| Full audit coverage | Current application producer is GIS only | Source-confirmed coverage gap | Untraceable account/org changes | Approve event catalogue and transactional producers for released modules | P1, gate for complete-audit claims |
| Logging/alerting | Single/stack debug-level source defaults; sensitive flows log sanitized codes | Effective retention/alerts unknown | Disk growth, missed faults, disclosure | Protected rotating logs, privacy/retention rules, sanitized error/correlation metrics and alert ownership | P1 |
| Backups/recovery | No project-specific backup/restore evidence found | Unverified | Irrecoverable records/spatial/audit data | Define RPO/RTO, provider backup capability, encrypted access and staging restore drill; preserve deletion tombstones | P0 |
| Storage/filesystem | Private/public disks and writable runtime folders configured | Permissions/path handling unknown | Unwritable app or unintended exposure | Least-privilege storage/framework/logs and bootstrap/cache permissions; public files intentionally scoped; no nonexistent upload claims | P1 |
| Queue/scheduler | Scaffold queue tables/config and dev queue listener; no business job dispatch/schedules found | Framework capability only | Unnecessary workers or assumed async email | Provision workers only for actual approved jobs; current Auth invites are synchronous provider calls; console has inspire only | P2 |
| Mobile API/CORS | /api/v1/me only; counter Flutter starter | Scope incomplete | Unsupported promised Android workflows | Approve API contracts/token storage; review allowed web origins only when needed; native Android is not automatically a browser-CORS client | P1, scope-dependent gate |
| External tiles | OSM browser tile URL/attribution; error notice/list fallback | Availability/performance untested | Missing basemap; metadata transfer to tile provider | Verify provider usage/attribution, availability and approved policy; retain list/GeoJSON fallback | P1 |
| Accessibility/responsiveness | Labels, keyboard maps/dialogs, responsive CSS and status states present | No browser/device audit executed | Unusable workflows for some users | Keyboard/focus/screen reader/contrast and mobile-size checks; collect actual evidence | P1 |

## Safe release sequence — proposed, not executed

1. Freeze an approved release scope and obtain current source identity and dependency audit artifacts. Do not equate this Markdown package with release approval.
2. Review consequential patches and schema/privilege proposals separately. The only source cleanup in this audit is an unused import removal.
3. Validate dependencies/platform and produce assets in a controlled build environment. Composer lock and Node lock are inputs; do not run blind upgrades.
4. Provision an approved staging origin, secrets, Supabase provider configuration, database schema and persistent stores through a reviewed plan. Verify callbacks and infrastructure access before inviting anyone.
5. Complete core automated/integration/browser/UAT, privacy, performance and recovery checks. Every test needs actual results/evidence.
6. Produce installation/rollback/runbook and operator ownership; only then request an explicit production deployment approval.

The composer setup script copies environment templates, generates an application key, runs forced migrations and installs/builds frontend dependencies. It is **not** a reviewed production migration/deployment runbook and must not be used blindly against existing data/keys.

## Manual technical commands (user only)

These commands were **not run** by the agent. Use the original project in an approved development/staging context. Before tests, confirm no production cached config is being used and that phpunit.xml isolation is honored. No migration, seeder, live Auth provisioning or deployment command is included here.

```powershell
Set-Location C:\dev\AssocMap\web
php -v
php -m
node --version
npm --version
composer validate --strict
composer check-platform-reqs
composer audit --format=json
npm audit --json
Get-ChildItem app,routes,bootstrap,config,database\migrations,tests -Recurse -File -Filter *.php |
    Where-Object FullName -NotMatch '\\bootstrap\\cache\\' |
    ForEach-Object { php -l $_.FullName }
Get-ChildItem resources\js -Recurse -File -Filter *.js |
    ForEach-Object { node --check $_.FullName }
node --check vite.config.js
php artisan route:list --path=login
php artisan route:list --path=invitation
php artisan route:list --path=admin
php artisan route:list --path=field-officer
php artisan route:list --path=association
php artisan route:list --path=map
php artisan route:list --path=api
php artisan view:cache
npm run build
php artisan test --filter=Gis
php artisan test --filter=Invitation
php artisan test
```

view:cache/build generate cache/assets; run only in the approved verification environment. Route list success proves route registration, not business queries/authorization. PHPUnit's current config chooses SQLite memory and isolated provider fixtures; it does **not** exercise real PostgreSQL/PostGIS/RLS/SMTP. Do not use npm audit fix --force as an unreviewed remedy.

Mobile checks are separate and will currently verify only the starter app:

```powershell
Set-Location C:\dev\AssocMap\mobile
flutter --version
dart --version
flutter analyze
flutter test
flutter build apk --release
```

A successful starter build would not establish functional mobile GIS/monitoring. No Flutter command was executed by the agent.

## Operational decisions still required

Hosting/provider, network topology, domain/callback policy, runtime DB role, session encryption/cookie policy, API rate limits, approved migration ordering, backup RPO/RTO, audit retention/actor deletion, provider reconciliation procedure and release exclusions all require responsible-owner decisions. Source defaults are not accepted production configuration. No credentials or confidential account information should be pasted into the reports or command evidence.

