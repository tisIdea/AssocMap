# Project completion and termination

Current approved policy, 2026-10-16. Source implementation only; migrations and tests were not executed.

## Outcomes and evidence

Only an ongoing, unarchived project in an active, unarchived association can be finalized. Completed means successful implementation completion; terminated means permanent discontinuation. Planned, suspended and cancelled retain their existing meaning and cannot finalize directly. Nothing converts existing cancelled rows to terminated.

Both outcomes use the existing fields `closed_at` (administrative finalization time), `closed_by` (responsible actor) and `closure_remarks` (Final Remarks). Status distinguishes the outcome; no duplicate termination columns or new lifecycle table are added. Existing completed rows with null evidence remain readable and immutable. No legacy dates, actors or remarks are fabricated.

Final Remarks must include visible text, contain at most 2,000 characters, and are trimmed before persistence. They are escaped in authorized detail views and hidden from default model serialization. Full remarks are not copied into audit snapshots, public GIS or mobile/report payloads.

The existing actual `implementation_end_date` is unchanged. Collecting it atomically during finalization needs a separate organizational decision on requiredness and a forward amendment to the profile-change whitelist inherited from 011. It is never set to the administrative timestamp automatically. A known actual date can be recorded through ordinary editing while the project remains eligible.

## Web and authorization

Existing Admin and Field Officer project details and register now provide separate Complete Project and Terminate Project actions. Completion requires exact `COMPLETED` in `completion_confirmation`; termination requires exact `TERMINATE` in `termination_confirmation`. Both fields bypass Laravel TrimStrings. The request and service independently reject wrong case, whitespace padding, arrays and missing confirmation. The previous accepted-checkbox contract no longer finalizes a project.

Existing completion POST routes ending in /close remain; separate /terminate POST routes were added for the same two roles. The outcome is chosen by the controller/service method, never a posted status. CSRF/session and role middleware remain. Association Accounts gain no mutation or historical-read privileges.

Each dialog uses its own projectClosure or projectTermination error bag, visible error list, accessible labels, warning, focus restoration, cancel action and exact-word input. Invalid remarks are safely redisplayed only in the affected dialog. Unrelated global errors do not open either dialog. JavaScript disables repeat submission and resets confirmation after page restoration; server-side locking is authoritative.

ProjectClosureService::close and ::terminate share one private transaction. Lock order remains actor profile and role (shared), association, project. The service reloads application access and unchanged role, checks current Field Officer assignment, checks ownership, operational association, ongoing/unarchived state and the expected microsecond/timezone updated_at version. A final status or closure marker produces 409. Stale versions produce 409. Business validation remains 422; authorization remains 403. Safe infrastructure failure feedback asks the user to reload before retrying an uncertain commit.

Only project final status/evidence and update provenance change. CLOSE PROJECT / project_closure remains the completion audit contract. Termination emits TERMINATE PROJECT / project_termination. Both use Project Management, allowlisted identifiers/status/evidence/actor-role snapshots, and a required audit insert in the same transaction. Audit failure rolls back the project and event. Three-attempt transaction retry conventions are retained. No external provider call occurs in finalization.

## Historical protection

Project::isFinalized and ProjectLifecycleService::assertNotFinalized / ::lockNotFinalized protect both final outcomes and any closure marker. Legacy completed status alone is sufficient.

- Project profile/status/ownership edits, archive/restore, products and materials are blocked. Ordinary forms and services cannot create either final state or set it through the status editor.
- Trainings and participants cannot be created under, edited, archived/restored or detached/reassigned away from a finalized project. Both source and destination project guards remain.
- Linked Program Activities have the same read-only rule. Unlinked association-wide and Admin program-wide activities remain governed by their normal rules.
- Monitoring creation, overview/section edits, visits, monthly production, quarterly production, Income and material writes all reach the existing project lock/guard before mutation. No totals are reset or recalculated by finalization.
- GIS is the approved exception: authorized creation of missing representations, geographic edits, publish/unpublish and existing GIS archive actions remain available for completed/terminated projects. Existing association/project eligibility and assignment checks remain. Finalized GIS cannot change project/association ownership or be deleted; project operations and closure evidence remain immutable. Manager controls show the outcome and retain edit/publication. See [GIS correction and migration review](gis-finalization-maintenance.md).
- Historical reads keep their existing role and association scopes. Admin audit viewing remains Admin-only. Existing Association Account access denials remain unchanged.
- Register edit/archive controls for finalized projects and linked training/activity records are unavailable; Monitoring removes edit availability and creation choices.

Project reassignment with retained training/activity ownership mismatches remains an independently audited issue for ongoing projects. This task does not silently move those records. Lifecycle locking resolves actual project owners and rejects writes to retained links once that project is finalized. Income/material stale-form replacement behavior on operational projects remains separately scoped; finalized projects cannot reach those saves.

## Database migration source

Never replay 001 or apply withdrawn 010. Under the supplied current environment, 001–009 already exist and 010–012 are unapplied. The remaining order is:

1. `011_add_permanent_project_closure.sql` — adds closure evidence and completion protection; its pending remarks predicate now rejects control/invisible-only evidence.
2. `012_allow_zero_production_activities.sql` — unchanged: production activity-count policy only.
3. `013_add_permanent_project_finalization.sql` — extends the existing projects_status_check with terminated, preserves all five prior statuses, amends the closure metadata constraint and replaces the existing completion function while retaining its trigger. Legacy completed all-null metadata remains valid; terminated requires evidence. Parent inserts cannot start finalized; only ongoing/unarchived transitions are permitted. No profile/date/association changes can accompany finalization. Existing finalized status, evidence and business profile cannot change or be deleted. Existing actor/commodity ON DELETE SET NULL cleanup exceptions remain. The operational association and valid active Admin/current assigned Field Officer evidence are checked at transition.
4. `014_protect_finalized_project_history.sql` — adds linked-table protection for 12 tables: project_products, project_materials, trainings, training_participants, program_activity_records, monitoring_records, monitoring_visit_reports, monitoring_production, monitoring_production_monthly, monitoring_income, monitoring_materials, gis_locations.

All migration numbers are retained. Pending 011/013 remarks checks and 014 have approved source corrections; 012 is byte-for-byte unchanged. Migration 010 is a comment-only no-op placeholder, not deployable association-termination SQL. There are no backfills, record rewrites, cascade changes, new association statuses or RLS policy/grant expansion.

014 resolves old AND new project ownership, including both monitoring-parent and product/material references. Ancestor shared locks stabilize relationship lookups. Sorted project shared locks conflict with finalization updates; the guard rechecks final status after waiting under READ COMMITTED. AFTER triggers inspect the final row after existing BEFORE validation/timestamp triggers; an exception rolls back the statement, including cascades. Null project links leave unrelated training/activity/GIS records operational.

014's two private SECURITY DEFINER helpers perform qualified integrity reads/locks with search_path = pg_catalog, public, pg_temp and row_security = off. A trusted table owner is required; execute is revoked from PUBLIC/anon/authenticated. row_security = off fails when RLS would filter a query; it does not grant bypass. Parent invoker guards also fail closed on filtered integrity reads. Genuine actor-FK null cleanup remains allowed. GIS has an explicit maintenance-column exception while retaining identity/ownership. Shared catalogue and quarter maintenance are not redesigned; actual owners, RLS and runtime grants require isolated verification.

**Pending verification:** all migration SQL is unexecuted. Verify trigger ordering, constraint names, SQL/PLpgSQL syntax, owners, privileges/RLS, FK cleanup and rollback on an isolated PostgreSQL/Supabase schema before deployment. Never enable finalization against an unmigrated schema. Deploy the migrations and corresponding source together in a controlled change window; do not expose only 011 between steps.

## Concurrency limits and direct writers

The same project row serializes completion versus termination, duplicate requests, and supported service mutations; one finalization wins and the later valid request returns conflict. Fresh actor and association locks serialize role/assignment changes. Legacy browser forms are rejected by status and version checks.

Direct SQL writers may lock child/ancestor rows in an order different from Laravel and encounter deadlocks; PostgreSQL must abort one transaction rather than permit history corruption. Existing Laravel transactions retry supported deadlocks. External writers must use READ COMMITTED and consistent lock ordering/retry. Real simultaneous races cannot be verified by the existing SQLite suite.

Parent/child triggers are integrity guards, not proof of the authenticated user's identity or a database-enforced audit requirement. Privileged SQL can supply an existing actor ID; Laravel is the authorization/audit boundary. Trusted owners/superusers can disable triggers or use TRUNCATE; deployed grants and maintenance access must be reviewed. Trigger bypass, shared catalogue semantics and audit immutability remain outside an absolute database-history guarantee.

## Reporting, API and mobile

DashboardService already groups current project statuses independently, so terminated gets its own portfolio category and is not counted as completed. No aggregate calculations changed. Python's project/monitoring-status allowlist now recognizes terminated; analytics continue grouping by exact status and retain production/Income values and saved monitoring status. The separate ongoing Program Activity contract defect remains unresolved and can still break Activities/Comprehensive reports when such rows are extracted.

Laravel dataset/API current status fields remain strings. Existing Dart dashboard chart labels and monitoring-status DTO fields accept backend strings; public GIS does not parse a fixed project-status enum. No mobile authentication changes were made. Actual endpoint/browser/mobile behavior remains unverified.

## Regression and verification status

AssociationLifecycleTest exercises an association listing against fixtures without termination columns, normal filtering, route withdrawal, and existing archive/reactivation service rules. ProjectClosureTest covers both outcomes, authorized Admin/assigned Field Officer, exact-word/visible-remarks validation, final evidence, history snapshots, audit rollback, inactive/revoked actors, denied account/unassigned officer, parent eligibility, stale/duplicate/opposite outcomes, mutation denial and escaped/scoped history. ProductionMonitoringTest now treats terminated as a project state and retains monthly-value protection cases. DashboardServiceTest keeps final portfolio outcomes separate; GisFeatureSerializerTest limits the finalization UI flag to authorized managers without expanding public/account data. Python analytics test source checks a distinct terminated category, unchanged monitoring snapshot and unchanged production/Income sections.

These sources were not run. SQLite does not validate PostgreSQL triggers, locking, RLS or true concurrent transactions. Follow [manual verification](project-finalization-qa.md), including two-connection races and direct child writes in an isolated database. Source edits are prepared; runtime and database deployment are pending.

See [exact change inventory](project-finalization-files.md).
