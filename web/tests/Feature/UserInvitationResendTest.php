<?php

namespace Tests\Feature;

use App\Models\UserInvitation;
use App\Models\UserProfile;
use App\Services\UserInvitationResendService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class UserInvitationResendTest extends TestCase
{
    private const ADMIN = '11111111-1111-4111-8111-111111111111';
    private const USER = '22222222-2222-4222-8222-222222222222';
    private const INVITATION = '33333333-3333-4333-8333-333333333333';
    private const ASSOCIATION = '44444444-4444-4444-8444-444444444444';
    private const OTHER = '55555555-5555-4555-8555-555555555555';
    private int $writes = 0;
    private bool $confirmed = false;
    private bool $timeout = false;
    private bool $differentId = false;
    private bool $revokeDuringWrite = false;
    private bool $rateLimited = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.supabase.url' => 'https://auth.example.test',
            'services.supabase.service_role_key' => 'synthetic.server.signature',
            'services.supabase.invitation_redirect_url' => 'https://app.example.test/invitation']);
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('code'); $table->boolean('is_active'); $table->timestamps();
            $table->string('name')->nullable(); $table->text('description')->nullable();
        });
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->unsignedBigInteger('role_id'); $table->string('display_name');
            $table->boolean('is_active'); $table->timestamp('activated_at')->nullable(); $table->timestamps();
        });
        Schema::create('associations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->string('status'); $table->timestamp('archived_at')->nullable();
        });
        Schema::create('association_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('user_id'); $table->uuid('association_id');
            $table->boolean('is_active')->default(true);
        });
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('auth_user_id'); $table->string('email');
            $table->string('display_name'); $table->unsignedBigInteger('role_id'); $table->uuid('association_id')->nullable();
            $table->string('status'); $table->integer('attempt_count'); $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable(); $table->timestamp('accepted_at')->nullable();
            $table->string('last_error_code')->nullable(); $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('actor_user_id'); $table->string('action_type');
            $table->string('module'); $table->string('entity_type'); $table->string('entity_id');
            $table->text('old_values')->nullable(); $table->text('new_values')->nullable(); $table->timestamp('performed_at');
        });
        foreach ([1 => 'system_admin', 2 => 'field_officer', 3 => 'association_account'] as $id => $code) {
            DB::table('roles')->insert(['id' => $id, 'code' => $code, 'is_active' => true]);
        }
        DB::table('user_profiles')->insert([
            ['id' => self::ADMIN, 'role_id' => 1, 'display_name' => 'Synthetic Admin', 'is_active' => true],
            ['id' => self::USER, 'role_id' => 2, 'display_name' => 'Synthetic Recipient', 'is_active' => false],
        ]);
        DB::table('user_invitations')->insert(['id' => self::INVITATION, 'auth_user_id' => self::USER,
            'email' => 'recipient@example.test', 'display_name' => 'Synthetic Recipient', 'role_id' => 2,
            'status' => 'sent', 'attempt_count' => 1, 'last_attempt_at' => now()->subMinutes(2),
            'sent_at' => now()->subMinutes(2), 'created_at' => now()->subMinutes(2)]);
        Http::fake(function (ProviderRequest $request) {
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/admin/users/'.self::USER)) {
                return Http::response(['id' => self::USER, 'email' => 'recipient@example.test',
                    'invited_at' => '2026-10-01T00:00:00Z', 'email_confirmed_at' => $this->confirmed ? '2026-10-01T01:00:00Z' : null]);
            }
            $this->assertSame('POST', $request->method());
            $this->assertStringContainsString('/auth/v1/invite?', $request->url());
            $this->writes++;
            if ($this->revokeDuringWrite) {
                DB::table('user_invitations')->where('id', self::INVITATION)->update(['status' => 'cancelled']);
            }
            if ($this->timeout) throw new ConnectionException('Synthetic timeout');
            if ($this->rateLimited) return Http::response(['code' => 'over_email_send_rate_limit'], 429);
            return Http::response(['id' => $this->differentId ? self::OTHER : self::USER,
                'email' => 'recipient@example.test', 'created_at' => '2026-10-01T00:00:00Z']);
        });
    }

    private function resend(): array
    {
        $request = Request::create('/admin/users/'.self::USER.'/resend-invitation', 'POST');
        $request->attributes->set('current_user', UserProfile::findOrFail(self::ADMIN));

        return app(UserInvitationResendService::class)->resend($request, UserProfile::findOrFail(self::USER));
    }

    #[DataProvider('invitedRoles')]
    public function test_resend_preserves_the_identity_profile_and_role_relationships(int $role): void
    {
        if ($role === 3) {
            DB::table('associations')->insert(['id' => self::ASSOCIATION, 'status' => 'active']);
            DB::table('user_profiles')->where('id', self::USER)->update(['role_id' => 3]);
            DB::table('user_invitations')->where('id', self::INVITATION)->update(['role_id' => 3, 'association_id' => self::ASSOCIATION]);
            DB::table('association_accounts')->insert(['id' => self::INVITATION, 'user_id' => self::ADMIN,
                'association_id' => self::ASSOCIATION, 'is_active' => false]);
        }
        $before = (array) DB::table('user_profiles')->where('id', self::USER)->first();
        $linksBefore = DB::table('association_accounts')->get()->toJson();
        $result = $this->resend();
        $this->assertSame('provider_accepted', $result['code']);
        $this->assertSame(202, $result['http_status']);
        $this->assertSame(1, $this->writes);
        $this->assertSame(2, DB::table('user_profiles')->count());
        $this->assertSame($before, (array) DB::table('user_profiles')->where('id', self::USER)->first());
        $this->assertSame(self::USER, UserInvitation::findOrFail(self::INVITATION)->auth_user_id);
        $this->assertSame('sent', UserInvitation::findOrFail(self::INVITATION)->status);
        $this->assertSame($linksBefore, DB::table('association_accounts')->get()->toJson());
        $this->assertSame(0, DB::table('association_accounts')->where('is_active', true)->count());
        $this->assertSame(2, DB::table('audit_logs')->where('action_type', 'RESEND INVITATION')->count());
    }

    public static function invitedRoles(): array
    {
        return [[2], [3]];
    }

    public function test_confirmed_provider_identity_is_not_sent_another_invitation(): void
    {
        $this->confirmed = true;
        $this->assertSame('identity_already_confirmed', $this->resend()['code']);
        $this->assertSame(0, $this->writes);
        $this->assertSame('sent', UserInvitation::findOrFail(self::INVITATION)->status);
    }

    public function test_unknown_provider_outcome_stops_retries_without_rebinding(): void
    {
        $this->timeout = true;
        $this->assertSame('provider_outcome_unknown', $this->resend()['code']);
        $invitation = UserInvitation::findOrFail(self::INVITATION);
        $this->assertSame('processing', $invitation->status);
        $this->assertSame('resend_outcome_unknown', $invitation->last_error_code);
        $this->expectException(ValidationException::class);
        try { $this->resend(); } finally { $this->assertSame(1, $this->writes); }
    }

    public function test_unexpected_provider_uuid_is_never_bound_to_the_existing_profile(): void
    {
        $this->differentId = true;
        $this->assertSame('identity_mismatch', $this->resend()['code']);
        $this->assertSame(self::USER, UserInvitation::findOrFail(self::INVITATION)->auth_user_id);
        $this->assertNull(UserProfile::find(self::OTHER));
        $this->assertSame(self::OTHER, UserInvitation::findOrFail(self::INVITATION)->reconciliationAuthUserId());
    }

    public function test_rate_limited_resend_retains_the_existing_invitation_and_enforces_cooldown(): void
    {
        $this->rateLimited = true;
        $this->assertSame('provider_rate_limited', $this->resend()['code']);
        $invitation = UserInvitation::findOrFail(self::INVITATION);
        $this->assertSame('sent', $invitation->status);
        $this->assertNull($invitation->last_error_code);
        $this->expectException(ValidationException::class);
        try { $this->resend(); } finally { $this->assertSame(1, $this->writes); }
    }

    #[DataProvider('ineligibleStates')]
    public function test_ineligible_accounts_never_trigger_provider_requests(string $state): void
    {
        if ($state === 'activated') DB::table('user_profiles')->where('id', self::USER)->update(['activated_at' => now()]);
        if ($state === 'active') DB::table('user_profiles')->where('id', self::USER)->update(['is_active' => true]);
        if ($state === 'revoked') DB::table('user_invitations')->where('id', self::INVITATION)->update(['status' => 'cancelled']);
        if ($state === 'claimed') DB::table('user_invitations')->where('id', self::INVITATION)->update(['last_error_code' => 'activation_password_started:synthetic']);
        if ($state === 'cooldown') DB::table('user_invitations')->where('id', self::INVITATION)->update(['last_attempt_at' => now()]);
        $this->expectException(ValidationException::class);
        try { $this->resend(); } finally { Http::assertNothingSent(); }
    }

    public static function ineligibleStates(): array
    {
        return [['activated'], ['active'], ['revoked'], ['claimed'], ['cooldown']];
    }

    public function test_non_administrator_is_denied_before_provider_contact(): void
    {
        DB::table('user_profiles')->where('id', self::ADMIN)->update(['role_id' => 2]);
        try {
            $this->resend();
            $this->fail('Expected authorization denial');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        Http::assertNothingSent();
    }

    public function test_revocation_during_the_provider_request_is_never_overwritten(): void
    {
        $this->revokeDuringWrite = true;
        $this->assertSame('reconciliation_required', $this->resend()['code']);
        $this->assertSame('cancelled', UserInvitation::findOrFail(self::INVITATION)->status);
        $this->assertFalse(UserProfile::findOrFail(self::USER)->is_active);
    }
}
