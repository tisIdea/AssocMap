<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\UserProfile;
use App\Services\GisAddressResolver;
use App\Services\GisFeatureSerializer;
use App\Services\GisQueryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class GisScopedReadsTest extends TestCase
{
    private GisQueryService $gis;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['database.connections.gis_scope_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('gis_scope_test');
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        $this->gis = new GisQueryService(new GisFeatureSerializer, new GisAddressResolver);
        Schema::create('municipalities', function (Blueprint $table) { $table->string('id')->primary(); });
        Schema::create('barangays', function (Blueprint $table) { $table->string('id')->primary(); $table->string('municipality_id'); });
        Schema::create('areas', function (Blueprint $table) { $table->string('id')->primary(); $table->string('barangay_id'); });
        Schema::create('associations', function (Blueprint $table) {
            $table->string('id')->primary(); $table->string('area_id'); $table->string('status');
            $table->string('primary_field_officer_id'); $table->timestamp('archived_at')->nullable();
        });
        Schema::create('projects', function (Blueprint $table) {
            $table->string('id')->primary(); $table->string('association_id'); $table->timestamp('archived_at')->nullable();
        });
        Schema::create('association_accounts', function (Blueprint $table) {
            $table->string('user_id'); $table->string('association_id'); $table->boolean('is_active');
        });
        Schema::create('gis_locations', function (Blueprint $table) {
            $table->string('id')->primary(); $table->string('association_id')->nullable(); $table->string('project_id')->nullable();
            $table->string('location_type'); $table->timestamp('archived_at')->nullable();
        });
        DB::table('municipalities')->insert(['id' => 'municipality']);
        DB::table('barangays')->insert(['id' => 'barangay', 'municipality_id' => 'municipality']);
        DB::table('areas')->insert(['id' => 'area', 'barangay_id' => 'barangay']);
        foreach (['association-a' => ['officer-a', 'active', null], 'association-b' => ['officer-b', 'active', null],
            'inactive-parent' => ['officer-a', 'inactive', null], 'archived-parent' => ['officer-a', 'active', now()]] as $id => $values) {
            DB::table('associations')->insert(['id' => $id, 'area_id' => 'area', 'primary_field_officer_id' => $values[0], 'status' => $values[1], 'archived_at' => $values[2]]);
        }
        foreach (['project-a' => ['association-a', null], 'project-b' => ['association-b', null],
            'inactive-project' => ['inactive-parent', null], 'parent-archived-project' => ['archived-parent', null],
            'archived-project' => ['association-a', now()]] as $id => $values) {
            DB::table('projects')->insert(['id' => $id, 'association_id' => $values[0], 'archived_at' => $values[1]]);
        }
        foreach ([
            ['a-point', 'association-a', null, 'association_point', null],
            ['a-project', null, 'project-a', 'project_point', null],
            ['a-boundary', null, 'project-a', 'project_boundary', null],
            ['b-point', 'association-b', null, 'association_point', null],
            ['b-project', null, 'project-b', 'project_point', null],
            ['inactive', null, 'inactive-project', 'project_point', null],
            ['parent-archived', null, 'parent-archived-project', 'project_point', null],
            ['project-archived', null, 'archived-project', 'project_point', null],
            ['gis-archived', null, 'project-a', 'project_point', now()],
            ['legacy-archived', 'association-a', null, 'association_point', now()],
        ] as $row) DB::table('gis_locations')->insert(['id' => $row[0], 'association_id' => $row[1], 'project_id' => $row[2], 'location_type' => $row[3], 'archived_at' => $row[4]]);
        DB::table('association_accounts')->insert(['user_id' => 'account-a', 'association_id' => 'association-a', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('gis_scope_test');
        parent::tearDown();
    }

    public function test_administrator_reads_all_eligible_locations_and_archived_register_is_separate(): void
    {
        $admin = $this->principal('admin', 'system_admin');
        $this->assertEqualsCanonicalizing(['a-project', 'a-boundary', 'b-project'], $this->ids($admin));
        $this->assertSame(['gis-archived'], $this->ids($admin, true));
        $this->assertTrue(DB::table('gis_locations')->where('id', 'a-point')->exists());
        $this->assertTrue(DB::table('gis_locations')->where('id', 'legacy-archived')->exists());
    }

    public function test_field_officer_scope_includes_project_locations_through_current_assignment(): void
    {
        $officer = $this->principal('officer-a', 'field_officer');
        $this->assertEqualsCanonicalizing(['a-project', 'a-boundary'], $this->ids($officer));
        DB::table('associations')->where('id', 'association-a')->update(['primary_field_officer_id' => 'officer-b']);
        $this->assertSame([], $this->ids($officer));
        $this->assertCount(3, $this->ids($this->principal('officer-b', 'field_officer')));
    }

    public function test_association_scope_follows_only_current_active_link(): void
    {
        $account = $this->principal('account-a', 'association_account');
        $this->assertEqualsCanonicalizing(['a-project', 'a-boundary'], $this->ids($account));
        DB::table('association_accounts')->update(['is_active' => false]);
        $this->assertSame([], $this->ids($account));
        DB::table('association_accounts')->update(['is_active' => true, 'association_id' => 'association-b']);
        $this->assertEqualsCanonicalizing(['b-project'], $this->ids($account));
        $this->assertFalse($this->gis->manager($account));
    }

    public function test_association_account_cannot_expand_scope_with_another_parent_id_or_archived_flag(): void
    {
        $account = $this->principal('account-a', 'association_account');
        $this->assertSame([], $this->gis->scoped($account)->where('a.id', 'association-b')->pluck('g.id')->all());
        $this->assertNotContains('gis-archived', $this->ids($account, true));
    }

    private function ids(UserProfile $user, bool $archived = false): array
    {
        return $this->gis->scoped($user, $archived)->pluck('g.id')->all();
    }

    private function principal(string $id, string $role): UserProfile
    {
        // Authentication is assumed here; these fixtures exercise GIS scope only.
        $user = Mockery::mock(UserProfile::class)->makePartial();
        $user->forceFill(['id' => $id, 'is_active' => true]);
        $user->setRelation('role', new Role(['code' => $role, 'is_active' => true]));
        $user->shouldReceive('canAccessApplication')->andReturn(true);

        return $user;
    }
}
