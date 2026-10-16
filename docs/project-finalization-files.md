# Project lifecycle correction: exact task paths

This inventory records the earlier lifecycle correction. The subsequent approved GIS exception and pending-migration remediation have their own [exact source inventory and verification status](gis-finalization-maintenance.md). Historical 011 hashes below describe the earlier task, not the revised pending source.

Workspace: C:\dev\AssocMap. Inventory is relative to the working tree at the start of this task, not merely Git HEAD. Existing uncommitted/untracked work was retained; some corrected files now match HEAD again.

Source changes were prepared only. No PHP/Laravel/Python/Flutter/npm commands, tests, SQL, migrations, provider connections, builds, environment edits, Git commits/pushes or deployment were performed.

## Modified (52)

- [web/app/Http/Controllers/Admin/AssociationController.php](../web/app/Http/Controllers/Admin/AssociationController.php)
- [web/app/Http/Controllers/AssociationOfficerController.php](../web/app/Http/Controllers/AssociationOfficerController.php)
- [web/app/Http/Controllers/Admin/ProjectController.php](../web/app/Http/Controllers/Admin/ProjectController.php)
- [web/app/Http/Controllers/FieldOfficer/ProjectController.php](../web/app/Http/Controllers/FieldOfficer/ProjectController.php)
- [web/app/Http/Controllers/ProjectClosureController.php](../web/app/Http/Controllers/ProjectClosureController.php)
- [web/app/Http/Controllers/Admin/TrainingController.php](../web/app/Http/Controllers/Admin/TrainingController.php)
- [web/app/Http/Controllers/FieldOfficer/TrainingController.php](../web/app/Http/Controllers/FieldOfficer/TrainingController.php)
- [web/app/Http/Controllers/Admin/ProgramActivityController.php](../web/app/Http/Controllers/Admin/ProgramActivityController.php)
- [web/app/Http/Controllers/FieldOfficer/ProgramActivityController.php](../web/app/Http/Controllers/FieldOfficer/ProgramActivityController.php)
- [web/app/Http/Controllers/MonitoringWorkspaceController.php](../web/app/Http/Controllers/MonitoringWorkspaceController.php)
- [web/app/Http/Requests/CloseProjectRequest.php](../web/app/Http/Requests/CloseProjectRequest.php)
- [web/app/Models/Association.php](../web/app/Models/Association.php)
- [web/app/Models/Project.php](../web/app/Models/Project.php)
- [web/app/Services/AssociationLifecycleService.php](../web/app/Services/AssociationLifecycleService.php)
- [web/app/Services/AssociationService.php](../web/app/Services/AssociationService.php)
- [web/app/Services/AuditLogService.php](../web/app/Services/AuditLogService.php)
- [web/app/Services/ProjectClosureService.php](../web/app/Services/ProjectClosureService.php)
- [web/app/Services/ProjectLifecycleService.php](../web/app/Services/ProjectLifecycleService.php)
- [web/app/Services/ProjectService.php](../web/app/Services/ProjectService.php)
- [web/app/Services/MonitoringService.php](../web/app/Services/MonitoringService.php)
- [web/app/Services/TrainingService.php](../web/app/Services/TrainingService.php)
- [web/app/Services/ProgramActivityService.php](../web/app/Services/ProgramActivityService.php)
- [web/app/Services/GisLocationService.php](../web/app/Services/GisLocationService.php)
- [web/bootstrap/app.php](../web/bootstrap/app.php)
- [web/routes/web.php](../web/routes/web.php)
- [web/resources/js/app.js](../web/resources/js/app.js)
- [web/resources/js/pages/project-closure.js](../web/resources/js/pages/project-closure.js)
- [web/resources/views/associations/officers.blade.php](../web/resources/views/associations/officers.blade.php)
- [web/resources/views/associations/officers-index.blade.php](../web/resources/views/associations/officers-index.blade.php)
- [web/resources/views/components/data-table.blade.php](../web/resources/views/components/data-table.blade.php)
- [web/resources/views/components/status-badge.blade.php](../web/resources/views/components/status-badge.blade.php)
- [web/resources/views/admin/shared/index.blade.php](../web/resources/views/admin/shared/index.blade.php)
- [web/resources/views/admin/audit-logs/show.blade.php](../web/resources/views/admin/audit-logs/show.blade.php)
- [web/resources/views/projects/show.blade.php](../web/resources/views/projects/show.blade.php)
- [web/resources/views/admin/projects/index.blade.php](../web/resources/views/admin/projects/index.blade.php)
- [web/resources/views/field-officer/projects/index.blade.php](../web/resources/views/field-officer/projects/index.blade.php)
- [web/resources/views/admin/trainings/index.blade.php](../web/resources/views/admin/trainings/index.blade.php)
- [web/resources/views/admin/program-activities/index.blade.php](../web/resources/views/admin/program-activities/index.blade.php)
- [web/tests/Feature/AssociationLifecycleTest.php](../web/tests/Feature/AssociationLifecycleTest.php)
- [web/tests/Feature/ProjectClosureTest.php](../web/tests/Feature/ProjectClosureTest.php)
- [web/tests/Feature/ProductionMonitoringTest.php](../web/tests/Feature/ProductionMonitoringTest.php)
- [web/tests/Feature/DashboardServiceTest.php](../web/tests/Feature/DashboardServiceTest.php)
- [reporting/python/contracts.py](../reporting/python/contracts.py)
- [reporting/python/tests/test_analytics.py](../reporting/python/tests/test_analytics.py)
- [docs/association-termination.md](../docs/association-termination.md)
- [docs/project-closure.md](../docs/project-closure.md)
- [docs/production-monitoring.md](../docs/production-monitoring.md)

- [web/app/Services/GisQueryService.php](../web/app/Services/GisQueryService.php)
- [web/app/Services/GisFeatureSerializer.php](../web/app/Services/GisFeatureSerializer.php)
- [web/app/Http/Controllers/GisWorkspaceController.php](../web/app/Http/Controllers/GisWorkspaceController.php)
- [web/resources/js/pages/gis-workspace.js](../web/resources/js/pages/gis-workspace.js)

- [web/tests/Unit/GisFeatureSerializerTest.php](../web/tests/Unit/GisFeatureSerializerTest.php)

## Created (6)

- [web/app/Http/Requests/TerminateProjectRequest.php](../web/app/Http/Requests/TerminateProjectRequest.php)
- [web/resources/views/projects/partials/finalization-modal.blade.php](../web/resources/views/projects/partials/finalization-modal.blade.php)
- [supabase/migrations/013_add_permanent_project_finalization.sql](../supabase/migrations/013_add_permanent_project_finalization.sql)
- [supabase/migrations/014_protect_finalized_project_history.sql](../supabase/migrations/014_protect_finalized_project_history.sql)
- [docs/project-finalization-qa.md](../docs/project-finalization-qa.md)
- [docs/project-finalization-files.md](../docs/project-finalization-files.md)

## Removed incorrect workflow source (6)

- `web/app/Http/Controllers/AssociationTerminationController.php`
- `web/app/Http/Requests/TerminateAssociationRequest.php`
- `web/app/Services/AssociationTerminationService.php`
- `web/resources/js/pages/association-termination.js`
- `web/resources/views/associations/termination.blade.php`
- `web/tests/Feature/AssociationTerminationTest.php`

## Withdrawn migration

Current state: [010](../supabase/migrations/010_add_permanent_association_termination.sql) is a comment-only no-op placeholder. The earlier .sql.txt historical-reference path is absent. Retain migration numbering and do not restore association-termination DDL.

Unchanged at the earlier task boundary and not renumbered (011 subsequently receives the approved pending remarks correction):

- `supabase/migrations/011_add_permanent_project_closure.sql`
  SHA-256: D480A88C162E0BC8B3C9F27827E006872AFC1C9735852C5D3447E7ECC578A2E3
- `supabase/migrations/012_allow_zero_production_activities.sql`
  SHA-256: 765364B07BB60C67891927CF2D780B0262365D2C6BC6709FDAAA8B0905D842AD

See [implementation and limitations](project-closure.md) and [manual verification](project-finalization-qa.md).
