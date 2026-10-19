# Withdrawn association termination requirement

Permanent Association Termination was withdrawn on 2026-10-16 after the project owner corrected the requirement. Associations use only their existing active/inactive and archive/unarchive lifecycle. They can be reactivated through the existing authorized workflow; inactive or archived associations still reject operational writes.

Migration 010 was confirmed unapplied by the project owner. The current [010 file](../supabase/migrations/010_add_permanent_association_termination.sql) is a comment-only no-op placeholder. It contains no association-termination DDL. The previously described .sql.txt relocation is not present in the inspected repository. Retain the number; never restore or execute the withdrawn termination SQL. Numbers 011 and 012 remain unchanged.

The mistaken application workflow, routes, controls, requests, service, model attributes, queries, audit producer and dedicated termination tests have been removed. No database rollback, column drop, record update or history deletion is needed under the supplied unapplied-migration context. If another environment applied 010, stop and assess that environment separately; this task does not authorize destructive rollback.

See [current project finalization policy](project-closure.md). Permanent completion and termination apply to projects, not associations.
