<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\UserProfile;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserProfileRoleRetrievalTest extends TestCase
{
    private const USER = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const MISSING = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('code'); $table->string('name'); $table->text('description')->nullable();
            $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->integer('role_id'); $table->string('display_name');
            $table->string('contact_number')->nullable(); $table->boolean('is_active');
            $table->uuid('invited_by')->nullable(); $table->timestamp('invited_at')->nullable();
            $table->timestamp('activated_at')->nullable(); $table->timestamps();
        });
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('auth_user_id')->nullable(); $table->string('email');
            $table->string('status'); $table->string('last_error_code')->nullable();
            $table->timestamp('accepted_at')->nullable(); $table->timestamp('last_attempt_at')->nullable(); $table->timestamps();
        });
        foreach ([7 => 'system_admin', 8 => 'field_officer', 9 => 'association_account'] as $id => $code) {
            DB::table('roles')->insert(['id' => $id, 'code' => $code, 'name' => 'Synthetic '.$code,
                'description' => 'Synthetic role description', 'is_active' => true,
                'created_at' => '2021-01-01 00:00:00', 'updated_at' => '2022-01-01 00:00:00']);
        }
        DB::table('user_profiles')->insert(['id' => self::USER, 'role_id' => 7,
            'display_name' => 'Synthetic profile', 'contact_number' => 'synthetic-contact', 'is_active' => true,
            'invited_by' => self::MISSING, 'invited_at' => '2020-01-01 00:00:00',
            'activated_at' => '2020-02-01 00:00:00', 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-02-01 00:00:00']);
    }

    #[DataProvider('retrievalModes')]
    public function test_one_query_preserves_the_profile_and_loaded_role_models(int $roleId, bool $write): void
    {
        DB::table('user_profiles')->where('id', self::USER)->update(['role_id' => $roleId]);
        $expected = UserProfile::with('role')->findOrFail(self::USER);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $actual = UserProfile::findWithRole(self::USER, useWritePdo: $write);
            $this->assertCount(1, DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertInstanceOf(UserProfile::class, $actual);
        $this->assertTrue($actual->relationLoaded('role'));
        $this->assertInstanceOf(Role::class, $actual->role);
        $this->assertSame($expected->getAttributes(), $actual->getAttributes());
        $this->assertSame($expected->role->getAttributes(), $actual->role->getAttributes());
        $this->assertSame($expected->toArray(), $actual->toArray());
        $this->assertSame($expected->getConnectionName(), $actual->getConnectionName());
        $this->assertSame($expected->role->getConnectionName(), $actual->role->getConnectionName());
        $this->assertSame(self::USER, $actual->id);
        $this->assertSame($roleId, $actual->role->id);
        $this->assertSame($actual->role_id, $actual->role->id);
        $this->assertTrue($actual->is_active);
        $this->assertTrue($actual->role->is_active);
        $this->assertInstanceOf(Carbon::class, $actual->activated_at);
        $this->assertInstanceOf(Carbon::class, $actual->role->created_at);
        $this->assertTrue($actual->exists);
        $this->assertTrue($actual->role->exists);
        $this->assertFalse($actual->isDirty());
        $this->assertFalse($actual->role->isDirty());
        $this->assertSame($actual->getAttributes(), $actual->getRawOriginal());
        $this->assertSame($actual->role->getAttributes(), $actual->role->getRawOriginal());
    }

    public static function retrievalModes(): array
    {
        return [[7, false], [7, true], [8, false], [8, true], [9, false], [9, true]];
    }

    public function test_missing_profile_and_missing_role_preserve_null_results(): void
    {
        $this->assertNull(UserProfile::findWithRole(self::MISSING));
        DB::table('user_profiles')->where('id', self::USER)->update(['role_id' => 999]);
        $profile = UserProfile::findWithRole(self::USER);
        $this->assertInstanceOf(UserProfile::class, $profile);
        $this->assertSame(self::USER, $profile->id);
        $this->assertSame(999, $profile->role_id);
        $this->assertTrue($profile->relationLoaded('role'));
        $this->assertNull($profile->role);
        $this->assertFalse($profile->canAccessApplication());
    }

    public function test_each_retrieval_reads_new_profile_and_role_state_without_column_collisions(): void
    {
        $initial = UserProfile::findWithRole(self::USER, useWritePdo: true);
        DB::table('roles')->where('id', 7)->update(['is_active' => false]);
        $roleRevoked = UserProfile::findWithRole(self::USER, useWritePdo: true);
        $this->assertNotSame($initial, $roleRevoked);
        $this->assertTrue($roleRevoked->is_active);
        $this->assertFalse($roleRevoked->role->is_active);
        $this->assertFalse($roleRevoked->canAccessApplication());
        DB::table('roles')->where('id', 7)->update(['is_active' => true]);
        DB::table('user_profiles')->where('id', self::USER)->update(['is_active' => false]);
        $profileRevoked = UserProfile::findWithRole(self::USER, useWritePdo: true);
        $this->assertFalse($profileRevoked->is_active);
        $this->assertTrue($profileRevoked->role->is_active);
        $this->assertFalse($profileRevoked->canAccessApplication());
        $this->assertTrue($initial->is_active);
        $this->assertTrue($initial->role->is_active);
    }
}
