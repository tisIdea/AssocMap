<?php

// Included only after the canonical runner verifies its fresh task-owned cluster.
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

$deletionStart = count($checks);
$deletionTables = ['gis_locations', 'media_photos', 'audit_logs'];
$storedVariants = Storage::disk('media_private')->allFiles();
verify(DB::selectOne('SELECT current_user AS actor')->actor === 'assocmap_gisclass_app', 'deletion uses the non-owner backend role');
$historyTrigger = DB::selectOne("SELECT tgtype, tgenabled, tgfoid = to_regprocedure('public.protect_finalized_project_history()') AS correct_function
    FROM pg_trigger WHERE tgrelid='public.gis_locations'::regclass AND tgname='trg_protect_finalized_project_history' AND NOT tgisinternal");
verify($historyTrigger !== null && $historyTrigger->tgtype === 29 && $historyTrigger->tgenabled === 'O' && $historyTrigger->correct_function,
    'canonical finalized-history trigger remains attached and enabled for deletion');
$newDeletionLocation = function () use ($locations, $payload, $officer, $request): array {
    return $locations->store(array_replace($payload, ['idempotency_key' => (string) Str::uuid(),
        'site_type_id' => null, 'location_name' => 'Synthetic permanent-delete fixture']), $officer, $request)['feature'];
};
$expectDeletionFailure = function (Closure $operation, string $label, string $message,
    string $class = ValidationException::class, ?int $status = null): void {
    try {
        $operation();
        throw new RuntimeException('Expected deletion rejection: '.$label);
    } catch (Throwable $error) {
        verify($error instanceof $class, $label.' rejects with '.$class);
        if ($error instanceof ValidationException) {
            verify(str_contains(implode(' ', $error->errors()['deletion'] ?? []), $message), $label.' explains the deletion blocker');
        }
        if ($status !== null) {
            verify($error->getStatusCode() === $status, $label.' denies with HTTP '.$status);
        }
    }
};
$unchangedDeletion = function (array $before, string $label) use ($deletionTables): void {
    verify(snapshot($deletionTables) === $before, $label.' preserves all GIS, photo and audit rows');
};

foreach ([$officer, $admin] as $actor) {
    $feature = $newDeletionLocation();
    $before = snapshot(['media_photos']);
    $locations->destroy($feature['id'], $feature['properties']['version'], $actor);
    verify(! DB::table('gis_locations')->where('id', $feature['id'])->exists(), $actor->role->code.' deletes eligible location with the canonical media FK present');
    $audit = DB::table('audit_logs')->where('entity_type', 'gis_location')->where('entity_id', $feature['id'])->where('action_type', 'DELETE GIS')->get();
    verify($audit->count() === 1 && $audit[0]->actor_user_id === $actor->id && $audit[0]->module === 'GIS Mapping',
        $actor->role->code.' deletion commits exactly one required actor-bound audit');
    verify(snapshot(['media_photos']) === $before, 'successful deletion does not alter any photo history');
}

foreach (['reserved', 'processing', 'uploading', 'uncertain', 'ready', 'deleting', 'deleted'] as $state) {
    $feature = $newDeletionLocation();
    $photoRow = photo('gis_location_id', $feature['id'], ['state' => $state]);
    DB::table('media_photos')->insert($photoRow);
    $before = snapshot($deletionTables);
    $expectDeletionFailure(fn () => $locations->destroy($feature['id'], $feature['properties']['version'], $officer),
        $state.' photo history', 'photo history');
    $unchangedDeletion($before, $state.' photo history denial');
    rejectedSql(fn () => DB::table('gis_locations')->where('id', $feature['id'])->delete(), $restrictViolation, 'media_photos_gis_location_id_fkey');
    $unchangedDeletion($before, $state.' direct SQL FK denial');
    if (in_array($state, ['ready', 'deleted'], true)) {
        $archived = $locations->lifecycle($feature['id'], 'archive', $feature['properties']['version'], $officer, $request);
        verify($archived['properties']['archived_at'] !== null && ! $archived['properties']['is_published'], $state.' photo history still permits Archive');
        verify(DB::table('media_photos')->where('id', $photoRow['id'])->value('state') === $state, 'Archive retains '.$state.' photo metadata');
        verify(DB::table('audit_logs')->where('entity_id', $feature['id'])->where('action_type', 'ARCHIVE')->count() === 1
            && DB::table('audit_logs')->where('entity_id', $feature['id'])->where('action_type', 'DELETE GIS')->count() === 0,
            'Archive is audited without a successful deletion audit for '.$state.' history');
    }
}

$feature = $newDeletionLocation();
$pdo->exec('CREATE TABLE public.gis_delete_unexpected_dependency (location_id UUID REFERENCES public.gis_locations(id))');
try {
    foreach ([false, true] as $hasChild) {
        if ($hasChild) {
            $pdo->prepare('INSERT INTO public.gis_delete_unexpected_dependency VALUES (?)')->execute([$feature['id']]);
        }
        $before = snapshot($deletionTables);
        $expectDeletionFailure(fn () => $locations->destroy($feature['id'], $feature['properties']['version'], $officer),
            'unrecognized '.($hasChild ? 'populated' : 'empty').' incoming FK', 'gis_delete_unexpected_dependency_location_id_fkey');
        $unchangedDeletion($before, 'unrecognized FK denial');
        verify((int) $pdo->query('SELECT count(*) FROM public.gis_delete_unexpected_dependency')->fetchColumn() === (int) $hasChild,
            'unrecognized dependency rows are preserved');
    }
} finally {
    $pdo->exec('DROP TABLE public.gis_delete_unexpected_dependency');
}

foreach (['completed', 'terminated'] as $status) {
    $feature = $newDeletionLocation();
    DB::beginTransaction();
    try {
        DB::table('projects')->where('id', PROJECT)->update(['status' => $status, 'closed_at' => now(),
            'closed_by' => ADMIN, 'closure_remarks' => 'Synthetic native deletion finalization']);
        $before = snapshot($deletionTables);
        $expectDeletionFailure(fn () => $locations->destroy($feature['id'], $feature['properties']['version'], $officer),
            $status.' project', 'permanently read-only');
        $unchangedDeletion($before, $status.' project service denial');
        rejectedSql(fn () => DB::table('gis_locations')->where('id', $feature['id'])->delete(), '23514');
        $unchangedDeletion($before, $status.' project native trigger denial');
        $archived = $locations->lifecycle($feature['id'], 'archive', $feature['properties']['version'], $officer, $request);
        verify($archived['properties']['archived_at'] !== null, $status.' project retains geographic Archive maintenance');
    } finally {
        DB::rollBack();
    }
}

$feature = $newDeletionLocation();
$before = snapshot($deletionTables);
$otherOfficer = UserProfile::with('role')->findOrFail(REPLACEMENT);
$expectDeletionFailure(fn () => $locations->destroy($feature['id'], $feature['properties']['version'], $otherOfficer),
    'unassigned officer deletion', '', HttpException::class, 404);
$unchangedDeletion($before, 'unauthorized deletion');
DB::beginTransaction();
try {
    DB::table('user_profiles')->where('id', OFFICER)->update(['auth_sessions_valid_after' => now()]);
    $expectDeletionFailure(fn () => $locations->destroy($feature['id'], $feature['properties']['version'], $officer),
        'stale officer deletion', '', HttpException::class, 403);
    $unchangedDeletion($before, 'stale-session deletion');
} finally {
    DB::rollBack();
}

// Change privileges only on this task-owned role, restoring them before data comparison.
foreach ([['gis_locations', 'DELETE'], ['audit_logs', 'INSERT'], ['media_photos', 'SELECT']] as [$table, $privilege]) {
    $feature = $newDeletionLocation();
    $before = snapshot($deletionTables);
    $pdo->exec('REVOKE '.$privilege.' ON public.'.$table.' FROM assocmap_gisclass_app');
    try {
        $expectDeletionFailure(fn () => $locations->destroy($feature['id'], $feature['properties']['version'], $officer),
            'missing '.$table.' '.$privilege, $table === 'media_photos' ? 'Database permissions' : $privilege.' on '.$table);
    } finally {
        $pdo->exec('GRANT '.$privilege.' ON public.'.$table.' TO assocmap_gisclass_app');
    }
    $unchangedDeletion($before, 'missing '.$table.' '.$privilege.' denial');
}

// Force a real audit INSERT failure after DELETE; the service transaction must restore the location.
$feature = $newDeletionLocation();
$before = snapshot($deletionTables);
$pdo->exec("ALTER TABLE public.audit_logs ADD CONSTRAINT gis_delete_test_audit_failure
    CHECK (action_type <> 'DELETE GIS' OR entity_id IS DISTINCT FROM ".$pdo->quote($feature['id']).')');
try {
    $expectDeletionFailure(fn () => $locations->destroy($feature['id'], $feature['properties']['version'], $officer),
        'audit constraint failure after deletion', 'gis_delete_test_audit_failure');
    $unchangedDeletion($before, 'audit constraint rollback');
} finally {
    $pdo->exec('ALTER TABLE public.audit_logs DROP CONSTRAINT gis_delete_test_audit_failure');
}

$feature = $newDeletionLocation();
$before = snapshot($deletionTables);
$pdo->exec('CREATE FUNCTION public.gis_delete_test_trigger() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN OLD; END $$;
    CREATE TRIGGER gis_delete_unexpected_trigger BEFORE DELETE ON public.gis_locations FOR EACH ROW EXECUTE FUNCTION public.gis_delete_test_trigger()');
try {
    $expectDeletionFailure(fn () => $locations->destroy($feature['id'], $feature['properties']['version'], $officer),
        'unexpected DELETE trigger', 'gis_delete_unexpected_trigger');
    $unchangedDeletion($before, 'unexpected trigger denial');
} finally {
    $pdo->exec('DROP TRIGGER gis_delete_unexpected_trigger ON public.gis_locations; DROP FUNCTION public.gis_delete_test_trigger()');
}

verify(Storage::disk('media_private')->allFiles() === $storedVariants, 'deletion and Archive never change stored photo variants');
verify(DB::transactionLevel() === 0, 'deletion regression leaves no open transaction');
$deletionCheckCount = count($checks) - $deletionStart;
