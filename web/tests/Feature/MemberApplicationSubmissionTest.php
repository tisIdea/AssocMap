<?php

namespace Tests\Feature;

use App\Models\MemberApplication;
use App\Models\UserProfile;
use App\Services\Auth\SupabaseAuthService;
use App\Services\Members\MemberApplicationPhotoService;
use App\Services\Members\MemberApplicationService;
use App\Services\SupabaseStorageService;
use App\Exceptions\MemberApplicationPhotoUnavailableException;
use App\Exceptions\PrivateMediaStorageException;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\IsolatedDatabaseManager;
use Tests\TestCase;

class MemberApplicationSubmissionTest extends TestCase
{
    private const ACCOUNT = '11111111-1111-4111-8111-111111111111';
    private const ASSOCIATION = '22222222-2222-4222-8222-222222222222';
    private const LINK = '33333333-3333-4333-8333-333333333333';
    private const URL = '/association/member-applications';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertInstanceOf(IsolatedDatabaseManager::class, DB::getFacadeRoot());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        Http::fake([]);
        Http::preventStrayRequests();
        config(['services.supabase_storage.photo_transport' => 'local']);
        Storage::fake('member_application_photos');
        $this->withoutVite();
        $this->schema();
        $this->fixtures();
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->with('synthetic-session')->andReturn(['id' => self::ACCOUNT]);
        $this->app->instance(SupabaseAuthService::class, $auth);
        $this->withSession(['supabase_access_token' => 'synthetic-session']);
    }

    protected function tearDown(): void
    {
        MemberApplication::flushEventListeners();
        try {
            try {
                Http::assertNothingSent();
            } finally {
                parent::tearDown();
            }
        } finally {
            HandleExceptions::flushState($this);
        }
    }

    private function schema(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('code'); $table->boolean('is_active'); $table->timestamps();
            $table->string('name')->nullable(); $table->text('description')->nullable();
        });
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->unsignedBigInteger('role_id');
            $table->string('display_name'); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('auth_user_id')->nullable();
            $table->string('email')->nullable();
            $table->string('status'); $table->string('last_error_code')->nullable();
            $table->timestamp('accepted_at')->nullable(); $table->timestamp('last_attempt_at')->nullable(); $table->timestamps();
        });
        Schema::create('associations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->string('name'); $table->string('status');
            $table->timestamp('archived_at')->nullable(); $table->timestamps();
        });
        Schema::create('association_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('association_id');
            $table->uuid('user_id'); $table->boolean('is_active'); $table->timestamps();
            $table->foreign('association_id')->references('id')->on('associations');
            $table->foreign('user_id')->references('id')->on('user_profiles');
        });
        Schema::create('association_positions', function (Blueprint $table) {
            $table->id(); $table->string('code'); $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_officer'); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('members', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('association_id'); $table->unsignedBigInteger('position_id');
            $table->string('member_status'); $table->timestamp('archived_at')->nullable();
            $table->string('fishr_registration_number')->nullable();
            $table->foreign('association_id')->references('id')->on('associations');
            $table->foreign('position_id')->references('id')->on('association_positions');
        });
        Schema::create('sex_categories', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('member_classifications', function (Blueprint $table) {
            $table->id(); $table->string('code'); $table->string('name'); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('member_applications', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('association_id'); $table->uuid('submitted_by_user_id');
            $table->string('first_name', 120); $table->string('middle_name', 120)->nullable();
            $table->string('last_name', 120); $table->string('suffix', 20)->nullable();
            $table->date('birth_date'); $table->unsignedBigInteger('sex_id');
            $table->string('contact_number', 30)->nullable(); $table->string('fishr_registration_number', 100)->nullable();
            $table->unsignedBigInteger('position_id'); $table->string('position_other_text', 100)->nullable();
            $table->text('endorsement_letter')->nullable(); $table->string('applicant_photo_path', 255)->nullable();
            $table->string('status'); $table->uuid('reviewed_by')->nullable(); $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable(); $table->timestamps();
            $table->foreign('association_id')->references('id')->on('associations');
            $table->foreign('submitted_by_user_id')->references('id')->on('user_profiles');
            $table->foreign('position_id')->references('id')->on('association_positions');
            $table->foreign('sex_id')->references('id')->on('sex_categories');
        });
        Schema::create('member_application_classifications', function (Blueprint $table) {
            $table->uuid('member_application_id'); $table->unsignedBigInteger('classification_id');
            $table->primary(['member_application_id', 'classification_id']);
            $table->foreign('member_application_id')->references('id')->on('member_applications');
            $table->foreign('classification_id')->references('id')->on('member_classifications');
        });
    }

    private function fixtures(): void
    {
        DB::table('roles')->insert(['id' => 1, 'code' => 'association_account', 'is_active' => true]);
        DB::table('user_profiles')->insert(['id' => self::ACCOUNT, 'role_id' => 1,
            'display_name' => 'Synthetic Account', 'is_active' => true]);
        DB::table('associations')->insert(['id' => self::ASSOCIATION, 'name' => 'Synthetic Association', 'status' => 'active']);
        DB::table('association_accounts')->insert(['id' => self::LINK, 'association_id' => self::ASSOCIATION,
            'user_id' => self::ACCOUNT, 'is_active' => true]);
        DB::table('association_positions')->insert(['id' => 2, 'code' => 'PRESIDENT', 'name' => 'President',
            'is_officer' => true, 'is_active' => true]);
        DB::table('sex_categories')->insert(['id' => 1, 'name' => 'Synthetic category', 'is_active' => true]);
        DB::table('member_classifications')->insert(['id' => 1, 'code' => 'FISHERFOLK', 'name' => 'Fisherfolk', 'is_active' => true]);
    }

    private function applicationData(): array
    {
        return ['first_name' => 'Synthetic', 'last_name' => 'Applicant', 'birth_date' => '2000-01-01',
            'sex_id' => 1, 'position_id' => 2, 'classification_ids' => [1], 'endorsement_letter' => 'Private synthetic letter'];
    }

    public static function malformedTextFields(): iterable
    {
        foreach (['first_name', 'middle_name', 'last_name', 'suffix', 'contact_number',
            'fishr_registration_number', 'position_other_text', 'endorsement_letter'] as $field) {
            foreach (['array' => ['unexpected'], 'empty array' => [], 'integer' => 42, 'false' => false] as $kind => $value) {
                yield $field.' '.$kind => [$field, $value];
            }
        }
    }

    #[DataProvider('malformedTextFields')]
    public function test_malformed_text_returns_validation_without_persisting_an_application(string $field, mixed $value): void
    {
        $this->postJson(self::URL, array_replace($this->applicationData(), [$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('member_applications', 0);
        $this->assertDatabaseCount('member_application_classifications', 0);
        $this->assertDatabaseCount('members', 0);
        $this->assertSame([], Storage::disk('member_application_photos')->allFiles());
    }

    public function test_valid_application_text_is_trimmed_and_optional_blanks_remain_nullable(): void
    {
        $this->postJson(self::URL, array_replace($this->applicationData(), [
            'first_name' => '  Synthetic  ', 'last_name' => '  Applicant  ', 'middle_name' => '  ',
            'suffix' => null, 'contact_number' => '  09123456789  ', 'fishr_registration_number' => '  REG-1  ',
            'endorsement_letter' => '  Private synthetic letter  ',
        ]))->assertRedirect();
        $application = MemberApplication::sole();
        $this->assertSame('Synthetic', $application->first_name);
        $this->assertSame('Applicant', $application->last_name);
        $this->assertNull($application->middle_name);
        $this->assertNull($application->suffix);
        $this->assertSame('09123456789', $application->contact_number);
        $this->assertSame('REG-1', $application->fishr_registration_number);
        $this->assertSame('Private synthetic letter', $application->endorsement_letter);
    }

    private function usePhotoTransport(string $transport): LocalFilesystemAdapter
    {
        config(['services.supabase_storage.photo_transport' => $transport]);

        return Storage::fake($transport === 'r2' ? 'media_private' : 'member_application_photos');
    }

    public static function photoTransports(): array
    {
        return ['local' => ['local'], 'R2' => ['r2']];
    }

    public static function readOnlyAssociations(): array
    {
        return ['inactive' => ['inactive', null], 'archived' => ['active', '2026-01-01'],
            'inactive and archived' => ['inactive', '2026-01-01']];
    }

    #[DataProvider('readOnlyAssociations')]
    public function test_read_only_association_rejects_submission_before_storing_photo(string $status, ?string $archivedAt): void
    {
        DB::table('associations')->where('id', self::ASSOCIATION)
            ->update(['status' => $status, 'archived_at' => $archivedAt]);
        $this->postJson(self::URL, $this->applicationData() + [
            'applicant_photo' => UploadedFile::fake()->image('portrait.png', 32, 48),
        ])->assertUnprocessable()->assertJsonValidationErrors('association_id');
        $this->assertDatabaseCount('member_applications', 0);
        $this->assertDatabaseCount('member_application_classifications', 0);
        $this->assertSame([], Storage::disk('member_application_photos')->allFiles());
    }

    public static function omittedPhotos(): array
    {
        return ['local omitted' => [[], 'local'], 'local null' => [['applicant_photo' => null], 'local'],
            'R2 omitted' => [[], 'r2'], 'R2 null' => [['applicant_photo' => null], 'r2']];
    }

    #[DataProvider('omittedPhotos')]
    public function test_submission_without_photo_stores_null_and_preserves_application_data(array $photo, string $transport): void
    {
        $this->usePhotoTransport($transport);
        Storage::shouldReceive('disk')->never();
        $this->from(self::URL)->post(self::URL, array_merge($this->applicationData(), $photo))
            ->assertRedirect(self::URL)->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Member application submitted successfully.');
        $application = MemberApplication::sole();
        $this->assertNull($application->applicant_photo_path);
        $this->assertSame(self::ASSOCIATION, $application->association_id);
        $this->assertSame(self::ACCOUNT, $application->submitted_by_user_id);
        $this->assertSame('pending', $application->status);
        $this->assertSame(2, $application->position_id);
        $this->assertSame('Private synthetic letter', $application->endorsement_letter);
        $this->assertSame([1], $application->classifications()->pluck('member_classifications.id')->all());
    }

    public static function validPhotoExtensions(): array
    {
        return ['local JPEG' => ['jpg', 'local'], 'local PNG' => ['png', 'local'],
            'R2 JPEG' => ['jpg', 'r2'], 'R2 PNG' => ['png', 'r2']];
    }

    #[DataProvider('validPhotoExtensions')]
    public function test_submission_with_valid_photo_preserves_private_upload(string $extension, string $transport): void
    {
        $disk = $this->usePhotoTransport($transport);
        $photo = UploadedFile::fake()->image('portrait.'.$extension, 32, 48);
        $this->from(self::URL)->post(self::URL, array_merge($this->applicationData(), ['applicant_photo' => $photo]))
            ->assertRedirect(self::URL)->assertSessionHasNoErrors()->assertSessionHas('success');
        $application = MemberApplication::sole();
        $this->assertMatchesRegularExpression('~\Aphotos/'.$application->id.'/[0-9a-f-]{36}\.'.$extension.'\z~',
            $application->applicant_photo_path);
        $disk->assertExists($application->applicant_photo_path);
        $image = getimagesizefromstring($disk->get($application->applicant_photo_path));
        $this->assertSame([32, 48], array_slice($image, 0, 2));
        $this->assertArrayNotHasKey('applicant_photo_path', $application->toArray());
    }

    public static function invalidPhotos(): array
    {
        return ['corrupt image' => ['corrupt'], 'oversized image' => ['size'],
            'excessive dimensions' => ['dimensions'], 'unsupported type' => ['type'], 'nonfile value' => ['nonfile']];
    }

    #[DataProvider('invalidPhotos')]
    public function test_optional_photos_are_still_validated_when_supplied(string $kind): void
    {
        $photo = match ($kind) {
            'size' => UploadedFile::fake()->image('portrait.png', 32, 32)->size(2049),
            'dimensions' => UploadedFile::fake()->image('portrait.png', 2049, 1),
            'type' => UploadedFile::fake()->create('portrait.svg', 1, 'image/svg+xml'),
            'nonfile' => 'not a file',
            default => UploadedFile::fake()->createWithContent('portrait.png', 'not image content'),
        };
        $this->postJson(self::URL, array_merge($this->applicationData(), ['applicant_photo' => $photo]))
            ->assertUnprocessable()->assertJsonValidationErrors('applicant_photo');
        $this->assertDatabaseCount('member_applications', 0);
        $this->assertSame([], Storage::disk('member_application_photos')->allFiles());
    }

    public static function mandatoryFields(): array
    {
        return array_combine($fields = ['first_name', 'last_name', 'birth_date', 'sex_id', 'position_id', 'endorsement_letter'],
            array_map(fn ($field) => [$field], $fields));
    }

    #[DataProvider('mandatoryFields')]
    public function test_other_mandatory_fields_remain_required(string $field): void
    {
        $data = $this->applicationData();
        unset($data[$field]);
        $this->postJson(self::URL, $data)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('member_applications', 0);
    }

    public function test_other_position_still_requires_its_details_without_a_photo(): void
    {
        DB::table('association_positions')->insert(['id' => 7, 'code' => 'OTHER', 'name' => 'Other',
            'is_officer' => true, 'is_active' => true]);
        $this->postJson(self::URL, array_replace($this->applicationData(), ['position_id' => 7]))
            ->assertUnprocessable()->assertJsonValidationErrors('position_other_text');
        $this->assertDatabaseCount('member_applications', 0);
    }

    public function test_missing_photo_does_not_mask_database_rollback_with_a_cleanup_type_error(): void
    {
        MemberApplication::created(static function (): void {
            throw new RuntimeException('Synthetic transaction failure');
        });
        try {
            $this->app->make(MemberApplicationService::class)->submit($this->applicationData(),
                UserProfile::with('role')->findOrFail(self::ACCOUNT));
            $this->fail('The synthetic database failure was not propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic transaction failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('member_applications', 0);
        $this->assertDatabaseCount('member_application_classifications', 0);
        $this->assertSame([], Storage::disk('member_application_photos')->allFiles());
    }

    #[DataProvider('photoTransports')]
    public function test_confirmed_photo_is_discarded_only_after_the_application_transaction_rolls_back(string $transport): void
    {
        if (! extension_loaded('gd')) $this->markTestSkipped('PHP GD is required.');
        $disk = $this->usePhotoTransport($transport);
        $events = [];
        $photos = Mockery::mock(MemberApplicationPhotoService::class, [new SupabaseStorageService])->makePartial();
        $photos->shouldReceive('store')->once()->withArgs(function ($photo, $applicationId) use (&$events) {
            $events[] = 'store';
            $this->assertSame(1, DB::transactionLevel());

            return $photo instanceof UploadedFile && is_string($applicationId);
        })->passthru();
        $photos->shouldReceive('discard')->once()->withArgs(function ($path, $applicationId) use (&$events, $disk) {
            $events[] = 'discard';
            $this->assertSame(0, DB::transactionLevel());
            $disk->assertExists($path);

            return str_starts_with($path, 'photos/'.$applicationId.'/');
        })->passthru();
        $this->app->instance(MemberApplicationPhotoService::class, $photos);
        $failure = new RuntimeException('Synthetic application persistence failure');
        MemberApplication::created(function ($application) use (&$events, $failure, $disk) {
            $events[] = 'application saved';
            $this->assertSame(1, DB::transactionLevel());
            $disk->assertExists($application->applicant_photo_path);
            throw $failure;
        });
        try {
            $this->app->make(MemberApplicationService::class)->submit($this->applicationData() + [
                'applicant_photo' => UploadedFile::fake()->image('portrait.png', 8, 8)], UserProfile::with('role')->findOrFail(self::ACCOUNT));
            $this->fail('The synthetic transaction failure did not propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
        $this->assertSame(['store', 'application saved', 'discard'], $events);
        $this->assertDatabaseCount('member_applications', 0);
        $this->assertDatabaseCount('member_application_classifications', 0);
        $this->assertSame([], $disk->allFiles());
    }

    #[DataProvider('photoTransports')]
    public function test_failure_after_commit_retains_photo_and_database_history_for_reconciliation(string $transport): void
    {
        if (! extension_loaded('gd')) $this->markTestSkipped('PHP GD is required.');
        $disk = $this->usePhotoTransport($transport);
        Log::spy();
        $failure = new RuntimeException('Synthetic post-commit failure');
        Event::listen(TransactionCommitted::class, static function () use ($failure): never { throw $failure; });
        try {
            try {
                $this->app->make(MemberApplicationService::class)->submit($this->applicationData() + [
                    'applicant_photo' => UploadedFile::fake()->image('portrait.png', 8, 8)], UserProfile::with('role')->findOrFail(self::ACCOUNT));
                $this->fail('The synthetic post-commit failure did not propagate.');
            } catch (RuntimeException $exception) {
                $this->assertSame($failure, $exception);
            }
        } finally {
            Event::forget(TransactionCommitted::class);
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseCount('member_applications', 1);
        $this->assertDatabaseCount('member_application_classifications', 1);
        $application = MemberApplication::firstOrFail();
        $disk->assertExists($application->applicant_photo_path);
        Log::shouldHaveReceived('warning')->once()->with('Member application submission requires reconciliation.',
            ['application_id' => $application->id, 'code' => 'submission_outcome_uncertain']);
    }

    public function test_unconfirmed_photo_upload_rolls_back_without_a_second_cleanup_attempt(): void
    {
        $failure = new MemberApplicationPhotoUnavailableException('Synthetic uncertain photo upload');
        $photos = Mockery::mock(MemberApplicationPhotoService::class);
        $photos->shouldReceive('store')->once()->andThrow($failure);
        $photos->shouldNotReceive('discard');
        $this->app->instance(MemberApplicationPhotoService::class, $photos);
        try {
            $this->app->make(MemberApplicationService::class)->submit($this->applicationData() + [
                'applicant_photo' => UploadedFile::fake()->createWithContent('portrait.png', 'synthetic image placeholder')],
                UserProfile::with('role')->findOrFail(self::ACCOUNT));
            $this->fail('The uncertain photo upload was accepted.');
        } catch (MemberApplicationPhotoUnavailableException $exception) {
            $this->assertSame($failure, $exception);
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseCount('member_applications', 0);
        $this->assertSame([], Storage::disk('member_application_photos')->allFiles());
    }

    public function test_r2_lost_upload_response_preserves_object_and_returns_safe_503_without_an_application(): void
    {
        $fake = $this->usePhotoTransport('r2');
        $disk = Mockery::mock($fake);
        $path = null;
        $disk->shouldReceive('put')->once()->andReturnUsing(function ($name, $contents, $options) use ($fake, &$path): never {
            $path = $name;
            $fake->put($name, $contents, $options);
            throw new PrivateMediaStorageException('write_unconfirmed');
        });
        $disk->shouldNotReceive('delete');
        Storage::shouldReceive('disk')->once()->with('media_private')->andReturn($disk);
        Log::spy();
        $this->postJson(self::URL, $this->applicationData() + ['applicant_photo' => UploadedFile::fake()->image('portrait.png', 8, 8)])
            ->assertStatus(503)->assertJsonPath('message', 'The photo upload could not be confirmed. No application was submitted. Wait before retrying; contact the administrator if this continues.');
        $this->assertDatabaseCount('member_applications', 0);
        $this->assertDatabaseCount('member_application_classifications', 0);
        $this->assertIsString($path);
        $fake->assertExists($path);
        Log::shouldHaveReceived('warning')->once()->with('Member application photo upload requires attention.',
            ['application_id' => explode('/', $path)[1], 'code' => 'photo_upload_outcome_uncertain']);
    }

    public function test_guest_photo_routes_require_authentication_before_opening_storage(): void
    {
        $application = $this->app->make(MemberApplicationService::class)->submit($this->applicationData(),
            UserProfile::with('role')->findOrFail(self::ACCOUNT));
        $photos = Mockery::mock(MemberApplicationPhotoService::class);
        $photos->shouldNotReceive('open');
        $this->app->instance(MemberApplicationPhotoService::class, $photos);
        $this->flushSession();
        foreach (['association', 'field-officer'] as $prefix) {
            $this->get('/'.$prefix.'/member-applications/'.$application->id.'/photo')->assertRedirect(route('login'));
        }
    }

    private function mobile(): void
    {
        $this->flushSession();
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('verifyApiIdentity')->with('synthetic-mobile-token')->andReturn([
            'outcome' => 'verified', 'identity' => ['id' => self::ACCOUNT, 'email' => 'account@example.test'],
        ]);
        $this->app->instance(SupabaseAuthService::class, $auth);
        $this->withToken('synthetic-mobile-token');
    }

    public function test_mobile_options_are_active_server_catalogs_and_pending_applications_do_not_reserve_positions(): void
    {
        $this->mobile();
        $this->postJson('/api/v1/association/member-applications', $this->applicationData())->assertCreated();
        DB::table('sex_categories')->insert(['id' => 9, 'name' => 'Inactive', 'is_active' => false]);
        DB::table('association_positions')->insert(['id' => 9, 'code' => 'INACTIVE', 'name' => 'Inactive', 'is_active' => false, 'is_officer' => false]);
        DB::table('member_classifications')->insert(['id' => 9, 'code' => 'INACTIVE', 'name' => 'Inactive', 'is_active' => false]);
        $this->getJson('/api/v1/association/member-applications/options')->assertOk()
            ->assertJsonPath('data.association.id', self::ASSOCIATION)->assertJsonCount(1, 'data.sex_categories')
            ->assertJsonCount(1, 'data.positions')->assertJsonCount(1, 'data.classifications')
            ->assertJsonPath('data.positions.0.occupied', false)->assertDontSee('Inactive');
        DB::table('members')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'association_id' => self::ASSOCIATION,
            'position_id' => 2, 'member_status' => 'active']);
        $this->getJson('/api/v1/association/member-applications/options')->assertOk()->assertJsonPath('data.positions.0.occupied', true);
    }

    public function test_mobile_submission_reuses_pending_workflow_and_private_photo_service_without_creating_a_member(): void
    {
        $this->mobile();
        $response = $this->postJson('/api/v1/association/member-applications', $this->applicationData() + [
            'applicant_photo' => UploadedFile::fake()->image('applicant.png', 8, 8),
        ])->assertCreated()->assertJsonPath('data.association_id', self::ASSOCIATION)
            ->assertJsonPath('data.application.status', 'pending')->assertJsonPath('data.application.has_photo', true)
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $application = MemberApplication::findOrFail($response->json('data.application.id'));
        $this->assertSame(self::ACCOUNT, $application->submitted_by_user_id);
        $this->assertSame('Private synthetic letter', $application->endorsement_letter);
        $this->assertSame([1], $application->classifications()->pluck('member_classifications.id')->all());
        Storage::disk('member_application_photos')->assertExists($application->applicant_photo_path);
        $this->assertDatabaseCount('members', 0);
        $this->assertNull($application->reviewed_at);
        $response->assertDontSee($application->applicant_photo_path)->assertDontSee('Private synthetic letter');
    }

    public function test_mobile_validation_has_field_errors_and_rejects_scope_or_review_injection(): void
    {
        $this->mobile();
        foreach (['association_id' => self::ASSOCIATION, 'submitted_by_user_id' => self::ACCOUNT,
            'status' => 'approved', 'applicant_photo_path' => 'private/path'] as $field => $value) {
            $this->postJson('/api/v1/association/member-applications', $this->applicationData() + [$field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field)->assertJsonPath('error.code', 'validation_failed');
        }
        $data = $this->applicationData();
        $data['birth_date'] = '2999-01-01';
        $data['endorsement_letter'] = ' ';
        $this->postJson('/api/v1/association/member-applications', $data)->assertUnprocessable()
            ->assertJsonValidationErrors(['birth_date', 'endorsement_letter']);
        $this->assertDatabaseCount('member_applications', 0);
        $this->assertDatabaseCount('members', 0);
    }

    public function test_mobile_list_is_paginated_filtered_and_limited_to_current_account_and_association(): void
    {
        $this->mobile();
        $id = $this->postJson('/api/v1/association/member-applications', $this->applicationData())->assertCreated()->json('data.application.id');
        DB::table('member_applications')->where('id', $id)->update(['status' => 'rejected', 'rejection_reason' => 'Synthetic review reason', 'reviewed_at' => now()]);
        DB::table('user_profiles')->insert(['id' => '99999999-9999-4999-8999-999999999999', 'role_id' => 1,
            'display_name' => 'Other synthetic holder', 'is_active' => true]);
        $other = (array) DB::table('member_applications')->where('id', $id)->first();
        $other['id'] = (string) \Illuminate\Support\Str::uuid();
        $other['submitted_by_user_id'] = '99999999-9999-4999-8999-999999999999';
        $other['first_name'] = 'Withheld other submitter';
        DB::table('member_applications')->insert($other);
        $this->getJson('/api/v1/association/member-applications?status=rejected')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.page', 1)->assertJsonPath('data.last_page', 1)
            ->assertJsonPath('data.applications.0.id', $id)->assertJsonPath('data.applications.0.rejection_reason', 'Synthetic review reason')
            ->assertDontSee('Withheld')->assertDontSee('endorsement_letter')->assertDontSee('applicant_photo_path')
            ->assertDontSee('birth_date')->assertDontSee('contact_number');
        $this->getJson('/api/v1/association/member-applications?status=pending')->assertOk()->assertJsonCount(0, 'data.applications');
        $this->getJson('/api/v1/association/member-applications?association_id='.self::ASSOCIATION)->assertUnprocessable();
        $this->getJson('/api/v1/association/member-applications?page=0')->assertUnprocessable();
    }

    public function test_mobile_unavailable_submission_is_safe_and_never_returns_a_success_receipt(): void
    {
        $this->mobile();
        $service = Mockery::mock(MemberApplicationService::class);
        $service->shouldReceive('submit')->once()->andThrow(new RuntimeException('Private SQL or provider diagnostic'));
        $this->app->instance(MemberApplicationService::class, $service);
        $this->postJson('/api/v1/association/member-applications', $this->applicationData())->assertStatus(503)
            ->assertJsonPath('error.code', 'service_unavailable')->assertDontSee('Private SQL')->assertDontSee('data');
        $this->assertDatabaseCount('member_applications', 0);
    }

    public function test_mobile_submission_requires_bearer_identity_even_with_a_web_session(): void
    {
        $this->postJson('/api/v1/association/member-applications', $this->applicationData())->assertUnauthorized();
        $this->mobile();
        DB::table('association_accounts')->update(['is_active' => false]);
        $this->postJson('/api/v1/association/member-applications', $this->applicationData())->assertForbidden();
        $this->assertDatabaseCount('member_applications', 0);
    }

    public function test_mobile_fishr_duplicate_is_rejected_by_the_existing_business_rule(): void
    {
        $this->mobile();
        $data = $this->applicationData() + ['fishr_registration_number' => 'SYNTHETIC-FISHR'];
        $this->postJson('/api/v1/association/member-applications', $data)->assertCreated();
        $this->postJson('/api/v1/association/member-applications', $data)->assertUnprocessable()
            ->assertJsonValidationErrors('fishr_registration_number');
        $this->assertDatabaseCount('member_applications', 1);
        $this->assertDatabaseCount('members', 0);
    }

    public function test_mobile_list_paginates_and_excludes_records_in_another_association(): void
    {
        $this->mobile();
        $id = $this->postJson('/api/v1/association/member-applications', $this->applicationData())->assertCreated()->json('data.application.id');
        $row = (array) DB::table('member_applications')->where('id', $id)->first();
        for ($i = 0; $i < 25; $i++) {
            $row['id'] = (string) \Illuminate\Support\Str::uuid();
            DB::table('member_applications')->insert($row);
        }
        $other = '88888888-8888-4888-8888-888888888888';
        DB::table('associations')->insert(['id' => $other, 'name' => 'Other association', 'status' => 'active']);
        $row['id'] = (string) \Illuminate\Support\Str::uuid();
        $row['association_id'] = $other;
        $row['first_name'] = 'Withheld other association';
        DB::table('member_applications')->insert($row);
        $this->getJson('/api/v1/association/member-applications')->assertOk()->assertJsonCount(25, 'data.applications')
            ->assertJsonPath('data.total', 26)->assertJsonPath('data.last_page', 2)->assertDontSee('Withheld');
        $this->getJson('/api/v1/association/member-applications?page=2')->assertOk()->assertJsonCount(1, 'data.applications')
            ->assertJsonPath('data.page', 2)->assertDontSee('Withheld');
    }

    public function test_mobile_empty_catalog_does_not_create_defaults_and_staff_cannot_submit(): void
    {
        $this->mobile();
        foreach (['sex_categories', 'association_positions', 'member_classifications'] as $table) {
            DB::table($table)->update(['is_active' => false]);
        }
        $this->getJson('/api/v1/association/member-applications/options')->assertOk()
            ->assertJsonCount(0, 'data.sex_categories')->assertJsonCount(0, 'data.positions')->assertJsonCount(0, 'data.classifications');
        DB::table('roles')->update(['code' => 'field_officer']);
        $this->postJson('/api/v1/association/member-applications', $this->applicationData())->assertForbidden();
        $this->assertDatabaseCount('member_applications', 0);
    }
}
