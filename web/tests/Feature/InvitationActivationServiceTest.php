<?php

namespace Tests\Feature;

use App\Exceptions\InvitationActivationException;
use App\Models\AssociationAccount;
use App\Models\UserInvitation;
use App\Models\UserProfile;
use App\Services\InvitationActivationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class InvitationActivationServiceTest extends TestCase
{
    private InvitationActivationService $activation;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // Every fixture lives on a dedicated, disposable SQLite connection.
        config(['database.connections.activation_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('activation_test');
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        $this->activation = app(InvitationActivationService::class);

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->boolean('is_active');
            $table->timestamps();
        });
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('role_id');
            $table->string('display_name');
            $table->boolean('is_active');
            $table->timestamp('activated_at')->nullable();
            $table->uuid('invited_by')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamps();
        });
        Schema::create('associations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('status');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::create('association_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('association_id')->unique();
            $table->uuid('user_id')->unique();
            $table->boolean('is_active');
            $table->uuid('linked_by')->nullable();
            $table->timestamp('linked_at');
            $table->timestamps();
        });
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email');
            $table->string('display_name');
            $table->unsignedBigInteger('role_id');
            $table->uuid('association_id')->nullable();
            $table->uuid('invited_by')->nullable();
            $table->uuid('auth_user_id')->nullable();
            $table->string('status');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        UserInvitation::flushEventListeners();
        AssociationAccount::flushEventListeners();
        DB::purge('activation_test');
        parent::tearDown();
    }

    public function test_field_officer_activation_preserves_attribution_and_creates_no_link(): void
    {
        $context = $this->fixture('field_officer');
        $this->activation->claimPassword($context, 'test_claim');
        $context['phase'] = 'password_confirmed';
        $this->activation->finish($context, 'test_claim');

        $profile = UserProfile::findOrFail($context['auth_user_id']);
        $invitation = UserInvitation::findOrFail($context['invitation_id']);
        $this->assertTrue($profile->is_active);
        $this->assertNotNull($profile->activated_at);
        $this->assertTrue($profile->canAccessApplication(['id' => $profile->id, 'email' => $context['email']]));
        $this->assertSame('accepted', $invitation->status);
        $this->assertNotNull($invitation->accepted_at);
        $this->assertNull($invitation->last_error_code);
        $this->assertSame($invitation->invited_by, $profile->invited_by);
        $this->assertSame(0, AssociationAccount::count());
        $this->activation->finish($context, 'test_claim');
        $this->assertSame(1, UserInvitation::count());
    }

    public function test_association_profile_is_active_before_link_creation_and_self_is_link_actor(): void
    {
        $context = $this->fixture('association_account');
        AssociationAccount::creating(function (AssociationAccount $link) {
            $this->assertTrue(UserProfile::findOrFail($link->user_id)->is_active);
        });
        $this->activation->claimPassword($context, 'test_claim');
        $context['phase'] = 'password_confirmed';
        $this->activation->finish($context, 'test_claim');

        $link = AssociationAccount::sole();
        $this->assertSame($context['association_id'], $link->association_id);
        $this->assertSame($context['auth_user_id'], $link->user_id);
        $this->assertSame($link->user_id, $link->linked_by);
        $this->assertNotNull($link->linked_at);
        $this->assertTrue($link->is_active);
    }

    public function test_database_failure_rolls_back_all_activation_changes_and_confirmed_context_can_recover(): void
    {
        $context = $this->fixture('association_account');
        $this->activation->claimPassword($context, 'test_claim');
        $context['phase'] = 'password_confirmed';
        $failOnce = true;
        UserInvitation::saving(function (UserInvitation $invitation) use (&$failOnce) {
            if ($invitation->status === 'accepted' && $failOnce) {
                $failOnce = false;
                throw new RuntimeException('Simulated activation storage failure.');
            }
        });
        try {
            $this->activation->finish($context, 'test_claim');
            $this->fail('The simulated failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated activation storage failure.', $exception->getMessage());
        }

        $this->assertFalse(UserProfile::findOrFail($context['auth_user_id'])->is_active);
        $this->assertNull(UserProfile::findOrFail($context['auth_user_id'])->activated_at);
        $this->assertSame(0, AssociationAccount::count());
        $this->assertSame('sent', UserInvitation::findOrFail($context['invitation_id'])->status);
        $this->assertSame('test_claim', UserInvitation::findOrFail($context['invitation_id'])->last_error_code);

        $this->activation->eligible($context, 'test_claim');
        $this->activation->finish($context, 'test_claim');
        $this->assertSame('accepted', UserInvitation::findOrFail($context['invitation_id'])->status);
        $this->assertSame(1, AssociationAccount::count());
        Http::assertNothingSent();
    }

    public function test_activation_requires_a_confirmed_password_receipt(): void
    {
        $context = $this->fixture('field_officer');
        $this->expectException(InvitationActivationException::class);
        $this->activation->finish($context, 'test_claim');
    }

    public function test_an_in_flight_write_blocks_another_password_claim(): void
    {
        $context = $this->fixture('field_officer');
        $this->activation->claimPassword($context, 'first_claim');
        $this->expectException(InvitationActivationException::class);
        $this->activation->claimPassword($context, 'second_claim');
    }

    public function test_confirmed_receipt_cannot_resolve_another_contexts_write_marker(): void
    {
        $context = $this->fixture('field_officer');
        $this->activation->claimPassword($context, 'first_claim');
        $context['phase'] = 'password_confirmed';
        $this->expectException(InvitationActivationException::class);
        $this->activation->finish($context, 'different_claim');
    }

    #[DataProvider('ineligibleScopes')]
    public function test_eligibility_changes_stop_activation(string $scenario): void
    {
        $context = $this->fixture('association_account');
        switch ($scenario) {
            case 'revoked': case 'expired': case 'accepted': case 'failed': case 'pending':
                $status = $scenario === 'revoked' ? 'cancelled' : $scenario;
                DB::table('user_invitations')->update(['status' => $status]);
                break;
            case 'unbound':
                DB::table('user_invitations')->update(['auth_user_id' => null]);
                break;
            case 'wrong_uuid':
                DB::table('user_invitations')->update(['auth_user_id' => (string) Str::uuid()]);
                break;
            case 'wrong_email':
                DB::table('user_invitations')->update(['email' => 'someone-else@example.test']);
                break;
            case 'wrong_profile_role':
                DB::table('user_profiles')->where('id', $context['auth_user_id'])->update(['role_id' => 2]);
                break;
            case 'wrong_invitation_role':
                DB::table('user_invitations')->update(['role_id' => 2]);
                break;
            case 'profile_active':
                DB::table('user_profiles')->where('id', $context['auth_user_id'])->update(['is_active' => true]);
                break;
            case 'profile_activated':
                DB::table('user_profiles')->where('id', $context['auth_user_id'])->update(['activated_at' => now()]);
                break;
            case 'role_inactive':
                DB::table('roles')->where('id', 1)->update(['is_active' => false]);
                break;
            case 'role_admin':
                DB::table('roles')->where('id', 1)->update(['code' => 'system_admin']);
                break;
            case 'reconciliation':
                DB::table('user_invitations')->update(['last_error_code' => 'profile_sync_failed']);
                break;
            case 'association_inactive':
                DB::table('associations')->update(['status' => 'inactive']);
                break;
            case 'association_archived':
                DB::table('associations')->update(['archived_at' => now()]);
                break;
            case 'association_missing':
                DB::table('associations')->delete();
                break;
            case 'association_changed':
                DB::table('user_invitations')->update(['association_id' => (string) Str::uuid()]);
                break;
            case 'association_link': case 'user_link':
                DB::table('association_accounts')->insert([
                    'id' => (string) Str::uuid(), 'association_id' => $scenario === 'association_link' ? $context['association_id'] : (string) Str::uuid(),
                    'user_id' => $scenario === 'user_link' ? $context['auth_user_id'] : (string) Str::uuid(),
                    'is_active' => false, 'linked_at' => now(),
                ]);
                break;
            case 'conflicting_invitation':
                $other = (array) DB::table('user_invitations')->first();
                $other['id'] = (string) Str::uuid();
                $other['email'] = 'other@example.test';
                $other['status'] = 'failed';
                DB::table('user_invitations')->insert($other);
                break;
        }
        $this->expectException(InvitationActivationException::class);
        $this->activation->eligible($context);
    }

    public static function ineligibleScopes(): array
    {
        return array_map(fn ($scenario) => [$scenario], [
            'revoked', 'expired', 'accepted', 'failed', 'pending', 'unbound', 'wrong_uuid', 'wrong_email',
            'wrong_profile_role', 'wrong_invitation_role', 'profile_active', 'profile_activated', 'role_inactive',
            'role_admin', 'reconciliation', 'association_inactive', 'association_archived', 'association_missing',
            'association_changed', 'association_link', 'user_link', 'conflicting_invitation',
        ]);
    }

    public function test_expired_context_cannot_finish_even_with_a_confirmed_receipt(): void
    {
        $context = $this->fixture('field_officer');
        $this->activation->claimPassword($context, 'test_claim');
        $context['phase'] = 'password_confirmed';
        $context['expires_at'] = time() - 1;
        $this->expectException(InvitationActivationException::class);
        $this->activation->finish($context, 'test_claim');
    }

    #[DataProvider('existingRoles')]
    public function test_existing_active_accounts_without_invitations_keep_application_access(string $role): void
    {
        $context = $this->fixture($role);
        DB::table('user_invitations')->delete();
        DB::table('user_profiles')->where('id', $context['auth_user_id'])->update(['is_active' => true]);
        $profile = UserProfile::findOrFail($context['auth_user_id']);
        $this->assertNull($profile->activated_at);
        $this->assertTrue($profile->canAccessApplication(['id' => $profile->id, 'email' => $context['email']]));
    }

    public static function existingRoles(): array
    {
        return [['system_admin'], ['field_officer'], ['association_account']];
    }

    private function fixture(string $role): array
    {
        $userId = (string) Str::uuid();
        $invitationId = (string) Str::uuid();
        $associationId = $role === 'association_account' ? (string) Str::uuid() : null;
        $inviterId = (string) Str::uuid();
        $now = now();
        DB::table('roles')->insert([
            ['id' => 1, 'code' => $role, 'is_active' => true],
            ['id' => 2, 'code' => 'system_admin', 'is_active' => true],
        ]);
        DB::table('user_profiles')->insert([
            'id' => $userId, 'role_id' => 1, 'display_name' => 'Invited Account', 'is_active' => false,
            'invited_by' => $inviterId, 'invited_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        if ($associationId !== null) DB::table('associations')->insert(['id' => $associationId, 'status' => 'active']);
        DB::table('user_invitations')->insert([
            'id' => $invitationId, 'auth_user_id' => $userId, 'role_id' => 1, 'display_name' => 'Invited Account',
            'email' => 'invited@example.test', 'association_id' => $associationId, 'status' => 'sent',
            'invited_by' => $inviterId, 'sent_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return [
            'invitation_id' => $invitationId, 'auth_user_id' => $userId, 'email' => 'invited@example.test',
            'role_id' => '1', 'association_id' => $associationId, 'phase' => 'verified', 'expires_at' => time() + 600,
        ];
    }
}
