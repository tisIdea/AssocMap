<?php

namespace Tests\Support;

use App\Models\Association;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\UserProfile;
use App\Services\Associations\AssociationOfficerService;
use App\Services\Members\MemberApplicationService;
use App\Services\Members\MemberService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait MemberMutationFixture
{
    public const ADMIN = '11111111-1111-4111-8111-111111111111';

    public const OFFICER = '22222222-2222-4222-8222-222222222222';

    public const OTHER_OFFICER = '33333333-3333-4333-8333-333333333333';

    public const ASSOCIATION = '44444444-4444-4444-8444-444444444444';

    public const OTHER_ASSOCIATION = '55555555-5555-4555-8555-555555555555';

    public const ACCOUNT = '66666666-6666-4666-8666-666666666666';

    public const LINK = '77777777-7777-4777-8777-777777777777';

    public const APPLICATION = '88888888-8888-4888-8888-888888888888';

    public const CANDIDATE = '99999999-9999-4999-8999-999999999999';

    public const FORMER = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    public function sec2Schema(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->boolean('is_active');
            $table->timestamps();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
        });
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('role_id');
            $table->string('display_name');
            $table->boolean('is_active');
            $table->timestamps();
            $table->timestampTz('auth_sessions_valid_after')->nullable();
            $table->uuid('email_change_hold_id')->nullable();
        });
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('auth_user_id')->nullable();
            $table->string('email');
            $table->string('status');
            $table->string('last_error_code')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();
        });
        Schema::create('associations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('status');
            $table->uuid('area_id')->nullable();
            $table->uuid('barangay_id')->nullable();
            $table->uuid('primary_field_officer_id');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::create('association_positions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('is_officer');
            $table->boolean('is_active');
            $table->integer('sort_order');
            $table->timestamps();
        });
        Schema::create('association_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('association_id');
            $table->uuid('user_id');
            $table->boolean('is_active');
            $table->timestamps();
        });
        Schema::create('sex_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active');
            $table->timestamps();
        });
        Schema::create('member_classifications', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active');
            $table->timestamps();
        });
        Schema::create('member_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('association_id');
            $table->uuid('submitted_by_user_id');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('suffix')->nullable();
            $table->date('birth_date');
            $table->integer('sex_id');
            $table->string('contact_number')->nullable();
            $table->string('fishr_registration_number')->nullable();
            $table->unsignedBigInteger('position_id');
            $table->string('position_other_text')->nullable();
            $table->string('status');
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->text('endorsement_letter')->nullable();
            $table->string('applicant_photo_path')->nullable();
        });
        Schema::create('member_application_classifications', function (Blueprint $table) {
            $table->uuid('member_application_id');
            $table->unsignedBigInteger('classification_id');
            $table->primary(['member_application_id', 'classification_id']);
        });
        Schema::create('members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('association_id');
            $table->uuid('source_application_id')->nullable()->unique();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('suffix')->nullable();
            $table->date('birth_date');
            $table->integer('sex_id');
            $table->string('contact_number')->nullable();
            $table->string('fishr_registration_number')->nullable()->unique();
            $table->unsignedBigInteger('position_id');
            $table->string('position_other_text')->nullable();
            $table->date('date_registered');
            $table->string('member_status');
            $table->timestamp('archived_at')->nullable();
            $table->uuid('archived_by')->nullable();
            $table->timestamps();
            $table->foreign('association_id')->references('id')->on('associations');
            $table->foreign('position_id')->references('id')->on('association_positions');
            $table->foreign('source_application_id')->references('id')->on('member_applications');
        });
        Schema::create('member_classification_assignments', function (Blueprint $table) {
            $table->uuid('member_id');
            $table->unsignedBigInteger('classification_id');
            $table->primary(['member_id', 'classification_id']);
            $table->foreign('member_id')->references('id')->on('members');
        });
        // This fixture is not a substitute for migration 008 or PostgreSQL concurrency testing.
        DB::statement("CREATE UNIQUE INDEX uq_members_active_executive_position ON members (association_id, position_id)
            WHERE member_status = 'active' AND archived_at IS NULL AND position_id IN (2, 3)");
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_user_id')->nullable();
            $table->string('action_type');
            $table->string('module');
            $table->string('entity_type')->nullable();
            $table->text('entity_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('performed_at');
        });
    }

    public function sec2Fixtures(): void
    {
        foreach (['audit_logs', 'member_classification_assignments', 'members', 'member_application_classifications',
            'member_applications', 'association_accounts', 'associations', 'user_invitations', 'user_profiles',
            'roles', 'association_positions', 'member_classifications', 'sex_categories'] as $table) {
            DB::table($table)->delete();
        }
        foreach ([1 => 'system_admin', 2 => 'field_officer', 3 => 'association_account'] as $id => $code) {
            DB::table('roles')->insert(['id' => $id, 'code' => $code, 'is_active' => true]);
        }
        foreach ([self::ADMIN => 1, self::OFFICER => 2, self::OTHER_OFFICER => 2, self::ACCOUNT => 3] as $id => $role) {
            DB::table('user_profiles')->insert(['id' => $id, 'role_id' => $role,
                'display_name' => 'Synthetic Actor', 'is_active' => true]);
        }
        foreach ([self::ASSOCIATION, self::OTHER_ASSOCIATION] as $id) {
            DB::table('associations')->insert(['id' => $id, 'name' => 'Synthetic Association',
                'status' => 'active', 'primary_field_officer_id' => self::OFFICER]);
        }
        foreach ([1 => 'MEMBER', 2 => 'PRESIDENT', 3 => 'VICE_PRESIDENT', 4 => 'SECRETARY', 7 => 'OTHER'] as $id => $code) {
            DB::table('association_positions')->insert(['id' => $id, 'code' => $code, 'name' => $code,
                'is_officer' => in_array($id, [2, 3, 4]), 'is_active' => true, 'sort_order' => $id]);
        }
        DB::table('association_accounts')->insert(['id' => self::LINK, 'association_id' => self::ASSOCIATION,
            'user_id' => self::ACCOUNT, 'is_active' => true]);
        DB::table('member_classifications')->insert(['id' => 1, 'code' => 'FISHERFOLK', 'name' => 'Fisherfolk', 'is_active' => true]);
        DB::table('sex_categories')->insert(['id' => 1, 'name' => 'Synthetic category', 'is_active' => true]);
    }

    public static function sec2Operations(): array
    {
        $cases = [];
        foreach (['update', 'archive', 'unarchive', 'approve', 'reject'] as $operation) {
            $cases[$operation] = [$operation, 'officer'];
        }
        foreach (['assign', 'replace', 'remove'] as $operation) {
            foreach (['admin', 'officer'] as $role) {
                $cases[$operation.' '.$role] = [$operation, $role];
            }
        }
        $cases['submit'] = ['submit', 'account'];

        return $cases;
    }

    public function sec2Actor(string $role): UserProfile
    {
        $actor = UserProfile::with('role')->findOrFail(match ($role) {
            'admin' => self::ADMIN, 'officer' => self::OFFICER, 'account' => self::ACCOUNT,
        });
        if (! $actor->canAccessApplication(throwOnDatabaseError: true)) {
            throw new \RuntimeException('The authenticated fixture must initially be eligible.');
        }

        return $actor;
    }

    public function sec2Context(string $operation): array
    {
        $application = new MemberApplication;
        $application->forceFill(['id' => self::APPLICATION, 'association_id' => self::ASSOCIATION,
            'submitted_by_user_id' => self::ACCOUNT, 'first_name' => 'Synthetic', 'last_name' => 'Applicant',
            'birth_date' => '2000-01-01', 'sex_id' => 1, 'position_id' => 2, 'status' => 'pending'])->save();
        $application->classifications()->attach(1);
        $attributes = ['association_id' => self::ASSOCIATION, 'first_name' => 'Synthetic', 'last_name' => 'Member',
            'birth_date' => '2000-01-01', 'sex_id' => 1, 'date_registered' => '2025-01-01', 'member_status' => 'active'];
        $member = new Member;
        $member->forceFill($attributes + ['id' => self::CANDIDATE,
            'position_id' => in_array($operation, ['remove', 'unarchive'], true) ? 2 : 1,
            'archived_at' => $operation === 'unarchive' ? '2025-01-01 00:00:00' : null])->save();
        $member->refresh();
        $data = ['member_record_id' => $member->id,
            'expected_updated_at' => $member->updated_at->format('Y-m-d\TH:i:s.uP'),
            ...($operation === 'update' ? ['first_name' => 'Changed'] : ['confirm_change' => '1'])];
        if (in_array($operation, ['assign', 'replace', 'remove'], true)) {
            $data = ['action' => $operation, 'member_id' => $member->id, 'position_id' => 2,
                'expected_position_id' => $member->position_id, 'expected_updated_at' => $data['expected_updated_at'],
                'confirm_change' => '1'];
        }
        if ($operation === 'replace') {
            $former = new Member;
            $former->forceFill($attributes + ['id' => self::FORMER, 'position_id' => 2])->save();
            $former->refresh();
            $data += ['former_member_id' => $former->id, 'expected_former_position_id' => 2,
                'expected_former_updated_at' => $former->updated_at->format('Y-m-d\TH:i:s.uP')];
        }

        return ['application' => $application->refresh(), 'member' => $member,
            'association' => Association::findOrFail(self::ASSOCIATION), 'data' => $data];
    }

    public function sec2Mutate(string $operation, UserProfile $actor, array $context): void
    {
        if (in_array($operation, ['assign', 'replace', 'remove'], true)) {
            $request = Request::create('/synthetic/officers', 'PATCH');
            $request->attributes->set('current_user', $actor);
            app(AssociationOfficerService::class)->change($request, $context['association'], $context['data']);
        } elseif (in_array($operation, ['approve', 'reject'], true)) {
            $service = app(MemberApplicationService::class);
            $operation === 'approve' ? $service->approve($context['application'], $actor)
                : $service->reject($context['application'], $actor, 'Synthetic reason');
        } elseif ($operation === 'submit') {
            app(MemberApplicationService::class)->submit(['first_name' => 'Synthetic', 'last_name' => 'Submitted',
                'birth_date' => '2000-01-01', 'sex_id' => 1, 'position_id' => 1, 'classification_ids' => [1],
                'endorsement_letter' => 'Synthetic letter'], $actor);
        } else {
            app(MemberService::class)->{$operation}($context['member'], $context['data'], $actor);
        }
    }

    public function sec2State(): array
    {
        $state = [];
        foreach (['members' => ['id'], 'member_applications' => ['id'], 'audit_logs' => ['id'],
            'member_classification_assignments' => ['member_id', 'classification_id'],
            'member_application_classifications' => ['member_application_id', 'classification_id']] as $table => $keys) {
            $query = DB::table($table);
            foreach ($keys as $key) {
                $query->orderBy($key);
            }
            $state[$table] = $query->get()->map(fn ($row) => (array) $row)->all();
        }

        return $state;
    }

    public function sec2Change(string $role, string $state): void
    {
        $actorId = match ($role) {
            'admin' => self::ADMIN, 'officer' => self::OFFICER, 'account' => self::ACCOUNT
        };
        match ($state) {
            'revoked' => DB::table('user_profiles')->where('id', $actorId)->update(['is_active' => false]),
            'inactive_role' => DB::table('roles')->where('code', match ($role) {
                'admin' => 'system_admin', 'officer' => 'field_officer', 'account' => 'association_account',
            })->update(['is_active' => false]),
            'changed_role' => DB::table('user_profiles')->where('id', $actorId)->update(['role_id' => $role === 'admin' ? 2 : 1]),
            'cutoff' => DB::table('user_profiles')->where('id', $actorId)->update(['auth_sessions_valid_after' => '2026-03-15 00:00:00']),
            'email_hold' => DB::table('user_profiles')->where('id', $actorId)->update(['email_change_hold_id' => self::APPLICATION]),
            'invitation_hold' => DB::table('user_invitations')->insert(['id' => self::APPLICATION,
                'auth_user_id' => $actorId, 'email' => 'synthetic@example.invalid', 'status' => 'pending',
                'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']),
            'reassigned' => DB::table('associations')->where('id', self::ASSOCIATION)->update(['primary_field_officer_id' => self::OTHER_OFFICER]),
            'archived' => DB::table('associations')->where('id', self::ASSOCIATION)->update(['archived_at' => '2026-03-15 00:00:00']),
            'inactive' => DB::table('associations')->where('id', self::ASSOCIATION)->update(['status' => 'inactive']),
            'unlinked' => DB::table('association_accounts')->where('id', self::LINK)->update(['is_active' => false]),
        };
    }
}
