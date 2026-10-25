<?php

namespace Tests\Feature;

use App\Services\SupabaseAuthService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedDatabaseManager;
use Tests\Support\MonitoringMaterialsFixture;
use Tests\TestCase;

class MonitoringVisitBindingTest extends TestCase
{
    use MonitoringMaterialsFixture;

    private string $identity = self::ADMIN;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertInstanceOf(IsolatedDatabaseManager::class, DB::getFacadeRoot());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        Http::swap((new Factory)->preventStrayRequests());
        $this->withoutVite();
        $this->schema();
        $this->fixtures();
        Schema::table('user_invitations', function (Blueprint $table) {
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_user_id');
            $table->string('action_type');
            $table->string('module');
            $table->string('entity_type');
            $table->uuid('entity_id');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('performed_at');
        });
        DB::table('audit_logs')->insert([
            'id' => self::id(90), 'actor_user_id' => self::ADMIN, 'action_type' => 'update',
            'module' => 'monitoring', 'entity_type' => 'monitoring_record', 'entity_id' => self::RECORD,
            'old_values' => '{"income_version":4}', 'new_values' => '{"income_version":5}',
            'performed_at' => '2026-01-15 10:00:00',
        ]);
        DB::table('monitoring_records')->update(['income_version' => 5, 'materials_version' => 7]);
        DB::table('user_profiles')->insert([
            'id' => self::id(1), 'role_id' => 2, 'display_name' => 'Other Synthetic Officer', 'is_active' => true,
        ]);
        DB::table('associations')->insert([
            'id' => self::id(5), 'name' => 'Foreign Synthetic Association', 'status' => 'active',
            'primary_field_officer_id' => self::id(1),
        ]);
        foreach ([[2, 3, self::ASSOCIATION], [6, 7, self::id(5)]] as [$projectId, $recordId, $associationId]) {
            $project = (array) DB::table('projects')->where('id', self::PROJECT)->first();
            DB::table('projects')->insert(array_replace($project, [
                'id' => self::id($projectId), 'association_id' => $associationId,
                'title' => 'Synthetic Project '.$projectId,
            ]));
            $record = (array) DB::table('monitoring_records')->where('id', self::RECORD)->first();
            DB::table('monitoring_records')->insert(array_replace($record, [
                'id' => self::id($recordId), 'project_id' => self::id($projectId),
            ]));
        }
        foreach ([[9, self::RECORD], [4, self::id(3)], [8, self::id(7)]] as [$visitId, $recordId]) {
            DB::table('monitoring_visit_reports')->insert($this->payload() + [
                'id' => self::id($visitId), 'monitoring_record_id' => $recordId,
                'monitored_by' => self::OFFICER, 'created_by' => self::OFFICER,
                'updated_by' => self::OFFICER, 'is_legacy_import' => false,
                'created_at' => '2026-01-16 10:00:00', 'updated_at' => '2026-01-16 10:00:00',
            ]);
            DB::table('monitoring_visit_reports')->where('id', self::id($visitId))
                ->update(['overall_remarks' => 'Visit '.self::id($visitId)]);
        }
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->andReturnUsing(fn () => ['id' => $this->identity]);
        $this->app->instance(SupabaseAuthService::class, $auth);
        $this->withSession(['supabase_access_token' => 'synthetic-token']);
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
            parent::tearDown();
        } finally {
            HandleExceptions::flushState($this);
        }
    }

    private static function id(int $number): string
    {
        return sprintf('99999999-0000-4000-8000-%012d', $number);
    }

    public static function workflows(): array
    {
        return [
            'shared administrator' => ['shared', 'admin', self::ADMIN],
            'shared assigned officer' => ['shared', 'field-officer', self::OFFICER],
            'legacy administrator' => ['legacy', 'admin', self::ADMIN],
            'legacy assigned officer' => ['legacy', 'field-officer', self::OFFICER],
        ];
    }

    public static function officerWorkflows(): array
    {
        return ['shared' => ['shared'], 'legacy' => ['legacy']];
    }

    private function payload(): array
    {
        return [
            'monitoring_date' => '2026-01-16', 'site_validated' => true,
            'conducted_by_user_id' => null, 'conducted_by' => 'Synthetic Observer',
            'issues_encountered' => 'Synthetic issue', 'actions_taken' => 'Synthetic action',
            'overall_remarks' => 'Updated synthetic visit',
        ];
    }

    private function url(string $family, string $area, string $operation, string $record, ?string $visit = null): string
    {
        $parameters = [$record];
        if ($visit !== null) {
            $parameters[] = $visit;
        }

        return route($area.'.monitoring.'.($family === 'legacy' ? 'visit-reports.' : 'visit.').$operation, $parameters);
    }

    private function assertReadable(string $family, string $area, string $record, string $visit): void
    {
        if ($family === 'legacy') {
            $this->getJson($this->url($family, $area, 'index', $record))->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visit)
                ->assertJsonPath('data.0.monitoring_record_id', $record);
        } else {
            $this->get($this->url($family, $area, 'view', $record, $visit))
                ->assertRedirect(route($area.'.monitoring.visits', $record));
            $this->get(route($area.'.monitoring.visits', $record))->assertOk()->assertSee('Visit '.$visit);
        }
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach ([
            'associations', 'association_accounts', 'projects', 'project_products', 'project_materials',
            'monitoring_records', 'monitoring_visit_reports', 'monitoring_production',
            'monitoring_production_monthly', 'monitoring_income', 'monitoring_materials', 'audit_logs',
        ] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }

    #[DataProvider('workflows')]
    public function test_authorized_correct_parent_reads_and_updates_persist(string $family, string $area, string $actor): void
    {
        $this->identity = $actor;
        $this->assertReadable($family, $area, self::RECORD, self::id(9));
        if ($family === 'shared') {
            $this->get($this->url($family, $area, 'edit', self::RECORD, self::id(9)))
                ->assertOk()->assertSee('Visit '.self::id(9));
        }
        $before = $this->snapshot();
        $response = $this->patchJson($this->url($family, $area, 'update', self::RECORD, self::id(9)), $this->payload());
        if ($family === 'legacy') {
            $response->assertOk()->assertJsonPath('data.0.id', self::id(9))
                ->assertJsonPath('data.0.overall_remarks', 'Updated synthetic visit');
        } else {
            $response->assertRedirect(route($area.'.monitoring.visits', self::RECORD))->assertSessionHasNoErrors();
        }
        $this->assertDatabaseHas('monitoring_visit_reports', [
            'id' => self::id(9), 'monitoring_record_id' => self::RECORD,
            'overall_remarks' => 'Updated synthetic visit', 'updated_by' => $actor,
        ]);
        $after = $this->snapshot();
        unset($before['monitoring_visit_reports'], $after['monitoring_visit_reports']);
        $this->assertSame($before, $after);
        $this->assertDatabaseCount('monitoring_visit_reports', 3);
    }

    #[DataProvider('workflows')]
    public function test_another_parent_in_the_same_authorized_association_cannot_address_the_visit(string $family, string $area, string $actor): void
    {
        $this->identity = $actor;
        $this->assertReadable($family, $area, self::RECORD, self::id(9));
        $this->assertReadable($family, $area, self::id(3), self::id(4));
        $before = $this->snapshot();
        $this->patchJson($this->url($family, $area, 'update', self::id(3), self::id(9)), $this->payload())->assertNotFound();
        if ($family === 'shared') {
            foreach (['view', 'edit'] as $operation) {
                $this->get($this->url($family, $area, $operation, self::id(3), self::id(9)))->assertNotFound();
            }
        }
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('workflows')]
    public function test_foreign_association_children_and_unassigned_parents_are_rejected(string $family, string $area, string $actor): void
    {
        $this->identity = $actor;
        $this->assertReadable($family, $area, self::RECORD, self::id(9));
        $before = $this->snapshot();
        $this->patchJson($this->url($family, $area, 'update', self::RECORD, self::id(8)), $this->payload())->assertNotFound();
        if ($family === 'shared') {
            $this->get($this->url($family, $area, 'view', self::RECORD, self::id(8)))->assertNotFound();
        }
        if ($actor === self::ADMIN) {
            $this->assertReadable($family, $area, self::id(7), self::id(8));
        } else {
            $this->assertOfficerDenied($family, self::id(7), self::id(8));
        }
        $this->assertSame($before, $this->snapshot());
    }

    private function assertOfficerDenied(string $family, string $record, string $visit): void
    {
        $read = $this->getJson($this->url($family, 'field-officer', $family === 'legacy' ? 'index' : 'view', $record, $family === 'legacy' ? null : $visit));
        $write = $this->patchJson($this->url($family, 'field-officer', 'update', $record, $visit), $this->payload());
        if ($family === 'shared') {
            $read->assertNotFound();
            $write->assertNotFound();
        } else {
            $read->assertUnprocessable()->assertJsonValidationErrors('authorization');
            $write->assertUnprocessable()->assertJsonValidationErrors('project_id');
        }
    }

    #[DataProvider('officerWorkflows')]
    public function test_assignment_changes_revoke_access_and_the_new_assigned_officer_can_read(string $family): void
    {
        $this->identity = self::OFFICER;
        $this->assertReadable($family, 'field-officer', self::RECORD, self::id(9));
        DB::table('associations')->where('id', self::ASSOCIATION)->update(['primary_field_officer_id' => self::id(1)]);
        $before = $this->snapshot();
        $this->assertOfficerDenied($family, self::RECORD, self::id(9));
        $this->assertSame($before, $this->snapshot());
        $this->identity = self::id(1);
        $this->assertReadable($family, 'field-officer', self::RECORD, self::id(9));
    }

    #[DataProvider('workflows')]
    public function test_role_revocation_and_guest_denials_cannot_mutate_valid_records(string $family, string $area, string $actor): void
    {
        $this->identity = $actor;
        $this->assertReadable($family, $area, self::RECORD, self::id(9));
        $read = $this->url($family, $area, $family === 'legacy' ? 'index' : 'view', self::RECORD, $family === 'legacy' ? null : self::id(9));
        $write = $this->url($family, $area, 'update', self::RECORD, self::id(9));
        $create = $this->url($family, $area, 'store', self::RECORD);
        $before = $this->snapshot();
        foreach ([self::ACCOUNT, $actor === self::ADMIN ? self::OFFICER : self::ADMIN] as $wrongRole) {
            $this->identity = $wrongRole;
            $this->getJson($read)->assertForbidden();
            $this->patchJson($write, $this->payload())->assertForbidden();
            $this->postJson($create, $this->payload())->assertForbidden();
        }
        $this->identity = $actor;
        DB::table('user_profiles')->where('id', $actor)->update(['is_active' => false]);
        foreach (['revoked', 'guest'] as $state) {
            foreach (['get', 'patch', 'post'] as $method) {
                if ($state === 'revoked') {
                    $this->withSession(['supabase_access_token' => 'synthetic-token']);
                } else {
                    $this->flushSession();
                }
                $url = $method === 'get' ? $read : ($method === 'patch' ? $write : $create);
                $this->json(strtoupper($method), $url, $method === 'get' ? [] : $this->payload())
                    ->assertRedirect(route('login'))->assertSessionMissing('supabase_access_token');
            }
        }
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('workflows')]
    public function test_client_supplied_parent_and_identity_cannot_reparent_or_create_a_visit(string $family, string $area, string $actor): void
    {
        $this->identity = $actor;
        $this->assertReadable($family, $area, self::RECORD, self::id(9));
        $before = $this->snapshot();
        $payload = $this->payload() + ['monitoring_record_id' => self::id(3), 'id' => self::id(8)];
        $this->patchJson($this->url($family, $area, 'update', self::RECORD, self::id(9)), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['monitoring_record_id', 'id']);
        $this->postJson($this->url($family, $area, 'store', self::RECORD), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['monitoring_record_id', 'id']);
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('workflows')]
    public function test_authorized_creation_is_attached_to_the_requested_parent(string $family, string $area, string $actor): void
    {
        $this->identity = $actor;
        $this->assertReadable($family, $area, self::RECORD, self::id(9));
        $response = $this->postJson($this->url($family, $area, 'store', self::RECORD), $this->payload());
        if ($family === 'legacy') {
            $response->assertCreated()->assertJsonCount(2, 'data');
        } else {
            $response->assertRedirect(route($area.'.monitoring.visits', self::RECORD))->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('monitoring_visit_reports', 4);
        $this->assertDatabaseHas('monitoring_visit_reports', [
            'monitoring_record_id' => self::RECORD, 'overall_remarks' => 'Updated synthetic visit',
            'created_by' => $actor, 'updated_by' => $actor, 'monitored_by' => $actor, 'is_legacy_import' => false,
        ]);
    }

    public function test_association_account_reads_only_owned_parent_child_pairs_and_has_no_write_route(): void
    {
        $this->identity = self::ACCOUNT;
        $this->assertReadable('shared', 'association', self::RECORD, self::id(9));
        $this->assertReadable('shared', 'association', self::id(3), self::id(4));
        $before = $this->snapshot();
        $this->get($this->url('shared', 'association', 'view', self::id(3), self::id(9)))->assertNotFound();
        $this->get($this->url('shared', 'association', 'view', self::id(7), self::id(8)))->assertNotFound();
        $this->patchJson('/association/monitoring/'.self::RECORD.'/visits/'.self::id(9), $this->payload())->assertStatus(405);
        $this->get('/association/monitoring/'.self::RECORD.'/visits/'.self::id(9).'/edit')->assertNotFound();
        $this->get(route('association.monitoring.visits', self::RECORD))->assertOk()
            ->assertDontSee('Add Visit Report')->assertDontSee('Edit Visit Report');
        $this->assertSame($before, $this->snapshot());
    }
}
