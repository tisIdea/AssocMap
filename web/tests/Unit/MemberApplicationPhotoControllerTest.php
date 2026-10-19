<?php

namespace Tests\Unit;

use App\Http\Controllers\MemberApplicationPhotoController;
use App\Models\MemberApplication;
use App\Models\Role;
use App\Models\UserProfile;
use App\Services\MemberApplicationPhotoService;
use App\Services\SupabaseStorageService;
use Tests\Fixtures\PhotoReadFailureStream;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Processors\PostgresProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class MemberApplicationPhotoControllerTest extends TestCase
{
    private const ACTOR_ID = '11111111-1111-4111-8111-111111111111';
    private const APPLICATION_ID = '22222222-2222-4222-8222-222222222222';
    private const ASSOCIATION_ID = '33333333-3333-4333-8333-333333333333';
    private const PATH = 'photos/'.self::APPLICATION_ID.'/44444444-4444-4444-8444-444444444444.png';
    private const URL = 'https://storage.example.test/storage/v1/object/authenticated/member-application-photos/'.self::PATH;

    private ?ConnectionResolverInterface $originalResolver = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalResolver = Model::getConnectionResolver();
        config(['services.supabase_storage' => [
            'photo_transport' => 'supabase', 'url' => 'https://storage.example.test',
            'secret_key' => 'sb_secret_synthetic_test_only', 'key_type' => 'secret',
            'bucket' => 'member-application-photos', 'connect_timeout' => 5, 'timeout' => 20,
        ]]);
        Http::fake([]);
        Http::preventStrayRequests();
        $this->scope([], []);
    }

    protected function tearDown(): void
    {
        if ($this->originalResolver !== null) Model::setConnectionResolver($this->originalResolver);
        else Model::unsetConnectionResolver();
        parent::tearDown();
    }

    private function actor(string $role, bool $accessible = true): UserProfile
    {
        $actor = Mockery::mock(UserProfile::class)->makePartial();
        $actor->setRawAttributes(['id' => self::ACTOR_ID]);
        $actor->setRelation('role', new Role(['code' => $role]));
        $actor->shouldReceive('canAccessApplication')->andReturn($accessible);

        return $actor;
    }

    private function application(string $submitter = self::ACTOR_ID): MemberApplication
    {
        $application = new MemberApplication;
        $application->setRawAttributes([
            'id' => self::APPLICATION_ID, 'association_id' => self::ASSOCIATION_ID,
            'submitted_by_user_id' => $submitter, 'applicant_photo_path' => self::PATH,
        ]);

        return $application;
    }

    private function scope(array $bindings, array $results, string $table = ''): void
    {
        // Compile real scope queries, but intercept selects before any database access.
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getTablePrefix')->andReturn('');
        $grammar = new PostgresGrammar($connection);
        $processor = new PostgresProcessor;
        $connection->shouldReceive('query')->andReturnUsing(
            static fn () => new Builder($connection, $grammar, $processor)
        );
        if ($results === []) {
            $connection->shouldNotReceive('select');
        } else {
            foreach ($results as $exists) {
                $connection->shouldReceive('select')->once()->ordered()
                    ->with(Mockery::on(static fn ($sql) => is_string($sql) && str_contains($sql, '"'.$table.'"')),
                        $bindings, false)->andReturn([['exists' => $exists]]);
            }
        }
        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->withAnyArgs()->andReturn($connection);
        Model::setConnectionResolver($resolver);
    }

    private function response(?UserProfile $actor, ?MemberApplication $application = null): Response
    {
        $request = Request::create('/synthetic-photo', 'GET');
        if ($actor !== null) $request->attributes->set('current_user', $actor);

        return (new MemberApplicationPhotoController)($request, $application ?? $this->application(),
            new MemberApplicationPhotoService(new SupabaseStorageService));
    }

    private function assertPrivatePhoto(Response $response): void
    {
        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        ob_start();
        try {
            $response->sendContent();
            $this->assertSame('synthetic image bytes', ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }

    public function test_currently_assigned_field_officer_can_download_a_private_photo(): void
    {
        $this->scope([self::ASSOCIATION_ID, self::ACTOR_ID], [true], 'associations');
        Http::fake([self::URL => Http::response('synthetic image bytes', 200, ['Content-Type' => 'image/png'])]);
        $this->assertPrivatePhoto($this->response($this->actor('field_officer')));
        Http::assertSentCount(1);
    }

    public function test_submitting_account_with_current_active_association_link_can_download(): void
    {
        $this->scope([self::ACTOR_ID, self::ASSOCIATION_ID, true], [true], 'association_accounts');
        Http::fake([self::URL => Http::response('synthetic image bytes', 200, ['Content-Type' => 'image/png'])]);
        $this->assertPrivatePhoto($this->response($this->actor('association_account')));
        Http::assertSentCount(1);
    }

    public function test_public_or_inaccessible_actor_is_rejected_before_storage_access(): void
    {
        $this->assertSame(403, $this->response(null)->getStatusCode());
        $this->assertSame(403, $this->response($this->actor('field_officer', false))->getStatusCode());
        Http::assertNothingSent();
    }

    public function test_unapproved_roles_cannot_download_private_evidence(): void
    {
        foreach (['system_admin', 'public', 'unrelated_role'] as $role) {
            $this->assertSame(404, $this->response($this->actor($role))->getStatusCode());
        }
        Http::assertNothingSent();
    }

    public function test_matching_association_alone_does_not_authorize_another_submitters_account(): void
    {
        $this->assertSame(404, $this->response($this->actor('association_account'),
            $this->application('55555555-5555-4555-8555-555555555555'))->getStatusCode());
        Http::assertNothingSent();
    }

    public function test_inactive_or_mismatched_account_link_is_rejected_before_storage_access(): void
    {
        $this->scope([self::ACTOR_ID, self::ASSOCIATION_ID, true], [false], 'association_accounts');
        $this->assertSame(404, $this->response($this->actor('association_account'))->getStatusCode());
        Http::assertNothingSent();
    }

    public function test_field_officer_scope_is_rechecked_on_each_request(): void
    {
        $this->scope([self::ASSOCIATION_ID, self::ACTOR_ID], [true, false], 'associations');
        Http::fake([self::URL => Http::response('synthetic image bytes', 200, ['Content-Type' => 'image/png'])]);
        $actor = $this->actor('field_officer');
        $this->assertPrivatePhoto($this->response($actor));
        $this->assertSame(404, $this->response($actor)->getStatusCode());
        Http::assertSentCount(1);
    }

    public function test_authorized_download_distinguishes_missing_object_from_provider_outage(): void
    {
        $this->scope([self::ASSOCIATION_ID, self::ACTOR_ID], [true, true], 'associations');
        Http::fakeSequence(self::URL)->push(['code' => 'NoSuchKey'], 404)->push([], 503);
        $actor = $this->actor('field_officer');
        $missing = $this->response($actor);
        $outage = $this->response($actor);
        $this->assertSame(404, $missing->getStatusCode());
        $this->assertSame(503, $outage->getStatusCode());
        $this->assertStringContainsString('temporarily unavailable', $outage->getContent());
        $this->assertStringContainsString('no-store', $outage->headers->get('Cache-Control'));
        Http::assertSentCount(2);
    }

    public function test_authorized_stream_is_closed_after_the_response_has_sent_its_contents(): void
    {
        $this->scope([self::ASSOCIATION_ID, self::ACTOR_ID], [true], 'associations');
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'synthetic image bytes');
        rewind($stream);
        $photos = Mockery::mock(MemberApplicationPhotoService::class);
        $photos->shouldReceive('open')->once()->with(self::APPLICATION_ID, self::PATH)
            ->andReturn(['stream' => $stream, 'mime_type' => 'image/png']);
        $request = Request::create('/synthetic-photo', 'GET');
        $request->attributes->set('current_user', $this->actor('field_officer'));
        try {
            $this->assertPrivatePhoto((new MemberApplicationPhotoController)($request, $this->application(), $photos));
            $this->assertFalse(is_resource($stream));
        } finally {
            if (is_resource($stream)) fclose($stream);
        }
        Http::assertNothingSent();
    }

    public function test_authorized_stream_is_also_closed_when_reading_the_response_fails(): void
    {
        $this->scope([self::ASSOCIATION_ID, self::ACTOR_ID], [true], 'associations');
        $this->assertTrue(stream_wrapper_register('synthetic-photo', PhotoReadFailureStream::class));
        PhotoReadFailureStream::$closes = 0;
        $stream = fopen('synthetic-photo://image', 'rb');
        $photos = Mockery::mock(MemberApplicationPhotoService::class);
        $photos->shouldReceive('open')->once()->with(self::APPLICATION_ID, self::PATH)
            ->andReturn(['stream' => $stream, 'mime_type' => 'image/png']);
        $request = Request::create('/synthetic-photo', 'GET');
        $request->attributes->set('current_user', $this->actor('field_officer'));
        ob_start();
        try {
            $response = (new MemberApplicationPhotoController)($request, $this->application(), $photos);
            try {
                $response->sendContent();
                $this->fail('The synthetic stream failure did not propagate.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Synthetic photo read failure', $exception->getMessage());
            }
            $this->assertFalse(is_resource($stream));
            $this->assertSame(1, PhotoReadFailureStream::$closes);
        } finally {
            ob_end_clean();
            if (is_resource($stream)) fclose($stream);
            stream_wrapper_unregister('synthetic-photo');
        }
        Http::assertNothingSent();
    }
}
