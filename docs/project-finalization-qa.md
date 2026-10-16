# Project finalization manual verification

Prepared 2026-10-16. No steps below have been executed by Codex. Use synthetic records and an isolated environment first. Live SQL, migrations, test execution, builds and provider access were excluded from implementation.

## Prerequisites and rollout

1. Review the current schema/migration ledger for the intended environment. The project owner reports 010, 011 and 012 unapplied in development; that is supplied evidence, not a live check performed here.
2. Never execute withdrawn 010. Do not renumber or replay older migrations. Review and apply pending source in this order, only through a separately authorized deployment: 011, 012, 013, 014.
3. Review 014's trusted function owner, private execute permissions, backend isolation level and existing RLS/grants. No client DML privileges should be added. Use the [GIS correction checklist, catalog queries and concurrency cases](gis-finalization-maintenance.md); all remain unexecuted.
4. Confirm the final source and complete migration set are deployed together. Do not open finalization while closure columns or the terminated constraint are missing.
5. Review the added isolated PHP/Python regression source and obtain separate authorization before executing it. The SQLite test guard must not be weakened to target Supabase.

## Browser and permissions

- As Admin, open /admin/associations. Default shows unarchived rows; Active/Inactive filter within unarchived; Archived shows archived rows. There is no Terminated filter or action. View historical officers; archive/unarchive preserves status. Unarchive then reactivate using the existing status editor; operational writes become eligible only when active and unarchived.
- As assigned Field Officer, open an ongoing unarchived project under an active/unarchived association. Both Complete Project and Terminate Project appear as separate actions. Repeat as Admin.
- Each dialog describes its outcome and the permanent linked-history restriction. Keyboard focus enters the dialog; labels are readable; Cancel/Escape works before submission; focus returns to the invoking action. Backend errors reopen only the affected dialog. Unrelated form errors open neither.
- Submit wrong case, missing, padded, newline-padded and array confirmation values directly over HTTP. Completion accepts only COMPLETED; termination only TERMINATE. A checkbox or forged status cannot select an outcome.
- Reject missing, whitespace-only, zero-width-only, oversized and array remarks. Accept visible remarks at the documented limit, preserve line breaks and escape HTML. Confirm the opposite dialog does not inherit those remarks/errors.
- Unassigned officers, inactive profiles/roles, Association Accounts, guests and stale-role requests are denied. Repeat through direct URLs/HTTP requests, not just visible buttons.
- Reject finalization from planned, suspended, cancelled, archived projects or inactive/archived associations. No partial final status, evidence or audit event should exist after a rejection.

## Successful outcomes and retained history

Use a different ongoing synthetic project for each outcome.

- Completion stores completed; termination stores terminated. Both store server administrative closed_at, the authorized closed_by, meaningful closure_remarks and update provenance, with one matching audit event. Only Admin retains audit-viewer access.
- The actual implementation_end_date, general project remarks, association ownership/status, resources and historical child records stay unchanged. No actor/date/remarks are fabricated for existing completed rows.
- View project details and linked history through existing authorized scopes. Completed and Terminated labels are distinct; successful completion statistics do not include terminated.
- Inspect project products/materials, trainings/participants, Program Activities, Monitoring overview/visits, quarterly production, all three monthly rows, Income and Monitoring materials before/after: all business values remain identical.
- Reject project profile/status/association edits, reopening, project archive/restore, inline resource registration, training participant changes, activity edits, operational source detachment/relink and new operational linked records. GIS is the explicit exception: allow authorized geographic edits, coordinate corrections, publish/unpublish and existing GIS archive; allow missing representations. Reject finalized GIS reassignment/detachment/deletion and changes to creation identity. Verify Admin/current assigned Field Officer only; preserve inactive/archived association/project restrictions and Account read-only access.
- Reject Monitoring creation, overview, visits, full/section edits, monthly production changes, Income replacement and material replacement through direct requests and stale open forms.
- Other ongoing projects and activities/trainings without a finalized project remain operational under their normal association and role rules.

## Two-connection PostgreSQL checks

These are required isolated integration scenarios, not assertions proven by the SQLite test source.

- Start two finalization requests against the same version: Complete vs Terminate, Complete vs Complete and Terminate vs Terminate. Hold the first project's locks before commit. The second must wait, then observe the final state and fail; exactly one final status and one event survive.
- Repeat with the first transaction rolling back because its required audit insert fails. There must be no partial finalization or event; the waiting eligible operation may then proceed.
- Race finalization with actor deactivation, role changes, association inactivation/archive, Field Officer reassignment and project reassignment. Verify current authorization, ownership and state are rechecked. Observe deadlock retries/failure responses rather than assuming all lock orders are deadlock-free.
- Race direct linked-child writes with finalization under READ COMMITTED. A child write committed first becomes preserved history; after finalization wins, the child statement must fail. Repeat for descendant paths through production and training participants, including attempts to reparent between projects.
- Reject project-linked child writes at stronger isolation levels rather than relying on an old snapshot. Confirm unlinked Program Activity INSERT/UPDATE/DELETE and ordinary reads/report snapshots at REPEATABLE READ remain unaffected. Linking, detaching and reassignment inspect both OLD and NEW links and retain isolation safeguards.
- Test every 014 table for INSERT, UPDATE, DELETE, relink/detach and old/new ownership. Include legacy completed parents and existing retained links with earlier ownership mismatches. Failed cascades must roll back completely.
- Verify legitimate actor FK null cleanup and commodity FK cleanup from existing relationships. Ordinary edits that impersonate cleanup must fail. Do not delete real users or catalogue records to perform these checks.
- Test existing updated_at, commodity/category, training/project, activity/project, monthly-production and Income triggers together with 013/014. Verify no new RLS/client grants and no bypass caused by hidden ancestors.
- Direct SQL finalization still requires privileged access and can supply an actor identifier; the database does not authenticate that person's session or insert the Laravel audit automatically. Verify deployed privileges prevent untrusted use. Owners/superusers, disabled triggers and TRUNCATE remain privileged bypasses requiring operational controls.

## Reports, API and mobile

- In a dataset without the separately known ongoing Program Activity defect, preview/export project and Comprehensive reports after each outcome. Verify a distinct terminated portfolio group, unchanged historical Monitoring statuses and unchanged production/monthly/Income totals. Inspect PDF/XLSX manually.
- A valid ongoing Program Activity can still be rejected by Python's existing activities allowlist. That independent audit defect was intentionally not corrected here and may still cause report unavailability.
- Inspect Admin, Field Officer and Association Account dashboards within their existing scopes. API chart labels and Dart DTO strings should display Terminated without changing totals or role permissions.
- Do not enable real Flutter login as part of verification for this source task.

## Outstanding decisions

Collecting actual implementation_end_date during finalization requires a separate approved rule and forward migration amendment. Additional transitions from planned/suspended/cancelled are not implemented. Existing ongoing-project reassignment/history mismatches and stale Income/material replacement remain separate audited work.
