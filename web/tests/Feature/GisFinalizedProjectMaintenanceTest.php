<?php

namespace Tests\Feature;

use App\Http\Controllers\GisWorkspaceController;
use App\Models\GisLocation;
use App\Models\Association;
use App\Models\Project;
use App\Models\UserProfile;
use App\Services\Auth\SupabaseAuthService;
use App\Services\GIS\GisAddressResolver;
use App\Services\GIS\GisFeatureSerializer;
use App\Services\GIS\GisGeometry;
use App\Services\GIS\GisLocationService;
use App\Services\GIS\GisQueryService;
use App\Services\Projects\ProjectLifecycleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\GisSqliteConnection;
use Tests\Support\IsolatedDatabaseManager;
use Tests\TestCase;

class GisFinalizedProjectMaintenanceTest extends TestCase
{
    private GisLocationService $gis;
    private GisQueryService $queries;
    private GisSqliteConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        config(['associations.geography_schema_ready' => false, 'associations.direct_geography' => false]);
        config(['app.key' => 'base64:'.base64_encode(str_repeat('g', 32))]);
        Http::preventStrayRequests();
        $this->withoutVite();
        $this->assertInstanceOf(IsolatedDatabaseManager::class, DB::getFacadeRoot());
        DB::purge('sqlite');
        DB::extend('sqlite', function ($config) {
            $this->assertSame(':memory:', $config['database']);
            return new GisSqliteConnection(new PDO('sqlite::memory:'), ':memory:', '', $config);
        });
        $this->connection = DB::connection();
        $this->assertSame('sqlite', $this->connection->getDriverName());
        $this->schema();
        foreach (['system_admin', 'field_officer', 'association_account'] as $index => $role) {
            DB::table('roles')->insert(['id' => $index + 1, 'code' => $role, 'is_active' => true]);
        }
        foreach (['admin' => 1, 'assigned' => 2, 'other' => 2, 'account' => 3] as $id => $role) {
            DB::table('user_profiles')->insert(['id' => $id, 'role_id' => $role, 'display_name' => $id, 'is_active' => true]);
        }
        DB::table('municipalities')->insert(['id' => 'municipality']);
        DB::table('barangays')->insert(['id' => 'barangay', 'municipality_id' => 'municipality']);
        DB::table('areas')->insert(['id' => 'area', 'barangay_id' => 'barangay']);
        DB::table('associations')->insert(['id' => 'association', 'area_id' => 'area', 'status' => 'active', 'primary_field_officer_id' => 'assigned']);
        foreach (['project', 'other-project'] as $id) {
            DB::table('projects')->insert(['id' => $id, 'association_id' => 'association', 'status' => 'ongoing', 'title' => $id]);
        }
        foreach ($this->historyTables() as $table) DB::table($table)->insert(['id' => 'history', 'value' => 'preserve '.$table]);
        $this->queries = new class(new GisFeatureSerializer, new GisAddressResolver) extends GisQueryService
        {
            public function detail(UserProfile $user, string $id): array
            {
                $location = $this->scoped($user, null)->where('g.id', $id)->select('g.*')->firstOrFail();
                $project = Project::findOrFail($location->project_id);
                $association = Association::findOrFail($project->association_id);
                $location->forceFill(['geometry_json' => $location->geom, 'version' => $location->getRawOriginal('updated_at'),
                    'project_status' => $project->status, 'project_closed_at' => $project->closed_at,
                    'project_archived_at' => $project->getRawOriginal('archived_at'),
                    'association_status' => $association->status, 'association_archived_at' => $association->getRawOriginal('archived_at'),
                    'association_name' => 'Synthetic association', 'project_title' => $project->title,
                    'parent_association_id' => $project->association_id]);

                return (new GisFeatureSerializer)->make($location, false, true, $user->role->code === 'system_admin');
            }
        };
        $geometry = Mockery::mock(GisGeometry::class)->makePartial();
        $geometry->shouldReceive('validateSpatial')->andReturnNull();
        $this->gis = new GisLocationService($geometry, $this->queries, app(ProjectLifecycleService::class), app(\App\Services\GIS\GisAccessService::class));
    }

    private function schema(): void
    {
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('code'); $t->boolean('is_active'); $t->string('name')->nullable(); $t->text('description')->nullable(); $t->timestamps(); });
        Schema::create('user_profiles', function (Blueprint $t) {
            $t->string('id')->primary(); $t->integer('role_id'); $t->string('display_name'); $t->boolean('is_active'); $t->timestamps();
        });
        Schema::create('user_invitations', function (Blueprint $t) {
            $t->string('auth_user_id')->nullable(); $t->string('status'); $t->string('last_error_code')->nullable();
            $t->timestamp('accepted_at')->nullable(); $t->timestamp('last_attempt_at')->nullable(); $t->timestamps();
        });
        Schema::create('municipalities', function (Blueprint $t) { $t->string('id')->primary(); });
        Schema::create('barangays', function (Blueprint $t) { $t->string('id')->primary(); $t->string('municipality_id'); });
        Schema::create('areas', function (Blueprint $t) { $t->string('id')->primary(); $t->string('barangay_id'); });
        Schema::create('associations', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('area_id'); $t->string('status'); $t->string('primary_field_officer_id'); $t->timestamp('archived_at')->nullable();
        });
        Schema::create('projects', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('association_id'); $t->string('title'); $t->string('status');
            $t->timestamp('closed_at')->nullable(); $t->text('closure_remarks')->nullable(); $t->string('closed_by')->nullable();
            $t->timestamp('archived_at')->nullable(); $t->timestamps();
        });
        Schema::create('gis_site_types', function (Blueprint $t) { $t->id(); $t->string('code', 50)->unique(); $t->string('name', 100); });
        Schema::create('gis_locations', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('association_id')->nullable(); $t->string('project_id')->nullable();
            $t->foreignId('site_type_id')->nullable()->constrained('gis_site_types')->restrictOnDelete();
            $t->string('location_type'); $t->string('location_name'); $t->text('geom');
            foreach (['address', 'public_title', 'public_description', 'public_address', 'published_by', 'created_by', 'updated_by', 'archived_by'] as $column) $t->text($column)->nullable();
            $t->boolean('is_published')->default(false); $t->timestamp('published_at')->nullable(); $t->timestamp('archived_at')->nullable();
            $t->timestamp('created_at')->useCurrent(); $t->timestamp('updated_at')->useCurrent();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->string('id')->primary();
            foreach (['actor_user_id', 'action_type', 'module', 'entity_type', 'entity_id', 'old_values', 'new_values', 'ip_address', 'user_agent'] as $column) $t->text($column)->nullable();
            $t->timestamp('performed_at');
        });
        foreach ($this->historyTables() as $table) Schema::create($table, function (Blueprint $t) { $t->string('id')->primary(); $t->text('value'); });
    }

    private function historyTables(): array
    {
        return ['monitoring_records', 'monitoring_production', 'monitoring_production_monthly', 'monitoring_income',
            'trainings', 'training_participants', 'program_activity_records', 'project_products', 'project_materials',
            'monitoring_materials', 'monitoring_visit_reports'];
    }

    #[DataProvider('classificationGeometries')]
    public function test_classification_maintenance_preserves_geometry_bytes_and_publication(array $geometry, bool $submitGeometry): void
    {
        DB::table('gis_site_types')->insert([['id' => 1, 'code' => 'synthetic_one', 'name' => 'Synthetic one'],
            ['id' => 2, 'code' => 'synthetic_two', 'name' => 'Synthetic two']]);
        $type = $geometry['type'] === 'Point' ? 'project_point' : 'project_boundary';
        $user = $this->actor();
        $request = Request::create('/gis', 'PATCH');
        $created = $this->gis->store($this->payload(['location_type' => $type, 'geometry' => $geometry]), $user, $request);
        $id = $created['feature']['id'];
        $this->gis->lifecycle($id, 'publish', $created['feature']['properties']['version'], $user, $request);
        $stored = json_encode($geometry, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION);
        DB::table('gis_locations')->where('id', $id)->update(['geom' => $stored]);
        $protected = ['geom', 'association_id', 'project_id', 'location_type', 'is_published', 'published_by', 'published_at'];
        $before = GisLocation::findOrFail($id)->only($protected);
        $history = $this->snapshot();
        Schema::create('media_photos', function (Blueprint $t) { $t->string('id'); $t->string('gis_location_id'); $t->string('state'); });
        DB::table('media_photos')->insert(['id' => 'synthetic-photo', 'gis_location_id' => $id, 'state' => 'ready']);

        foreach ([1, 2, null] as $siteType) {
            $payload = $this->payload(['version' => $this->queries->detail($user, $id)['properties']['version'],
                'location_type' => $type, 'site_type_id' => $siteType, 'geometry' => $geometry]);
            if (! $submitGeometry) {
                unset($payload['geometry']);
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->gis->update($id, $payload, $user, $request);
            $updates = array_filter(DB::getQueryLog(), fn ($entry) => str_starts_with($entry['query'], 'UPDATE gis_locations SET'));
            DB::disableQueryLog();
            $this->assertCount(1, $updates);
            foreach ($updates as $entry) $this->assertDoesNotMatchRegularExpression('/\bgeom\s*=/', $entry['query']);
            $this->assertSame($siteType, GisLocation::findOrFail($id)->site_type_id);
            $this->assertEquals($before, GisLocation::findOrFail($id)->only($protected));
            $event = DB::table('audit_logs')->where('entity_id', $id)->where('action_type', 'UPDATE')->orderByDesc('rowid')->first();
            $this->assertSame($siteType, json_decode($event->new_values, true)['site_type_id']);
        }
        $this->assertSame($history, $this->snapshot());
        $this->assertDatabaseHas('media_photos', ['id' => 'synthetic-photo', 'gis_location_id' => $id, 'state' => 'ready']);
    }

    public static function classificationGeometries(): array
    {
        $outer = [[123, 10], [124, 10], [124, 11], [123, 11], [123, 10]];
        $hole = [[123.2, 10.2], [123.2, 10.4], [123.4, 10.4], [123.4, 10.2], [123.2, 10.2]];
        $geometries = [
            'point' => ['type' => 'Point', 'coordinates' => [123.12345678901234, 10.3]],
            'polygon with hole' => ['type' => 'Polygon', 'coordinates' => [$outer, $hole]],
            'multipart with hole' => ['type' => 'MultiPolygon', 'coordinates' => [[$outer, $hole],
                [[[125, 10], [126, 10], [126, 11], [125, 10]]]]],
        ];
        $cases = [];
        foreach ($geometries as $name => $geometry) {
            foreach ([false, true] as $submitted) $cases[$name.($submitted ? ' old client' : ' omitted geometry')] = [$geometry, $submitted];
        }

        return $cases;
    }

    public function test_metadata_updates_retain_authorization_version_type_and_audit_guards(): void
    {
        DB::table('gis_site_types')->insert(['id' => 1, 'code' => 'synthetic', 'name' => 'Synthetic']);
        $request = Request::create('/gis', 'PATCH');
        $id = $this->gis->store($this->payload(), $this->actor(), $request)['feature']['id'];
        $payload = $this->payload(['version' => $this->queries->detail($this->actor(), $id)['properties']['version'], 'site_type_id' => 1]);
        unset($payload['geometry']);
        $before = DB::table('gis_locations')->get()->toJson();
        foreach (['other', 'account'] as $actor) $this->denied(fn () => $this->gis->update($id, $payload, $this->actor($actor), $request));
        try {
            $this->gis->update($id, array_replace($payload, ['version' => 'stale']), $this->actor(), $request);
            $this->fail('A stale classification update must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        try {
            $this->gis->update($id, array_replace($payload, ['location_type' => 'project_boundary']), $this->actor(), $request);
            $this->fail('Changing geometry type must require replacement geometry.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('geometry', $exception->errors());
        }
        $this->connection->rejectAudit = true;
        try {
            $this->gis->update($id, $payload, $this->actor(), $request);
            $this->fail('Classification maintenance requires a successful audit.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure.', $exception->getMessage());
        }
        $this->assertSame($before, DB::table('gis_locations')->get()->toJson());
        $this->assertSame(1, DB::table('audit_logs')->count());
    }

    public function test_metadata_update_does_not_expose_or_convert_a_legacy_association_point(): void
    {
        DB::table('gis_locations')->insert(['id' => 'legacy-association-point', 'association_id' => 'association',
            'project_id' => null, 'location_type' => 'association_point', 'location_name' => 'Legacy point',
            'geom' => '{"type":"Point","coordinates":[123,10]}']);
        $before = DB::table('gis_locations')->get()->toJson();
        $payload = $this->payload(['version' => 'legacy-version']);
        unset($payload['geometry']);
        $this->denied(fn () => $this->gis->update('legacy-association-point', $payload, $this->actor(), Request::create('/gis', 'PATCH')));
        $this->assertFalse($this->queries->scoped($this->actor(), null)->where('g.id', 'legacy-association-point')->exists());
        $this->assertSame($before, DB::table('gis_locations')->get()->toJson());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    #[DataProvider('statusesAndManagers')]
    public function test_classification_create_update_clear_and_omitted_legacy_update_preserve_history(string $status, string $actor): void
    {
        DB::table('gis_site_types')->insert([['id' => 1, 'code' => 'synthetic_one', 'name' => 'Synthetic one'],
            ['id' => 2, 'code' => 'synthetic_two', 'name' => 'Synthetic two']]);
        $this->finalize($status);
        $before = $this->snapshot();
        $user = $this->actor($actor);
        $request = Request::create('/gis');
        $created = $this->gis->store($this->payload(['site_type_id' => '1']), $user, $request);
        $id = $created['feature']['id'];
        $this->assertSame(1, GisLocation::findOrFail($id)->site_type_id);
        $this->assertFalse($this->gis->store($this->payload(['site_type_id' => 1]), $user, $request)['created']);
        $this->denied(fn () => $this->gis->store($this->payload(['site_type_id' => 2]), $user, $request));
        foreach ([['site_type_id' => 2], [], ['site_type_id' => null]] as $index => $change) {
            $version = $this->queries->detail($user, $id)['properties']['version'];
            $this->gis->update($id, $this->payload(['version' => $version] + $change), $user, $request);
            $this->assertSame($index === 2 ? null : 2, GisLocation::findOrFail($id)->site_type_id);
        }
        $events = DB::table('audit_logs')->where('entity_id', $id)->orderBy('performed_at')->get();
        $this->assertCount(4, $events);
        $this->assertSame(1, json_decode($events[0]->new_values, true)['site_type_id']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_invalid_classification_and_unauthorized_updates_leave_the_location_unchanged(): void
    {
        DB::table('gis_site_types')->insert(['id' => 1, 'code' => 'synthetic', 'name' => 'Synthetic']);
        $request = Request::create('/gis');
        $id = $this->gis->store($this->payload(), $this->actor(), $request)['feature']['id'];
        $this->assertNull(GisLocation::findOrFail($id)->site_type_id);
        $version = $this->queries->detail($this->actor(), $id)['properties']['version'];
        foreach ([999, -1, 'invalid'] as $invalid) {
            try {
                $this->gis->update($id, $this->payload(['version' => $version, 'site_type_id' => $invalid]), $this->actor(), $request);
                $this->fail('Invalid catalog assignment accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('site_type_id', $exception->errors());
            }
        }
        foreach (['other', 'account'] as $actor) {
            $this->denied(fn () => $this->gis->update($id, $this->payload(['version' => $version, 'site_type_id' => 1]), $this->actor($actor), $request));
        }
        $this->assertNull(GisLocation::findOrFail($id)->site_type_id);
        $this->assertSame(1, DB::table('audit_logs')->count());
    }

    private function snapshot(): array
    {
        $rows = ['projects' => DB::table('projects')->orderBy('id')->get()->toJson()];
        foreach ($this->historyTables() as $table) $rows[$table] = DB::table($table)->get()->toJson();
        return $rows;
    }

    private function actor(string $id = 'admin'): UserProfile
    {
        return UserProfile::with('role')->findOrFail($id);
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['idempotency_key' => '11111111-1111-4111-8111-111111111111',
            'parent_association_id' => 'association', 'project_id' => 'project', 'location_type' => 'project_point',
            'location_name' => 'Verified site', 'geometry' => ['type' => 'Point', 'coordinates' => [123.8, 10.3]],
            'public_title' => 'Approved site'], $changes);
    }

    private function finalize(string $status): void
    {
        DB::table('projects')->where('id', 'project')->update(['status' => $status,
            'closed_at' => $status === 'ongoing' ? null : '2026-10-16 08:00:00',
            'closed_by' => $status === 'ongoing' ? null : 'admin',
            'closure_remarks' => $status === 'ongoing' ? null : 'Retained final evidence.']);
    }

    public static function statusesAndManagers(): array
    {
        $cases = [];
        foreach (['completed', 'terminated', 'ongoing'] as $status) foreach (['admin', 'assigned'] as $actor) $cases[] = [$status, $actor];
        return $cases;
    }

    #[DataProvider('statusesAndManagers')]
    public function test_authorized_gis_create_edit_coordinates_and_publication_preserve_operational_history(string $status, string $actor): void
    {
        $this->finalize($status);
        $before = $this->snapshot(); $user = $this->actor($actor); $request = Request::create('/gis', 'PATCH');
        $created = $this->gis->store($this->payload(), $user, $request);
        $id = $created['feature']['id'];
        $this->assertTrue($created['created']);
        $this->assertFalse($this->gis->store($this->payload(), $user, $request)['created']);
        $version = $created['feature']['properties']['version'];
        $this->gis->update($id, $this->payload(['version' => $version, 'location_name' => 'Corrected site',
            'geometry' => ['type' => 'Point', 'coordinates' => [124.1, 10.6]]]), $user, $request);
        $location = GisLocation::findOrFail($id);
        $this->assertSame('Corrected site', $location->location_name);
        $this->assertSame([124.1, 10.6], json_decode($location->geom, true)['coordinates']);
        $version = (string) $location->updated_at;
        $this->gis->lifecycle($id, 'publish', $version, $user, $request);
        $this->assertTrue(GisLocation::findOrFail($id)->is_published);
        $this->gis->lifecycle($id, 'unpublish', (string) GisLocation::findOrFail($id)->updated_at, $user, $request);
        $this->assertFalse(GisLocation::findOrFail($id)->is_published);
        $this->assertSame($before, $this->snapshot());
        $this->assertEqualsCanonicalizing(['CREATE', 'UPDATE', 'PUBLISH GIS', 'UNPUBLISH GIS'], DB::table('audit_logs')->pluck('action_type')->all());
        $request->attributes->set('current_user', $user);
        $this->assertSame('gis-editor', (new GisWorkspaceController($this->queries, $this->gis))->edit($request, $id)->name());
    }

    public static function finalStatuses(): array { return [['completed'], ['terminated']]; }

    #[DataProvider('finalStatuses')]
    public function test_finalized_identity_and_deletion_are_denied_but_unrelated_project_remains_eligible(string $status): void
    {
        $id = $this->gis->store($this->payload(), $this->actor(), Request::create('/gis'))['feature']['id'];
        $this->finalize($status); $before = $this->snapshot();
        $version = (string) GisLocation::findOrFail($id)->updated_at;
        $operations = [
            fn () => $this->gis->update($id, $this->payload(['version' => $version, 'project_id' => 'other-project']), $this->actor(), Request::create('/gis')),
            fn () => $this->gis->update($id, $this->payload(['version' => $version, 'project_id' => null]), $this->actor(), Request::create('/gis')),
            fn () => $this->gis->update($id, $this->payload(['version' => $version, 'parent_association_id' => 'different']), $this->actor(), Request::create('/gis')),
            fn () => $this->gis->destroy($id, $version, $this->actor()),
            fn () => app(ProjectLifecycleService::class)->lockNotFinalized(['project']),
        ];
        foreach ($operations as $operation) {
            $this->denied(fn () => DB::transaction($operation));
            $this->assertSame('project', GisLocation::findOrFail($id)->project_id);
            $this->assertSame($before, $this->snapshot());
            $this->assertSame(1, DB::table('audit_logs')->count());
        }
        DB::transaction(fn () => app(ProjectLifecycleService::class)->lockNotFinalized(['other-project']));
    }

    #[DataProvider('finalStatuses')]
    public function test_ineligible_actors_stale_authorization_and_stale_versions_cannot_edit_or_publish(string $status): void
    {
        $this->finalize($status);
        $id = $this->gis->store($this->payload(), $this->actor(), Request::create('/gis'))['feature']['id'];
        $version = (string) GisLocation::findOrFail($id)->updated_at;
        foreach (['other', 'account'] as $actor) {
            $this->denied(fn () => $this->gis->store($this->payload(['idempotency_key' => '22222222-2222-4222-8222-222222222222']), $this->actor($actor), Request::create('/gis')));
            $this->denied(fn () => $this->gis->update($id, $this->payload(['version' => $version]), $this->actor($actor), Request::create('/gis')));
            $this->denied(fn () => $this->gis->lifecycle($id, 'publish', $version, $this->actor($actor), Request::create('/gis')));
        }
        $cached = $this->actor('assigned');
        DB::table('user_profiles')->where('id', 'assigned')->update(['is_active' => false]);
        $this->denied(fn () => $this->gis->lifecycle($id, 'publish', $version, $cached, Request::create('/gis')));
        DB::table('user_profiles')->where('id', 'assigned')->update(['is_active' => true, 'role_id' => 3]);
        $this->denied(fn () => $this->gis->lifecycle($id, 'publish', $version, $cached, Request::create('/gis')));
        DB::table('user_profiles')->where('id', 'assigned')->update(['role_id' => 2]);
        DB::table('associations')->update(['primary_field_officer_id' => 'other']);
        $this->denied(fn () => $this->gis->lifecycle($id, 'publish', $version, $cached, Request::create('/gis')));
        $this->denied(fn () => $this->gis->update($id, $this->payload(['version' => 'stale']), $this->actor(), Request::create('/gis')));
        $this->assertFalse(GisLocation::findOrFail($id)->is_published);
        $this->assertSame(1, DB::table('audit_logs')->count());
    }

    public function test_association_and_archive_guards_and_coordinate_validation_remain_active(): void
    {
        $this->finalize('completed');
        $id = $this->gis->store($this->payload(), $this->actor(), Request::create('/gis'))['feature']['id'];
        $version = (string) GisLocation::findOrFail($id)->updated_at;
        foreach ([['status' => 'inactive'], ['status' => 'active', 'archived_at' => now()]] as $state) {
            DB::table('associations')->update($state);
            $this->denied(fn () => $this->gis->update($id, $this->payload(['version' => $version]), $this->actor(), Request::create('/gis')));
        }
        DB::table('associations')->update(['status' => 'active', 'archived_at' => null]);
        DB::table('projects')->where('id', 'project')->update(['archived_at' => now()]);
        $this->denied(fn () => $this->gis->lifecycle($id, 'publish', $version, $this->actor(), Request::create('/gis')));
        DB::table('projects')->where('id', 'project')->update(['archived_at' => null]);
        $this->denied(fn () => $this->gis->update($id, $this->payload(['version' => $version,
            'geometry' => ['type' => 'Point', 'coordinates' => [181, 10]]]), $this->actor(), Request::create('/gis')));
        $this->gis->lifecycle($id, 'archive', $version, $this->actor(), Request::create('/gis'));
        $this->denied(fn () => $this->gis->update($id, $this->payload(['version' => (string) GisLocation::findOrFail($id)->updated_at]), $this->actor(), Request::create('/gis')));
    }

    public static function triggerDefinitions(): array
    {
        $cases = [[null, true], [[], true], [['multiple' => true], false]];
        foreach (['tgname' => 'unexpected_delete', 'function_oid' => '999', 'expected_oid' => null, 'tgtype' => 11,
            'tgenabled' => 'A', 'tgnargs' => 1, 'tgconstraint' => 1, 'columns' => '1', 'condition' => 'true',
            'tgoldtable' => 'old_rows', 'tgnewtable' => 'new_rows', 'lanname' => 'sql', 'prosecdef' => false,
            'pronargs' => 1, 'return_type' => 'void', 'trusted_owner' => false, 'function_config' => '{search_path=public}'] as $field => $value) {
            $cases[] = [['field' => $field, 'value' => $value], false];
        }
        return $cases;
    }

    #[DataProvider('triggerDefinitions')]
    public function test_ongoing_deletion_accepts_only_the_approved_trigger_definition(?array $change, bool $accepted): void
    {
        $id = $this->gis->store($this->payload(), $this->actor(), Request::create('/gis'))['feature']['id'];
        $trigger = GisSqliteConnection::approvedTrigger();
        if (isset($change['field'])) $trigger->{$change['field']} = $change['value'];
        $this->connection->deleteTriggers = $change === null ? [] : [$trigger];
        if ($change['multiple'] ?? false) {
            $unknown = GisSqliteConnection::approvedTrigger();
            $unknown->tgname = 'unknown_delete_trigger';
            $this->connection->deleteTriggers[] = $unknown;
        }
        $operation = fn () => $this->gis->destroy($id, (string) GisLocation::findOrFail($id)->updated_at, $this->actor());
        if ($accepted) {
            $operation();
            $this->assertNull(GisLocation::find($id));
            $this->assertSame(1, DB::table('audit_logs')->where('action_type', 'DELETE GIS')->count());
        } else {
            $this->denied($operation);
            $this->assertNotNull(GisLocation::find($id));
            $this->assertSame(1, DB::table('audit_logs')->count());
        }
    }

    public function test_media_foreign_key_preserves_permanent_delete_and_retains_photo_history(): void
    {
        Schema::create('media_photos', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('gis_location_id');
            $table->string('state');
        });
        $this->connection->incomingForeignKeys = [(object) [
            'conname' => 'media_photos_gis_location_id_fkey',
            'nspname' => 'public',
            'relname' => 'media_photos',
            'approved_media_fk' => true,
        ]];

        $empty = $this->gis->store($this->payload(), $this->actor(), Request::create('/gis'))['feature']['id'];
        $this->gis->destroy($empty, (string) GisLocation::findOrFail($empty)->updated_at, $this->actor());
        $this->assertNull(GisLocation::find($empty));

        $withHistory = $this->gis->store($this->payload(['idempotency_key' => '22222222-2222-4222-8222-222222222222']),
            $this->actor(), Request::create('/gis'))['feature']['id'];
        DB::table('media_photos')->insert([
            'id' => 'photo-history',
            'gis_location_id' => $withHistory,
            'state' => 'deleted',
        ]);
        $this->denied(fn () => $this->gis->destroy(
            $withHistory, (string) GisLocation::findOrFail($withHistory)->updated_at, $this->actor()
        ));
        $this->assertNotNull(GisLocation::find($withHistory));
        $this->assertSame(1, DB::table('media_photos')->where('id', 'photo-history')->count());
    }

    public function test_unrecognized_incoming_foreign_key_blocks_deletion(): void
    {
        $this->connection->incomingForeignKeys = [(object) [
            'conname' => 'unexpected_location_reference',
            'nspname' => 'public',
            'relname' => 'unknown_table',
            'approved_media_fk' => false,
        ]];
        $id = $this->gis->store($this->payload(), $this->actor(), Request::create('/gis'))['feature']['id'];
        $this->denied(fn () => $this->gis->destroy(
            $id, (string) GisLocation::findOrFail($id)->updated_at, $this->actor()
        ));
        $this->assertNotNull(GisLocation::find($id));
    }

    public function test_required_audit_failure_rolls_back_gis_changes(): void
    {
        $this->finalize('terminated');
        $id = $this->gis->store($this->payload(), $this->actor(), Request::create('/gis'))['feature']['id'];
        $before = $this->snapshot();
        $this->connection->rejectAudit = true;
        try {
            $this->gis->lifecycle($id, 'publish', (string) GisLocation::findOrFail($id)->updated_at, $this->actor(), Request::create('/gis'));
            $this->fail('The required audit must fail.');
        } catch (RuntimeException $exception) { $this->assertSame('Synthetic audit failure.', $exception->getMessage()); }
        $this->assertFalse(GisLocation::findOrFail($id)->is_published);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, DB::table('audit_logs')->count());
    }

    #[DataProvider('finalStatuses')]
    public function test_an_ongoing_location_cannot_be_reassigned_into_finalized_history(string $status): void
    {
        $this->finalize($status);
        $id = $this->gis->store($this->payload(['project_id' => 'other-project']), $this->actor(), Request::create('/gis'))['feature']['id'];
        $this->denied(fn () => $this->gis->update($id, $this->payload([
            'version' => (string) GisLocation::findOrFail($id)->updated_at,
        ]), $this->actor(), Request::create('/gis')));
        $this->assertSame('other-project', GisLocation::findOrFail($id)->project_id);
        $this->assertSame(1, DB::table('audit_logs')->count());
    }

    public function test_required_audit_failure_rolls_back_eligible_deletion(): void
    {
        $id = $this->gis->store($this->payload(), $this->actor(), Request::create('/gis'))['feature']['id'];
        $this->connection->deleteTriggers = [GisSqliteConnection::approvedTrigger()];
        $this->connection->rejectAudit = true;
        try {
            $this->gis->destroy($id, (string) GisLocation::findOrFail($id)->updated_at, $this->actor());
            $this->fail('The required audit must fail.');
        } catch (RuntimeException $exception) { $this->assertSame('Synthetic audit failure.', $exception->getMessage()); }
        $this->assertNotNull(GisLocation::findOrFail($id));
        $this->assertSame(1, DB::table('audit_logs')->count());
    }

    #[DataProvider('historicalGeometryCases')]
    public function test_admin_geometry_correction_preserves_lifecycles_ownership_publication_and_history(string $status, string $archival): void
    {
        $this->finalize($status);
        $feature = $this->gis->store($this->payload(), $this->actor(), Request::create('/gis'))['feature'];
        $id = $feature['id'];
        $this->gis->lifecycle($id, 'publish', $feature['properties']['version'], $this->actor(), Request::create('/gis'));
        if ($archival === 'association') DB::table('associations')->update(['archived_at' => now()]);
        elseif ($archival === 'inactive archived association') DB::table('associations')->update(['status' => 'inactive', 'archived_at' => now()]);
        elseif ($archival === 'project') DB::table('projects')->where('id', 'project')->update(['archived_at' => now()]);
        else DB::table('gis_locations')->where('id', $id)->update(['archived_at' => now()]);
        $before = $this->snapshot();
        $associations = DB::table('associations')->get()->toJson();
        $saved = GisLocation::findOrFail($id);
        $attributes = $saved->getRawOriginal();
        $geometry = ['type' => 'Point', 'coordinates' => [124.2, 10.8]];
        $result = $this->gis->correctGeometry($id, ['version' => (string) $saved->updated_at, 'geometry' => $geometry],
            $this->actor(), Request::create('/gis'));
        $this->assertSame($geometry, $result['geometry']);
        $this->assertTrue($result['properties']['can_correct_geometry']);
        $this->assertSame($associations, DB::table('associations')->get()->toJson());
        $this->assertSame($before, $this->snapshot());
        $fresh = $saved->fresh();
        foreach (array_diff(array_keys($attributes), ['geom', 'updated_by', 'updated_at']) as $field) {
            $this->assertSame($attributes[$field], $fresh->getRawOriginal($field));
        }
        $this->assertSame('admin', $fresh->updated_by);
        $event = DB::table('audit_logs')->where('action_type', 'CORRECT GIS GEOMETRY')->sole();
        $this->assertSame('admin', $event->actor_user_id);
        $this->assertSame($id, $event->entity_id);
        $this->assertSame($geometry, json_decode($event->new_values, true)['geometry']);
        $this->assertSame(['association_id', 'project_id', 'location_type', 'geometry'], array_keys(json_decode($event->old_values, true)));
    }

    public static function historicalGeometryCases(): array
    {
        $cases = [];
        foreach (['ongoing', 'completed', 'terminated'] as $status) {
            foreach (['association', 'inactive archived association', 'project', 'location'] as $archival) $cases[] = [$status, $archival];
        }

        return $cases;
    }

    public function test_geometry_correction_rejects_unauthorized_and_revoked_actors(): void
    {
        $feature = $this->gis->store($this->payload(), $this->actor(), Request::create('/gis'))['feature'];
        DB::table('associations')->update(['archived_at' => now()]);
        $data = ['geometry' => ['type' => 'Point', 'coordinates' => [124, 10]], 'version' => $feature['properties']['version']];
        $before = GisLocation::findOrFail($feature['id'])->getRawOriginal();
        foreach (['assigned', 'other', 'account'] as $id) {
            $this->denied(fn () => $this->gis->correctGeometry($feature['id'], $data, $this->actor($id), Request::create('/gis')));
        }
        $cached = $this->actor(); $cached->canAccessApplication();
        DB::table('user_profiles')->where('id', 'admin')->update(['is_active' => false]);
        $this->denied(fn () => $this->gis->correctGeometry($feature['id'], $data, $cached, Request::create('/gis')));
        DB::table('user_profiles')->where('id', 'admin')->update(['is_active' => true, 'role_id' => 2]);
        $this->denied(fn () => $this->gis->correctGeometry($feature['id'], $data, $cached, Request::create('/gis')));
        DB::table('user_profiles')->where('id', 'admin')->update(['role_id' => 1]);
        DB::table('roles')->where('id', 1)->update(['is_active' => false]);
        $this->denied(fn () => $this->gis->correctGeometry($feature['id'], $data, $cached, Request::create('/gis')));
        $this->assertSame($before, GisLocation::findOrFail($feature['id'])->getRawOriginal());
        $this->assertSame(0, DB::table('audit_logs')->where('action_type', 'CORRECT GIS GEOMETRY')->count());
    }

    public function test_geometry_correction_rejects_stale_invalid_or_extra_fields_and_requires_audit_success(): void
    {
        $feature = $this->gis->store($this->payload(), $this->actor(), Request::create('/gis'))['feature'];
        $id = $feature['id'];
        $data = ['geometry' => ['type' => 'Point', 'coordinates' => [124, 10]], 'version' => $feature['properties']['version']];
        $this->denied(fn () => $this->gis->correctGeometry($id, $data, $this->actor(), Request::create('/gis')));
        DB::table('associations')->update(['archived_at' => now()]);
        $before = GisLocation::findOrFail($id)->getRawOriginal();
        foreach ([['version' => 'stale'], ['geometry' => ['type' => 'Point', 'coordinates' => [181, 10]]],
            ['geometry' => ['type' => 'Polygon', 'coordinates' => []]], ['project_id' => 'other-project'],
            ['is_published' => true], ['association_id' => 'other'], ['status' => 'active']] as $change) {
            $this->denied(fn () => $this->gis->correctGeometry($id, array_replace($data, $change), $this->actor(), Request::create('/gis')));
            $this->assertSame($before, GisLocation::findOrFail($id)->getRawOriginal());
        }
        $this->connection->rejectAudit = true;
        try {
            $this->gis->correctGeometry($id, $data, $this->actor(), Request::create('/gis'));
            $this->fail('Corrections require an audit event.');
        } catch (RuntimeException $exception) { $this->assertSame('Synthetic audit failure.', $exception->getMessage()); }
        $this->assertSame($before, GisLocation::findOrFail($id)->getRawOriginal());
        $this->connection->rejectAudit = false;
        $this->gis->correctGeometry($id, $data, $this->actor(), Request::create('/gis'));
        $saved = GisLocation::findOrFail($id)->getRawOriginal();
        $this->denied(fn () => $this->gis->correctGeometry($id, $data, $this->actor(), Request::create('/gis')));
        $this->assertSame($saved, GisLocation::findOrFail($id)->getRawOriginal());
        $this->assertSame(1, DB::table('audit_logs')->where('action_type', 'CORRECT GIS GEOMETRY')->count());
        $current = $this->queries->detail($this->actor(), $id);
        $this->gis->correctGeometry($id, array_replace($data, ['version' => $current['properties']['version']]), $this->actor(), Request::create('/gis'));
        $this->assertSame(2, DB::table('audit_logs')->where('action_type', 'CORRECT GIS GEOMETRY')->count());
    }

    public function test_geometry_correction_http_is_admin_only_strict_and_keeps_general_editing_blocked(): void
    {
        $feature = $this->gis->store($this->payload(), $this->actor(), Request::create('/gis'))['feature'];
        $id = $feature['id'];
        DB::table('associations')->update(['archived_at' => now()]);
        $this->app->instance(GisQueryService::class, $this->queries);
        $this->app->instance(GisLocationService::class, $this->gis);
        $identity = 'admin';
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->andReturnUsing(function () use (&$identity) { return ['id' => $identity]; });
        $this->app->instance(SupabaseAuthService::class, $auth);
        $data = ['geometry' => ['type' => 'Point', 'coordinates' => [124, 10]], 'version' => $feature['properties']['version']];
        $this->get('/admin/gis/'.$id.'/coordinates')->assertRedirect(route('login'));
        $this->patchJson('/admin/gis/'.$id.'/coordinates', $data)->assertRedirect(route('login'));
        $this->withSession(['supabase_access_token' => 'synthetic-token']);
        foreach (['assigned', 'other', 'account'] as $identity) {
            $this->get('/admin/gis/'.$id.'/coordinates')->assertForbidden();
            $this->patchJson('/admin/gis/'.$id.'/coordinates', $data)->assertForbidden();
        }
        $identity = 'assigned';
        $this->getJson('/field-officer/gis/'.$id.'?association_id=association')->assertNotFound();
        $identity = 'admin';
        $this->getJson('/admin/gis/'.$id)->assertOk()->assertJsonPath('properties.can_correct_geometry', true);
        $this->get('/admin/gis/'.$id.'/edit')->assertConflict();
        $page = $this->get('/admin/gis/'.$id.'/coordinates')->assertOk()->assertSee('Correct historical GIS geometry')
            ->assertSee('data-gis-correction="true"', false)->assertSee('<fieldset disabled>', false);
        $this->assertSame($id, $page->viewData('gisFeature')['id']);
        $this->patchJson('/admin/gis/'.$id.'/coordinates', $data + ['project_id' => 'other-project'])
            ->assertUnprocessable()->assertJsonValidationErrors('project_id');
        $this->patchJson('/admin/gis/'.$id.'/coordinates', $data + ['is_published' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('is_published');
        foreach (['association_id', 'location_type', 'location_name', 'archived_at', 'public_title', 'status'] as $field) {
            $this->patchJson('/admin/gis/'.$id.'/coordinates', $data + [$field => 'tampered'])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->patchJson('/admin/gis/'.$id.'/coordinates', array_replace($data, ['geometry' => ['type' => 'Point', 'coordinates' => [181, 10]]]))
            ->assertUnprocessable();
        $this->patchJson('/admin/gis/'.$id.'/coordinates', $data)->assertOk()->assertJsonPath('geometry', $data['geometry']);
        $this->patchJson('/admin/gis/'.$id.'/coordinates', $data)->assertConflict();
        $this->assertNotNull(Association::findOrFail('association')->archived_at);
        $this->assertSame(1, DB::table('audit_logs')->where('action_type', 'CORRECT GIS GEOMETRY')->count());
        $this->app->bind(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, function ($app) {
            return new class($app, $app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken {
                protected function runningUnitTests() { return false; }
            };
        });
        $this->patchJson('/admin/gis/'.$id.'/coordinates', $data)->assertStatus(419);
        $this->assertSame(1, DB::table('audit_logs')->where('action_type', 'CORRECT GIS GEOMETRY')->count());
    }

    public function test_geometry_correction_preserves_saved_boundary_type_and_polygon_parts(): void
    {
        $outer = [[123, 10], [124, 10], [124, 11], [123, 10]];
        $feature = $this->gis->store($this->payload(['location_type' => 'project_boundary',
            'geometry' => ['type' => 'Polygon', 'coordinates' => [$outer]]]), $this->actor(), Request::create('/gis'))['feature'];
        DB::table('associations')->update(['archived_at' => now()]);
        $geometry = ['type' => 'MultiPolygon', 'coordinates' => [[$outer], [[[125, 10], [126, 10], [126, 11], [125, 10]]]]];
        $result = $this->gis->correctGeometry($feature['id'], ['version' => $feature['properties']['version'], 'geometry' => $geometry],
            $this->actor(), Request::create('/gis'));
        $this->assertSame($geometry, $result['geometry']);
        $this->assertSame('project_boundary', $result['properties']['location_type']);
    }

    private function denied(callable $operation): void
    {
        try { $operation(); $this->fail('The mutation must be denied.'); }
        catch (ValidationException $exception) { $this->assertNotEmpty($exception->errors()); }
        catch (HttpException $exception) { $this->assertContains($exception->getStatusCode(), [403, 404, 409, 422]); }
    }
}
