<?php

declare(strict_types=1);

require __DIR__.'/ScratchDatabase.php';
require __DIR__.'/LocalTestRun.php';

$scratch = new ScratchDatabase($argv[1] ?? '');
$pdo = $scratch->pdo;
if ((int) $pdo->query("SELECT count(*) FROM pg_catalog.pg_tables WHERE schemaname='public'")->fetchColumn() !== 0) {
    throw new RuntimeException('A newly created empty disposable database is required; no tables were changed.');
}
$root = dirname(__DIR__, 3);
$baseline = file_get_contents($root.'/supabase/migrations/001_assocmap_schema.sql');
$expand = file_get_contents($root.'/supabase/migrations/017_expand_association_geography.sql');
$cutover = file_get_contents($root.'/supabase/deferred/018_cutover_association_geography.sql');
$run = new LocalTestRun($scratch->directory.'/association-geography-results.json');

function geographyId(int $number): string { return sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $number); }
function geographyRejectMigration(PDO $pdo, string $sql, string $message): void
{
    try { $pdo->exec($sql); }
    catch (PDOException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        LocalTestRun::assert($error->getCode() === 'P0001' && str_contains($error->getMessage(), $message), 'Unexpected migration rejection.');

        return;
    }
    throw new RuntimeException('Expected migration rejection.');
}
function geographyFacts(PDO $pdo): array
{
    return $pdo->query("SELECT to_jsonb(a) - 'barangay_id' - 'updated_at' AS facts FROM public.associations a ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
}

// Ancillary profile stub; all geographic, Association and registration table DDL is exact baseline SQL.
$pdo->exec('CREATE TABLE public.user_profiles (id UUID PRIMARY KEY)');
foreach (['municipalities', 'barangays', 'areas', 'registration_agencies', 'associations', 'association_registrations'] as $table) {
    if (preg_match('/CREATE TABLE public\.'.preg_quote($table, '/').' \([\s\S]*?\n\);/', $baseline, $match) !== 1) {
        throw new RuntimeException('Exact baseline table DDL not found: '.$table);
    }
    $pdo->exec($match[0]);
}
foreach (['uq_area_barangay_address_ci', 'uq_association_name_ci', 'idx_associations_area_id'] as $index) {
    if (preg_match('/CREATE (?:UNIQUE )?INDEX '.preg_quote($index, '/').'\b[^;]*;/', $baseline, $match) !== 1) {
        throw new RuntimeException('Exact baseline index DDL not found: '.$index);
    }
    $pdo->exec($match[0]);
}
if (preg_match('/CREATE OR REPLACE FUNCTION public\.set_updated_at\(\)[\s\S]*?\$\$;/', $baseline, $match) !== 1) {
    throw new RuntimeException('Exact timestamp function not found.');
}
$pdo->exec($match[0]);
$pdo->exec('CREATE TRIGGER trg_test_associations_updated BEFORE UPDATE ON public.associations FOR EACH ROW EXECUTE FUNCTION public.set_updated_at()');
$pdo->exec("INSERT INTO public.user_profiles VALUES ('".geographyId(1)."')");
$pdo->exec("INSERT INTO public.municipalities(id,name) VALUES ('".geographyId(20)."','Synthetic Municipality')");
foreach ([30, 31] as $id) $pdo->exec("INSERT INTO public.barangays(id,municipality_id,name) VALUES ('".geographyId($id)."','".geographyId(20)."','Synthetic Barangay {$id}')");
foreach ([40 => 30, 41 => 31] as $id => $barangay) $pdo->exec("INSERT INTO public.areas(id,barangay_id,address) VALUES ('".geographyId($id)."','".geographyId($barangay)."','Recorded Area {$id}')");
foreach ([10 => ['active', 'NULL'], 11 => ['inactive', 'NULL'], 12 => ['active', "'2025-01-01'::timestamptz"]] as $id => [$status, $archived]) {
    $pdo->exec("INSERT INTO public.associations(id,name,area_id,primary_field_officer_id,saad_phase2_join_date,status,archived_at)
        VALUES ('".geographyId($id)."','Synthetic Association {$id}','".geographyId(40)."','".geographyId(1)."','2024-01-01','{$status}',{$archived})");
}
$pdo->exec("INSERT INTO public.registration_agencies(id,code,name) VALUES (1,'SYNTHETIC','Synthetic Agency')");
$pdo->exec("INSERT INTO public.association_registrations(id,association_id,agency_id,registration_number,registration_date)
    VALUES ('".geographyId(50)."','".geographyId(12)."',1,'SYNTHETIC-REG','2024-01-01')");
$childrenBefore = $pdo->query('SELECT row_to_json(r) FROM public.association_registrations r ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$pdo->exec($expand);

$run->check('expansion remains nullable for new fields and Area-required for legacy writers', function () use ($pdo) {
    $columns = $pdo->query("SELECT column_name,is_nullable FROM information_schema.columns WHERE table_schema='public' AND table_name='associations'")->fetchAll(PDO::FETCH_KEY_PAIR);
    LocalTestRun::assert($columns['area_id'] === 'NO' && $columns['barangay_id'] === 'YES' && $columns['specific_address'] === 'YES', 'Expansion nullability changed.');
    LocalTestRun::assert((int) $pdo->query('SELECT count(*) FROM associations WHERE barangay_id IS NULL AND specific_address IS NULL')->fetchColumn() === 3, 'Expansion modified existing locations.');
    LocalTestRun::rollback($pdo, function () use ($pdo) {
        $pdo->exec("INSERT INTO associations(name,area_id,primary_field_officer_id,saad_phase2_join_date) VALUES ('Legacy writer','".geographyId(40)."','".geographyId(1)."','2024-01-01')");
        LocalTestRun::sqlState($pdo, "UPDATE associations SET area_id=NULL WHERE id='".geographyId(10)."'", ['23502']);
    });
});
$run->check('expansion address rejects blank and overlong values while preserving Unicode', function () use ($pdo) {
    LocalTestRun::rollback($pdo, function () use ($pdo) {
        foreach (['', " \t\n ", str_repeat('ñ', 501)] as $bad) {
            LocalTestRun::sqlState($pdo, 'UPDATE associations SET specific_address='.$pdo->quote($bad), ['23514']);
        }
        $pdo->exec('UPDATE associations SET specific_address='.$pdo->quote(str_repeat('ñ', 500)));
        LocalTestRun::assert((int) $pdo->query('SELECT count(DISTINCT specific_address) FROM associations')->fetchColumn() === 1, 'Address should not be unique.');
    });
});
$run->check('cutover refuses execution without explicit Batch 2 approval', function () use ($pdo, $cutover) {
    geographyRejectMigration($pdo, $cutover, 'requires Batch 2 approval');
});
$pdo->exec("SET assocmap.geography_cutover_approved='batch2'");
$run->check('cutover rejects conflicting populated Barangay without partial backfill', function () use ($pdo, $cutover) {
    $pdo->exec("UPDATE associations SET barangay_id='".geographyId(31)."' WHERE id='".geographyId(10)."'");
    try {
        geographyRejectMigration($pdo, $cutover, 'Conflicting populated');
        LocalTestRun::assert((int) $pdo->query('SELECT count(*) FROM associations WHERE barangay_id IS NULL')->fetchColumn() === 2, 'Rejected migration partially backfilled.');
    } finally { $pdo->exec("UPDATE associations SET barangay_id=NULL WHERE id='".geographyId(10)."'"); }
});
$run->check('cutover rejects unresolved Area mapping without partial backfill', function () use ($pdo, $cutover) {
    $pdo->exec('ALTER TABLE associations DROP CONSTRAINT associations_area_id_fkey');
    $pdo->exec("UPDATE associations SET area_id='".geographyId(999)."' WHERE id='".geographyId(10)."'");
    $pdo->exec('ALTER TABLE associations ADD CONSTRAINT associations_area_id_fkey FOREIGN KEY(area_id) REFERENCES areas(id) ON DELETE RESTRICT NOT VALID');
    try { geographyRejectMigration($pdo, $cutover, 'Unresolved Association'); }
    finally {
        $pdo->exec("UPDATE associations SET area_id='".geographyId(40)."' WHERE id='".geographyId(10)."'");
        $pdo->exec('ALTER TABLE associations VALIDATE CONSTRAINT associations_area_id_fkey');
    }
});
$factsBefore = geographyFacts($pdo);
$pdo->exec($cutover);
$run->check('backfill preserves IDs inactive and archived rows Areas addresses and registration children', function () use ($pdo, $factsBefore, $childrenBefore) {
    LocalTestRun::assert(geographyFacts($pdo) === $factsBefore, 'Backfill changed preserved Association attributes.');
    LocalTestRun::assert($pdo->query('SELECT row_to_json(r) FROM public.association_registrations r ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) === $childrenBefore, 'Child registrations changed.');
    LocalTestRun::assert((int) $pdo->query("SELECT count(*) FROM associations WHERE barangay_id='".geographyId(30)."' AND specific_address IS NULL")->fetchColumn() === 3, 'Wrong backfill or copied address.');
});
$run->check('final MATCH SIMPLE foreign keys accept four combinations and reject missing or mismatched Barangay', function () use ($pdo) {
    LocalTestRun::rollback($pdo, function () use ($pdo) {
        foreach ([[null, null], [40, null], [null, 'Independent / ñ #2'], [40, 'Independent / ñ #2']] as [$area, $address]) {
            $pdo->exec('UPDATE associations SET area_id='.($area === null ? 'NULL' : $pdo->quote(geographyId($area))).', specific_address='.($address === null ? 'NULL' : $pdo->quote($address))." WHERE id='".geographyId(10)."'");
        }
        LocalTestRun::sqlState($pdo, "UPDATE associations SET area_id='".geographyId(41)."' WHERE id='".geographyId(10)."'", ['23503']);
        LocalTestRun::sqlState($pdo, 'UPDATE associations SET barangay_id=NULL', ['23502']);
        LocalTestRun::sqlState($pdo, "UPDATE associations SET area_id=NULL,barangay_id='".geographyId(999)."'", ['23503']);
    });
});
$run->check('referenced Area and Barangay delete or reparent cannot break geography', function () use ($pdo) {
    LocalTestRun::rollback($pdo, function () use ($pdo) {
        LocalTestRun::sqlState($pdo, "UPDATE areas SET barangay_id='".geographyId(31)."' WHERE id='".geographyId(40)."'", ['23001'], 'fk_associations_area_barangay');
        LocalTestRun::sqlState($pdo, "DELETE FROM areas WHERE id='".geographyId(40)."'", ['23001'], 'fk_associations_area_barangay');
        $pdo->exec('UPDATE associations SET area_id=NULL');
        $pdo->exec("DELETE FROM areas WHERE id='".geographyId(40)."'");
        LocalTestRun::sqlState($pdo, "DELETE FROM barangays WHERE id='".geographyId(30)."'", ['23001'], 'fk_associations_barangay');
    });
});
$run->check('existing Area identity and case-insensitive Association uniqueness remain enforced', function () use ($pdo) {
    LocalTestRun::rollback($pdo, function () use ($pdo) {
        LocalTestRun::sqlState($pdo, "INSERT INTO areas(barangay_id,address) VALUES ('".geographyId(30)."','recorded area 40')", ['23505']);
        LocalTestRun::sqlState($pdo, 'UPDATE areas SET address=NULL', ['23502']);
        LocalTestRun::sqlState($pdo, "UPDATE associations SET name='synthetic association 10' WHERE id='".geographyId(11)."'", ['23505']);
    });
});
$run->check('final schema has validated direct and composite FKs and nonredundant lookup indexes', function () use ($pdo) {
    $constraints = $pdo->query("SELECT conname FROM pg_constraint WHERE conrelid='public.associations'::regclass AND convalidated")->fetchAll(PDO::FETCH_COLUMN);
    LocalTestRun::assert(in_array('fk_associations_barangay', $constraints, true) && in_array('fk_associations_area_barangay', $constraints, true), 'Final foreign keys are not validated.');
    $indexes = $pdo->query("SELECT indexname FROM pg_indexes WHERE schemaname='public' AND tablename='associations'")->fetchAll(PDO::FETCH_COLUMN);
    LocalTestRun::assert(in_array('idx_associations_barangay_id', $indexes, true) && in_array('idx_associations_area_barangay', $indexes, true) && ! in_array('idx_associations_area_id', $indexes, true), 'Unexpected redundant or missing indexes.');
});
$run->check('cutover replay rejects the final schema without changing records', function () use ($pdo, $cutover) {
    $before = geographyFacts($pdo);
    geographyRejectMigration($pdo, $cutover, 'Unsupported or already-cut-over');
    LocalTestRun::assert(geographyFacts($pdo) === $before, 'Rejected replay changed records.');
});
$run->finish();
