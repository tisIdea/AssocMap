<?php

namespace Tests\Feature;

use App\Http\Controllers\GisWorkspaceController;
use App\Models\GisLocation;
use App\Models\Project;
use App\Models\UserProfile;
use App\Services\GisAddressResolver;
use App\Services\GisFeatureSerializer;
use App\Services\GisGeometry;
use App\Services\GisLocationService;
use App\Services\GisQueryService;
use App\Services\ProjectLifecycleService;
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
        DB::table('associations')->insert(['id' => 'association', 'status' => 'active', 'primary_field_officer_id' => 'assigned']);
        foreach (['project', 'other-project'] as $id) {
            DB::table('projects')->insert(['id' => $id, 'association_id' => 'association', 'status' => 'ongoing', 'title' => $id]);
        }
        foreach ($this->historyTables() as $table) DB::table($table)->insert(['id' => 'history', 'value' => 'preserve '.$table]);
        $this->queries = new class(new GisFeatureSerializer, new GisAddressResolver) extends GisQueryService
        {
            public function detail(UserProfile $user, string $id): array
            {
                $location = GisLocation::findOrFail($id);
                $project = Project::findOrFail($location->project_id);
                $location->forceFill(['geometry_json' => $location->geom, 'version' => (string) $location->updated_at,
                    'project_status' => $project->status, 'project_closed_at' => $project->closed_at,
                    'parent_association_id' => $project->association_id]);

                return (new GisFeatureSerializer)->make($location, false, true);
            }
        };
        $geometry = Mockery::mock(GisGeometry::class)->makePartial();
        $geometry->shouldReceive('validateSpatial')->andReturnNull();
        $this->gis = new GisLocationService($geometry, $this->queries, app(ProjectLifecycleService::class));
    }

    private function schema(): void
    {
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('code'); $t->boolean('is_active'); });
        Schema::create('user_profiles', function (Blueprint $t) {
            $t->string('id')->primary(); $t->integer('role_id'); $t->string('display_name'); $t->boolean('is_active'); $t->timestamps();
        });
        Schema::create('user_invitations', function (Blueprint $t) {
            $t->string('auth_user_id')->nullable(); $t->string('status'); $t->string('last_error_code')->nullable();
            $t->timestamp('accepted_at')->nullable(); $t->timestamp('last_attempt_at')->nullable(); $t->timestamps();
        });
        Schema::create('associations', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('status'); $t->string('primary_field_officer_id'); $t->timestamp('archived_at')->nullable();
        });
        Schema::create('projects', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('association_id'); $t->string('title'); $t->string('status');
            $t->timestamp('closed_at')->nullable(); $t->text('closure_remarks')->nullable(); $t->string('closed_by')->nullable();
            $t->timestamp('archived_at')->nullable(); $t->timestamps();
        });
        Schema::create('gis_locations', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('association_id')->nullable(); $t->string('project_id');
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

    private function denied(callable $operation): void
    {
        try { $operation(); $this->fail('The mutation must be denied.'); }
        catch (ValidationException $exception) { $this->assertNotEmpty($exception->errors()); }
        catch (HttpException $exception) { $this->assertContains($exception->getStatusCode(), [403, 404, 409, 422]); }
    }
}
