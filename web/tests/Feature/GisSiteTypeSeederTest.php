<?php

namespace Tests\Feature;

use App\Models\GisSiteType;
use App\Services\GIS\GisQueryService;
use Database\Seeders\GisSiteTypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class GisSiteTypeSeederTest extends TestCase
{
    private const TYPES = [
        'fish_cage' => 'Fish Cage',
        'fish_pen' => 'Fish Pen',
        'culture_pond' => 'Fishpond / Culture Pond',
        'seaweed_cultivation_site' => 'Seaweed Cultivation Site',
        'hatchery_nursery' => 'Hatchery / Nursery',
        'payao_installation' => 'Payao Installation',
        'post_harvest_facility' => 'Post-Harvest Facility',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        DB::connection()->getPdo()->sqliteCreateFunction('regexp',
            static fn (string $pattern, string $value): int => preg_match('/'.$pattern.'/D', $value), 2);
        // Synthetic equivalents of 024's catalog constraints; no migration is executed.
        DB::statement("CREATE TABLE gis_site_types (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code VARCHAR(50) NOT NULL UNIQUE CHECK (code REGEXP '^[a-z][a-z0-9_]{0,49}$'),
            name VARCHAR(100) NOT NULL CHECK (length(name) <= 100 AND TRIM(name) <> '')
        )");
        Schema::create('gis_locations', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('site_type_id')->nullable()->constrained('gis_site_types')->restrictOnDelete();
            $table->string('project_id');
            $table->string('location_type');
            $table->binary('geom');
            $table->boolean('is_published');
        });
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public function test_initial_insertion_uses_generated_ids_and_the_existing_catalog_projection(): void
    {
        $this->seed(GisSiteTypeSeeder::class);

        $this->assertDatabaseCount('gis_site_types', 7);
        $this->assertEqualsCanonicalizing(['id', 'code', 'name'], Schema::getColumnListing('gis_site_types'));
        $rows = GisSiteType::all();
        $this->assertEquals(self::TYPES, $rows->pluck('name', 'code')->all());
        $this->assertCount(7, $rows->pluck('id')->unique());
        foreach ($rows as $row) {
            $this->assertGreaterThan(0, $row->id);
        }
        $options = collect(app(GisQueryService::class)->siteTypes());
        $this->assertEquals(self::TYPES, $options->pluck('name', 'code')->all());
        $this->assertSame($rows->pluck('id', 'code')->sortKeys()->all(), $options->pluck('id', 'code')->sortKeys()->all());
    }

    public function test_repeat_execution_preserves_ids_and_does_not_issue_catalog_writes(): void
    {
        $this->seed(GisSiteTypeSeeder::class);
        $before = $this->catalog();
        $sequence = DB::table('sqlite_sequence')->where('name', 'gis_site_types')->value('seq');
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->seed(GisSiteTypeSeeder::class);

        $writes = array_filter(DB::getQueryLog(), static fn (array $query): bool => preg_match('/^\s*(insert|update|delete|replace)\b/i', $query['query']) === 1);
        DB::disableQueryLog();
        $this->assertSame([], array_values($writes));
        $this->assertSame($before, $this->catalog());
        $this->assertSame($sequence, DB::table('sqlite_sequence')->where('name', 'gis_site_types')->value('seq'));
    }

    public function test_existing_ids_custom_types_and_classified_or_unclassified_locations_are_preserved(): void
    {
        DB::table('gis_site_types')->insert([
            ['id' => 41, 'code' => 'fish_cage', 'name' => 'Fish Cage'],
            ['id' => 95, 'code' => 'fishing_gear_site', 'name' => 'Existing Fishing Gear Site'],
        ]);
        $existing = $this->catalog();
        $this->locations();
        $locations = DB::table('gis_locations')->orderBy('id')->get()->toJson();

        $this->seed(GisSiteTypeSeeder::class);

        $this->assertDatabaseCount('gis_site_types', 8);
        $this->assertSame($existing, DB::table('gis_site_types')->whereIn('id', [41, 95])->orderBy('id')->get()->toJson());
        $this->assertSame($locations, DB::table('gis_locations')->orderBy('id')->get()->toJson());
        $this->assertGreaterThan(95, DB::table('gis_site_types')->where('code', 'culture_pond')->value('id'));
    }

    public function test_an_existing_label_under_a_different_code_is_preserved_as_a_separate_classification(): void
    {
        DB::table('gis_site_types')->insert(['id' => 95, 'code' => 'existing_pond', 'name' => 'Fishpond / Culture Pond']);

        $this->seed(GisSiteTypeSeeder::class);

        $this->assertDatabaseHas('gis_site_types', ['id' => 95, 'code' => 'existing_pond', 'name' => 'Fishpond / Culture Pond']);
        $this->assertDatabaseCount('gis_site_types', 8);
        $this->assertNotSame(95, DB::table('gis_site_types')->where('code', 'culture_pond')->value('id'));
    }

    #[DataProvider('conflictingCodes')]
    public function test_a_conflicting_code_rolls_back_all_new_rows_without_changing_existing_data(string $code): void
    {
        DB::table('gis_site_types')->insert(['id' => 41, 'code' => $code, 'name' => 'Existing stakeholder label']);
        $this->locations();
        $before = $this->catalog();
        $locations = DB::table('gis_locations')->orderBy('id')->get()->toJson();

        try {
            app(GisSiteTypeSeeder::class)->run();
            $this->fail('A conflicting code must require catalog review.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('['.$code.']', $exception->getMessage());
        }

        $this->assertSame($before, $this->catalog());
        $this->assertSame($locations, DB::table('gis_locations')->orderBy('id')->get()->toJson());
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function conflictingCodes(): array
    {
        return array_combine(array_keys(self::TYPES), array_map(static fn (string $code): array => [$code], array_keys(self::TYPES)));
    }

    public function test_a_database_insert_failure_is_propagated_without_a_partial_catalog(): void
    {
        DB::unprepared("CREATE TRIGGER synthetic_catalog_failure BEFORE INSERT ON gis_site_types
            WHEN NEW.code = 'payao_installation' BEGIN SELECT RAISE(ABORT, 'Synthetic catalog failure'); END");

        try {
            app(GisSiteTypeSeeder::class)->run();
            $this->fail('A database failure must not be reported as a successful seed.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Synthetic catalog failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('gis_site_types', 0);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_an_ignored_insert_cannot_silently_leave_an_incomplete_catalog(): void
    {
        DB::unprepared("CREATE TRIGGER synthetic_catalog_ignore BEFORE INSERT ON gis_site_types
            WHEN NEW.code = 'hatchery_nursery' BEGIN SELECT RAISE(IGNORE); END");

        try {
            app(GisSiteTypeSeeder::class)->run();
            $this->fail('An incomplete catalog must not be reported as a successful seed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('[hatchery_nursery]', $exception->getMessage());
        }

        $this->assertDatabaseCount('gis_site_types', 0);
        $this->assertSame(0, DB::transactionLevel());
    }

    private function catalog(): string
    {
        return DB::table('gis_site_types')->orderBy('id')->get()->toJson();
    }

    private function locations(): void
    {
        DB::table('gis_locations')->insert([
            ['id' => 'synthetic-classified', 'site_type_id' => 41, 'project_id' => 'synthetic-project',
                'location_type' => 'project_point', 'geom' => 'synthetic-unchanged-point-bytes', 'is_published' => true],
            ['id' => 'synthetic-unclassified', 'site_type_id' => null, 'project_id' => 'synthetic-project',
                'location_type' => 'project_boundary', 'geom' => 'synthetic-unchanged-boundary-bytes', 'is_published' => false],
        ]);
    }
}
