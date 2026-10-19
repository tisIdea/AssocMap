# GIS maintenance after project finalization

Prepared 2026-10-16 in C:\dev\AssocMap. Source implementation and static review only. No application commands, tests, builds, SQL, migrations, live Supabase access, environment/credential reads, installation, commits, pushes or deployment were performed. Existing unrelated working-tree changes were preserved.

That paragraph records the original source-only implementation stage. The subsequent, separately authorized local PostgreSQL execution results are in [the local migration test report](local-postgresql-migration-test-report.md), with [a manual Supabase handoff](supabase-finalization-deployment-guide.md). No live Supabase deployment has been performed.

## Policy and root cause

Completed and terminated are irreversible project outcomes. Project profile, ownership, closure evidence, products/materials, Trainings/participants, Program Activities and Monitoring/production/Income/visits remain protected by ProjectLifecycleService and migrations 011/013/014. Historical reads retain existing role/association scope.

GIS is an approved maintenance exception. Previously GisLocationService::lockParent called assertNotFinalized for every mutation; GisWorkspaceController::edit rejected the flag; gis-workspace.js returned before rendering any controls; 014 treated GIS like an operational child. Those four restrictions have been corrected together.

| Operation | Completed / terminated GIS | Ongoing GIS |
| --- | --- | --- |
| View | Existing permissions | Existing permissions |
| Create missing geographic representation | Admin / current assigned Field Officer, eligible association/project | Existing workflow |
| Name, site address, geometry, approved public details | Allowed through existing validation/version/audit | Existing workflow |
| Publish / unpublish / existing GIS archive | Allowed through existing lifecycle action | Existing workflow |
| Change linked project/association | Denied if either source or destination project is finalized | Existing ownership/assignment checks |
| Permanently delete | Denied | Existing guarded deletion and required audit |
| Edit project status/dates/closure/operational history | Denied | Existing project rules |

Association Accounts acquire no mutation routes or privileges. Public and Account serializer projections are unchanged. Managers receive the exact project_status and a defensive finalization flag (status or closed_at); labels distinguish Completed and Terminated. Public GIS previously exposed only approved geographic details, not a project-state label, and still does. Reporting/API/mobile status contracts already distinguish the two statuses; no calculations, payload contracts or operational services were changed.

Inactive/archived associations, archived projects, archived GIS editing, current assignment, active profile/role/invitation access, PostGIS validation, duplicate/idempotency rules and publication title checks remain enforced by existing code. GIS archival preserves the record and unpublishes it; no new restore action was introduced.

## Request and data flow

The existing Blade/Leaflet form sends CSRF-protected POST/PATCH to the existing role-scoped routes. SaveGisLocationRequest validates UUIDs, supported types, bounded geometry and fields; client-supplied publication/provenance is prohibited. The service normalizes geometry, reloads/locks the actor and role, locks the actual association/project and rechecks assignment/eligibility. Update checks the old and new parent, the GIS version and archive state. Finalized parent identity cannot change. Disabled parent selectors merely explain this rule; the server enforces it independently.

The existing PostGIS write and GIS audit run in the same three-attempt transaction. All GIS mutations now take the existing per-ID advisory lock first (extended to edit/publication), reducing the store-retry-versus-edit row-order inversion. Publish/unpublish still records the authenticated actor and publication event. No GIS method updates projects or operational tables. Rejected mutations/audit failures roll back; there is no backfill, deletion, relinking or recalculation of existing history.

## Migration audit resolutions

| Finding | Source disposition | Verification still required |
| --- | --- | --- |
| MIG-01, High: every enabled DELETE trigger blocked eligible GIS deletion | assertDeletionSchema now inspects every enabled noninternal DELETE trigger. Only the exact 014 trigger shape/function identity/security metadata is accepted; other triggers fail closed. Incoming FK and DELETE/audit privilege checks remain. | Real catalog/PDO values, exact function config, unknown/name-spoofed/altered definitions and real ongoing deletion |
| MIG-02, Medium: unreadable Final Remarks | Pending 011/013 strip POSIX control/space and explicit Unicode format/filler/variation/private-use ranges, then require a printable nonspace character via [:graph:]. NULL/empty/over-2000 are rejected by the existing metadata branches. Request/service PCRE also excludes invisible fillers/variation selectors in addition to Unicode Z/C/space. Legacy completed all-null metadata remains valid; triggers require complete evidence for a new final transition. | PostgreSQL regex parsing, Unicode/locale cases and both migration stages |
| MIG-04, Medium: isolation applied to unrelated activities | 014 resolves both OLD and NEW relationships first. An empty project-ID array returns without a project isolation check. Any actual project link still requires READ COMMITTED, including linking/detaching/reassignment. | INSERT/UPDATE/DELETE under READ COMMITTED and REPEATABLE READ; other baseline triggers keep their own isolation rules |
| MIG-03, Medium conditional: inconsistent writer lock order | Existing per-ID advisory lock extended to all GIS mutations to serialize same-ID create retries, edits and publication/deletion. Other locks/retries retained; no claim of universal deadlock freedom or broad write-contract redesign. Exact scenarios below. | Two-connection tests and actual retry/error behavior |
| MIG-05, Medium conditional: hidden ancestry/provenance through RLS | Fixed pg_catalog, public, pg_temp search paths. row_security=off makes filtered integrity queries error rather than interpreting invisibility as absence. 014 retains owner preflight/private SECURITY DEFINER helpers. Parent guards remain SECURITY INVOKER with private execute ACLs and fail-closed reads. No RLS policies/client grants added. | Deployed owners, FORCE RLS, backend role and inherited privileges; failure may correctly block a wrongly configured backend |

011/013 use PostgreSQL POSIX syntax and U& Unicode escapes, not PCRE property syntax. The SQL predicate is intentionally a conservative approximation of PHP Unicode categories. Non-ASCII [:graph:] classification depends on the database collation/LC_CTYPE/platform; a C locale rejects non-ASCII-only evidence. PCRE and PostgreSQL can also use different Unicode versions. Test accented Filipino text, supported scripts and emoji on the intended database; do not claim full category equivalence. PostgreSQL text itself cannot store NUL. Invisible-only values in the supplied ASCII/Unicode regression cases must fail; visible text mixed with line breaks/format characters remains acceptable.

The approved GIS DELETE trigger must be AFTER ROW INSERT/UPDATE/DELETE (tgtype 29), origin-enabled, nonconstraint, without column filters, WHEN, transition tables or arguments. Its function OID must equal public.protect_finalized_project_history(), with PL/pgSQL, no arguments, trigger return type, SECURITY DEFINER, the expected search_path/row_security config and ownership matching projects/gis_locations. A matching name with another function/owner/config is rejected. This checks a trusted definition's catalog identity, not a cryptographic function-body attestation; a privileged owner can still replace code.

014 allows actual GIS geographic/publication/archive columns and ordinary update attribution. id, project_id, association_id, created_at and other unlisted identity fields stay fixed whenever OLD or NEW ancestry includes a finalized project. created_by changes only for the existing genuine profile ON DELETE SET NULL cleanup. GIS INSERT is allowed, DELETE is denied, and geometry/parent/publication constraints still run. Operational child rules retain only their existing genuine actor-FK cleanup exception.

## Lock order, writer policy and remaining boundaries

Laravel finalization takes actor/role shared locks, association FOR UPDATE, then project FOR UPDATE. GIS now starts every mutation with the same per-ID advisory lock, then actor FOR UPDATE/role SHARE. Edit/lifecycle/delete take the GIS row before association/project FOR UPDATE; store takes parents before the resulting GIS row. The shared advisory lock prevents a same-ID create retry and edit from reaching those opposite row orders simultaneously through this service. 014 locks indirect ancestor rows and sorted project rows FOR SHARE. Those locks serialize relevant writes with finalization under READ COMMITTED; they are retained for GIS maintenance too.

Confirmed possible deadlock schedule: A (Laravel finalization) holds association and needs project; B (direct project UPDATE) already holds project and its 013 AFTER trigger needs association. Another schedule: a direct child writer holds child/ancestor and waits for project SHARE while a project-first operational service holds project and needs that child. GIS row-first updates can participate in the same inversion with custom project-first writers. The correction does not establish one universal lock order across arbitrary SQL writers.

Prefer the trusted Laravel services as the only business writers. Authorized external maintenance writers should use READ COMMITTED, take actor/role where applicable, association rows in stable order, project rows in stable order, then descendants, keep transactions short, and retry the entire transaction after 40P01/40001 with bounded retries. Current finalization/GIS use DB::transaction(..., 3); not every older operational service has the same retry count. Do not assume retry removes every availability failure or automatically replay an uncertain commit. No transaction or lock was removed to hide a deadlock.

The helpers enforce integrity, not authenticated identity, assignment permissions or mandatory Laravel audit for privileged direct SQL. They do not grant client DML. A trusted SQL writer can supply an existing actor ID; owners/superusers/BYPASSRLS roles and trigger/TRUNCATE privileges remain administrative trust boundaries. TRUNCATE is not protected by these row triggers. Existing database publication constraints can independently restrict profile-FK cleanup for published GIS. No owner/role/grant live configuration was inspected.

SECURITY DEFINER is limited to private read/lock helpers owned by the trusted table owner. All business tables are explicitly qualified; pg_temp is last. Public schema CREATE privilege must not be available to untrusted writers. row_security=off does not bypass RLS: it throws if RLS would apply. A FORCE RLS owner without bypass can therefore fail closed and block writes until an explicitly reviewed role policy is supplied. Do not fix this by casually granting client bypass or disabling RLS. The parent invoker functions also require runtime access to their integrity dependencies; live privileges remain unknown.

References: [PostgreSQL function security](https://www.postgresql.org/docs/current/sql-createfunction.html), [RLS and row_security=off](https://www.postgresql.org/docs/current/ddl-rowsecurity.html), [pattern matching and locale](https://www.postgresql.org/docs/current/functions-matching.html), [locking/deadlocks](https://www.postgresql.org/docs/current/explicit-locking.html).

## Read-only deployment inspection queries

Prepared only; none executed. Run later through a separately authorized review, including as the actual Laravel database role (SQL Editor's role alone is insufficient). Do not paste credentials into reports.

```sql
SELECT current_user, session_user, current_database(),
       current_setting('server_version') AS server_version,
       current_setting('server_encoding') AS encoding,
       current_setting('lc_ctype') AS lc_ctype,
       current_setting('transaction_isolation') AS isolation,
       current_setting('row_security') AS row_security,
       current_setting('session_replication_role') AS replication_role;

SELECT n.nspname, c.relname, pg_get_userbyid(c.relowner) AS owner,
       c.relrowsecurity, c.relforcerowsecurity,
       has_table_privilege(current_user, c.oid, 'SELECT') AS can_read,
       has_table_privilege(current_user, c.oid, 'UPDATE') AS can_update,
       has_table_privilege(current_user, c.oid, 'DELETE') AS can_delete
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public' AND c.relname IN (
 'projects','associations','user_profiles','commodities','gis_locations','audit_logs',
 'trainings','training_participants','program_activity_records','project_products',
 'project_materials','monitoring_records','monitoring_visit_reports',
 'monitoring_production','monitoring_production_monthly','monitoring_income','monitoring_materials')
ORDER BY c.relname;

SELECT rolname, rolsuper, rolbypassrls, rolinherit, rolcanlogin
FROM pg_roles WHERE rolname IN (current_user, session_user, 'anon', 'authenticated')
 OR oid IN (SELECT proowner FROM pg_proc WHERE proname IN
 ('protect_project_completion','protect_finalized_project_history','project_history_project_ids',
  'user_has_role','validate_gis_location'));

SELECT n.nspname, p.proname, pg_get_function_identity_arguments(p.oid) AS arguments,
       pg_get_userbyid(p.proowner) AS owner, p.prosecdef, p.proconfig, p.proacl,
       has_function_privilege('anon', p.oid, 'EXECUTE') AS anon_execute,
       has_function_privilege('authenticated', p.oid, 'EXECUTE') AS authenticated_execute,
       pg_get_functiondef(p.oid) AS definition
FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
WHERE n.nspname = 'public' AND p.proname IN
 ('protect_project_completion','protect_finalized_project_history','project_history_project_ids',
  'user_has_role','validate_gis_location');

SELECT t.tgname, t.tgenabled, t.tgtype, t.tgisinternal, t.tgnargs,
       t.tgconstraint, t.tgattr, t.tgqual, t.tgoldtable, t.tgnewtable,
       t.tgfoid::regprocedure AS trigger_function, pg_get_triggerdef(t.oid) AS definition
FROM pg_trigger t WHERE t.tgrelid = 'public.gis_locations'::regclass ORDER BY t.tgname;

SELECT conrelid::regclass AS child, conname, pg_get_constraintdef(oid)
FROM pg_constraint WHERE confrelid = 'public.gis_locations'::regclass
 OR (conrelid = 'public.projects'::regclass AND conname IN
 ('projects_status_check','ck_projects_closure_metadata'));

SELECT schemaname, tablename, policyname, roles, cmd, qual, with_check
FROM pg_policies WHERE schemaname = 'public' ORDER BY tablename, policyname;

SELECT n.nspname, a.grantee, a.privilege_type
FROM pg_namespace n CROSS JOIN LATERAL
 aclexplode(COALESCE(n.nspacl, acldefault('n', n.nspowner))) a
WHERE n.nspname = 'public'; -- grantee 0 denotes PUBLIC
```

## Test source and manual QA

Tests were unexecuted at the original preparation stage; the linked local test report now records actual execution. IsolatedDatabaseManager still permits only SQLite :memory:, and TestEnvironmentGuard now also prevents loading the project credential file. GisSqliteConnection is a test-only SQLite adapter for a few PostGIS SQL expressions and catalog response fixtures. It tests Laravel decisions, transactions and rollback; it does not validate spatial computation, real catalog decoding, triggers, RLS, locks or concurrent scheduling. Those behaviors were subsequently tested in the separate, explicitly guarded local PostgreSQL harness. GisQueryService detail projection and spatial database validation are stubbed in the SQLite service tests; actual scoped reads and geometry normalization have their existing separate tests.

- GisFinalizedProjectMaintenanceTest covers Admin/assigned Field Officer creation/edit/coordinate changes/publication/unpublication for ongoing and both final outcomes; exact project/operational snapshots; edit-page reachability; idempotent retry; ownership/detachment/deletion denial; unassigned/account/stale actor/assignment denial; stale version, inactive/archived parent, invalid coordinates, existing GIS archive, approved/unknown/spoofed trigger metadata and required-audit rollback.
- GisFeatureSerializerTest preserves public/account privacy, distinguishes manager status and recognizes the defensive closure marker. ProjectClosureTest adds control/format/filler/variation/private-use-only remarks to both outcome paths. Its existing operational mutation/history assertions remain intact.
- [PostgreSQL regression source](../supabase/tests/finalization_gis_regression.sql) is separate from the Laravel suite and migration directory. It requires a unique assocmap_gis_test_YYYYMMDD_hex8 name, loopback address, exact expected database/port and an explicit synthetic-only setting. It uses the deployed remarks constraint; tests actual GIS INSERT/UPDATE/publication/delete/identity guards, old/new links, all retained operational child tables, unchanged history snapshots, and unlinked/linked Program Activity writes under READ COMMITTED/REPEATABLE READ. It rolls back and requires separately prepared complete synthetic fixtures; use a client that stops at the first error. Its local execution is recorded in the linked report; it must not be run against Supabase development data.

Browser QA after separately approved isolated setup:

1. For each final outcome, use an active association and synthetic Admin/current assigned Field Officer. Record project evidence and child values. Add a missing GIS point/boundary; retry the same creation; verify idempotency and required audit.
2. Edit name, site/public addresses/description and valid coordinates or boundary vertices. Publish, inspect only approved public projection, unpublish, and exercise the existing GIS archive action last. Compare project status/closed_at/closed_by/remarks/dates and every operational register before/after.
3. Confirm Edit/Publish/Unpublish controls remain visible on unarchived final GIS, parent selectors are locked, and Delete is absent. Direct PATCH with different project/association, detach, prohibited provenance fields or DELETE must fail without a write/audit. A posted id cannot alter the route-bound record identity. Do not rely on disabled UI alone.
4. Repeat as unassigned Field Officer, Account, guest, inactive/role-changed user and a formerly assigned officer. Revoke assignment between opening and saving. Requests must remain denied. Inactive/archived association, archived project and archived GIS edit/publication restrictions remain.
5. Repeat ongoing create/edit/publish/unpublish/delete. In scratch PostgreSQL, verify exact approved 014 metadata succeeds; add unknown trigger, same-name/different-function, changed owner/config/events/arguments/filter and unknown trigger alongside approved; eligible deletion must refuse unexpected definitions. Restore only scratch metadata afterward. Incoming FK and missing audit privilege continue blocking deletion.
6. Submit invalid coordinates, self-intersecting boundaries, empty public titles, invalid UUIDs, stale versions and duplicate request IDs with changed payloads. Verify existing errors and zero partial writes. Confirm historical reads/reports retain current scopes and exact Completed/Terminated categories.
7. On isolated PostgreSQL, test SQL remarks at stages 011 and 013: NULL, empty, whitespace, ASCII 1-31/127-159, zero-width/BOM/direction/tag controls, fillers/variation selectors/private-use, 2000/2001, newlines with visible text and non-ASCII-only text. Verify legacy completed all-null rows and genuine FK cleanup; new final rows cannot omit evidence.
8. Under READ COMMITTED, test unlinked activity CRUD, link/detach/reassign ongoing-to-ongoing, and every mutation touching old/new finalized ancestry. Under REPEATABLE READ, unlinked CRUD succeeds but any real project-linked INSERT/UPDATE/DELETE/link/detach/reassign fails 25000; include GIS maintenance and indirect Training/Monitoring ancestry. Existing baseline isolation guards remain independent.

Required two-connection tests (not proven by SQLite or sequential requests):

| Schedule | Required result |
| --- | --- |
| A saves GIS under association/project locks and pauses; B finalizes same ongoing project | B waits; committed GIS is retained and finalization then succeeds. Repeat Completed and Terminated. |
| A finalizes and holds locks; B edits/publishes GIS | B waits, then authorized GIS maintenance succeeds after A commits; final evidence remains exactly A's. Reassignment/delete must instead fail once finalization wins. |
| A edits/publishes GIS, then its required audit fails; B is waiting | A rolls back geometry/publication/audit; B sees committed state, with normal version/authorization checks. |
| A finalizes but audit fails/ROLLBACK; B edits GIS | No partial final evidence/event survives; B continues against the unchanged eligible project. |
| Two editors use same version | Only first successful save wins; second reloads after 409. Include two different authorized actors. |
| Idempotent create retry versus edit/publication/delete of the same GIS ID by different authorized actors | Existing per-ID advisory lock serializes the service operations before parent/GIS row locks; verify retries, tombstone and stale-version behavior. Direct SQL without that lock is outside this convention. |
| Operational child/indirect ancestor write races finalization, both lock orders | Write committed before finalization becomes retained history; after finalization wins the operational write fails. Observe/retry any deadlock as a whole transaction. |
| A holds association then needs project; B holds project then its finalization trigger needs association | Observe 40P01/rollback rather than claiming deadlock freedom; confirm unchanged history and bounded writer retries. |
| FORCE RLS nonbypass owner hides ancestor/profile/catalogue; unknown client grants | Integrity queries must fail closed, not interpret filtered rows as missing. Test proper owner/bypass setup separately, without adding client privileges. |

Use two explicitly authorized scratch connections and fresh synthetic projects for each schedule. Pause with an open transaction before COMMIT/ROLLBACK; do not approximate concurrency with sequential HTTP calls. Also test ancestor deletion/cascades, existing BEFORE triggers, actor/commodity FK null cleanup and full rollback. Owner/TRUNCATE bypass is an administrative-boundary test, not a claim that these row triggers prevent it.

## Deployment sequence and status

Implementation status: targeted source changes prepared; runtime/PostgreSQL verification and deployment NOT DONE. No extra feature decision is needed for the approved GIS exception. Executing tests, preparing a scratch database, migrations and deployment require separate authorization. If any environment already applied an earlier 011/013/014, stop: these are approved corrections to unapplied files, not permission to replay applied migrations or rewrite history; plan a separate forward migration.

1. Confirm environment migration ledger and ownership/grants through a separately authorized review. Never replay 001-009 against existing data. Keep 010 as its comment-only no-op placeholder; never restore Association Termination. 012 is unchanged.
2. First verify the corrected sources in an isolated PostgreSQL/PostGIS environment with the intended database version/locale/roles; keep Laravel's SQLite guard intact. Review catalogs and run approved browser/SQL/concurrency cases only with separate execution permission.
3. Use a maintenance window that stops writes, workers and older application processes. In order, deploy corrected pending 011, unchanged 012, corrected 013, corrected 014, then the matching Laravel/UI source before reopening writes. Apply through the project's approved migration process, not this document.
4. Never expose the new application before closure columns exist; GIS manager queries now also select closed_at. Do not open finalization while 013/014 are missing. Old 014 blocks allowed final GIS maintenance; old Laravel rejects the known DELETE trigger even with corrected 014. Corrected Laravel without 014 retains service guards but lacks the prepared database child-history protections. A mixed/partially migrated window can therefore produce errors or protection gaps; keep writes paused throughout.
5. Verify expected catalogs, permissions, ongoing GIS deletion, both final outcomes and unchanged historical data before reopening. Retain backups and the deployment's reviewed recovery plan; do not downgrade final statuses or delete history as rollback.

Unrelated known Program Activity 'ongoing' rejection in the Python report contract remains outside this correction. No Monitoring Income synchronization, Flutter authentication, invitations, reports calculations or association lifecycle statuses were modified.

## Exact task inventory

Existing source modified (relative paths; earlier unrelated working-tree edits retained):

- web/app/Services/GisLocationService.php
- web/app/Http/Controllers/GisWorkspaceController.php
- web/app/Services/GisQueryService.php
- web/app/Services/GisFeatureSerializer.php
- web/app/Http/Requests/CloseProjectRequest.php
- web/app/Services/ProjectClosureService.php
- web/resources/js/pages/gis-workspace.js
- web/resources/js/pages/gis-editor.js
- web/resources/views/gis-editor.blade.php
- web/resources/views/projects/partials/finalization-modal.blade.php
- supabase/migrations/011_add_permanent_project_closure.sql
- supabase/migrations/013_add_permanent_project_finalization.sql
- supabase/migrations/014_protect_finalized_project_history.sql
- web/tests/Feature/ProjectClosureTest.php
- web/tests/Unit/GisFeatureSerializerTest.php
- docs/project-closure.md
- docs/project-finalization-qa.md
- docs/association-termination.md
- docs/project-finalization-files.md
- docs/production-monitoring.md

New source:

- web/tests/Support/GisSqliteConnection.php
- web/tests/Feature/GisFinalizedProjectMaintenanceTest.php
- supabase/tests/finalization_gis_regression.sql
- docs/gis-finalization-maintenance.md

010 and 012 were not edited. No other feature source was changed for this task.

## Capstone explanation

Finalization records an irreversible business outcome and freezes operational evidence. Geographic reference information can still be wrong or require publication changes afterward, so authorized GIS maintenance has a narrow exception. Laravel checks the current actor, assignment, eligible parents, version and geographic input, then saves GIS and its audit atomically. Database triggers independently protect final operational ancestry and GIS identity. A coordinate correction cannot reopen the project, transfer its ownership or recalculate its historical results. This design reuses the existing architecture without weakening the operational lifecycle rule; real PostgreSQL concurrency and deployed permissions remain to be tested.
