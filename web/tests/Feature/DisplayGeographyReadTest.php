<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Project;
use App\Services\Associations\AssociationGeographyService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DisplayGeographyReadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::unprepared('CREATE TABLE municipalities (id TEXT PRIMARY KEY, name TEXT, province TEXT, region TEXT, is_active BOOLEAN);
            CREATE TABLE barangays (id TEXT PRIMARY KEY, municipality_id TEXT, name TEXT, is_active BOOLEAN);
            CREATE TABLE areas (id TEXT PRIMARY KEY, barangay_id TEXT, address TEXT, is_active BOOLEAN);
            CREATE TABLE associations (id TEXT PRIMARY KEY, name TEXT, area_id TEXT, barangay_id TEXT);
            CREATE TABLE projects (id TEXT PRIMARY KEY, association_id TEXT, title TEXT)');
        DB::table('municipalities')->insert(['id' => 'town', 'name' => 'Synthetic municipality', 'province' => 'Province', 'region' => 'Region', 'is_active' => false]);
        DB::table('barangays')->insert([
            ['id' => 'barangay', 'municipality_id' => 'town', 'name' => 'Synthetic barangay', 'is_active' => false],
            ['id' => 'orphan', 'municipality_id' => 'missing', 'name' => 'Retained barangay', 'is_active' => true],
        ]);
        DB::table('areas')->insert([
            ['id' => 'area', 'barangay_id' => 'barangay', 'address' => 'Stored address', 'is_active' => false],
            ['id' => 'orphan', 'barangay_id' => 'missing', 'address' => 'Retained address', 'is_active' => true],
        ]);
        DB::table('associations')->insert([
            ['id' => 'one', 'name' => 'Complete', 'area_id' => 'area', 'barangay_id' => 'barangay'],
            ['id' => 'two', 'name' => 'Area-less', 'area_id' => null, 'barangay_id' => 'barangay'],
            ['id' => 'three', 'name' => 'Missing parent', 'area_id' => 'orphan', 'barangay_id' => 'orphan'],
            ['id' => 'four', 'name' => 'Missing geography', 'area_id' => 'missing', 'barangay_id' => null],
        ]);
        foreach (['one', 'two', 'three', 'four'] as $id) {
            DB::table('projects')->insert(['id' => $id, 'association_id' => $id, 'title' => 'Synthetic '.$id]);
        }
    }

    public static function geographyModes(): array
    {
        return ['legacy' => [false, false], 'legacy with status' => [false, true],
            'direct' => [true, false], 'direct with status' => [true, true]];
    }

    #[DataProvider('geographyModes')]
    public function test_joined_display_reads_preserve_the_complete_loaded_model_graph(bool $direct, bool $status): void
    {
        config(['associations.geography_schema_ready' => $direct]);
        $geography = app(AssociationGeographyService::class);
        $barangay = $geography->barangayRelation();
        $original = $status
            ? ['area:id,barangay_id,address,is_active', $barangay.':id,municipality_id,name,is_active',
                $barangay.'.municipality:id,name,province,region,is_active']
            : $geography->relations();
        [$expected, $before] = $this->counted(fn () => Association::with($original)->orderBy('id')->get());
        [$actual, $after] = $this->counted(fn () => Association::with($geography->displayRelations(withStatus: $status))->orderBy('id')->get());

        $this->assertSame($expected->toArray(), $actual->toArray());
        $this->assertSame(4, $actual->count());
        $this->assertSame($direct ? 1 : 2, $before - $after);
        foreach ($actual as $association) {
            $this->assertSame($expected->firstWhere('id', $association->id)->getOriginal(), $association->getOriginal());
            if ($association->area) {
                $this->assertSame($expected->firstWhere('id', $association->id)->area->getOriginal(), $association->area->getOriginal());
            }
        }
    }

    #[DataProvider('geographyModes')]
    public function test_prefixed_display_reads_preserve_pagination_filters_and_original_relations(bool $direct, bool $status): void
    {
        config(['associations.geography_schema_ready' => $direct]);
        $geography = app(AssociationGeographyService::class);
        $barangay = $geography->barangayRelation('association');
        $original = $status
            ? ['association.area:id,barangay_id,address,is_active', $barangay.':id,municipality_id,name,is_active',
                $barangay.'.municipality:id,name,province,region,is_active']
            : $geography->relations('association');
        $expected = Project::with($original)->where('title', 'like', 'Synthetic%')->orderBy('id')->paginate(2);
        $actual = Project::with($geography->displayRelations('association', $status))->where('title', 'like', 'Synthetic%')->orderBy('id')->paginate(2);

        $this->assertSame($expected->toArray(), $actual->toArray());
        $this->assertSame(4, $actual->total());
        $this->assertSame(2, $actual->perPage());
    }

    public function test_empty_display_reads_do_not_add_queries(): void
    {
        $geography = app(AssociationGeographyService::class);
        [$records, $queries] = $this->counted(fn () => Association::with($geography->displayRelations())->where('id', 'absent')->get());

        $this->assertSame([], $records->toArray());
        $this->assertSame(1, $queries);
    }

    private function counted(callable $read): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try { return [$read(), count(DB::getQueryLog())]; }
        finally { DB::disableQueryLog(); }
    }
}
