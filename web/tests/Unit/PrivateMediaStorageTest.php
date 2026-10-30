<?php

namespace Tests\Unit;

use App\Exceptions\PrivateMediaStorageException;
use App\Services\PrivateMediaAdapter;
use App\Services\Members\MemberApplicationPhotoService;
use App\Services\SupabaseStorageService;
use Aws\Command;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

class PrivateMediaStorageTest extends TestCase
{
    private const KEY = 'synthetic-media-key';

    private const SECRET = 'synthetic-media-secret';

    private const PATH = 'monitoring/synthetic-private-object.txt';

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.disks.media_private' => array_replace(config('filesystems.disks.media_private'), [
            'key' => self::KEY,
            'secret' => self::SECRET,
            'region' => 'us-east-1',
            'bucket' => 'synthetic-media-tests',
            'endpoint' => 'https://objects.example.test',
        ])]);
    }

    private function disk(array $results = []): array
    {
        $disk = $this->app->make(FilesystemManager::class)->disk('media_private');
        // Replace the SDK transport before any operation; constructor resolution makes no requests.
        $s3 = (new ReflectionProperty($disk->getAdapter(), 'adapter'))->getValue($disk->getAdapter());
        $client = (new ReflectionProperty($s3, 'client'))->getValue($s3);
        $handler = new MockHandler($results);
        $client->getHandlerList()->setHandler($handler);

        return [$disk, $handler, $client];
    }

    public function test_environment_configuration_is_independent_cacheable_and_preserves_existing_disks(): void
    {
        $repository = Env::getRepository();
        $settings = [
            'MEDIA_PRIVATE_ACCESS_KEY_ID' => self::KEY,
            'MEDIA_PRIVATE_SECRET_ACCESS_KEY' => self::SECRET,
            'MEDIA_PRIVATE_REGION' => 'auto',
            'MEDIA_PRIVATE_BUCKET' => 'synthetic-media-tests',
            'MEDIA_PRIVATE_ENDPOINT' => 'https://objects.example.test',
            'MEDIA_PRIVATE_USE_PATH_STYLE_ENDPOINT' => 'true',
            'MEDIA_PRIVATE_CONNECT_TIMEOUT' => '4',
            'MEDIA_PRIVATE_TIMEOUT' => '25',
        ];
        $before = require config_path('filesystems.php');
        $original = [];
        foreach ($settings as $name => $value) {
            $original[$name] = $repository->get($name);
            $repository->set($name, $value);
        }
        try {
            $loaded = require config_path('filesystems.php');
            $media = $loaded['disks']['media_private'];
            $this->assertSame([self::KEY, self::SECRET, 'auto', 'synthetic-media-tests', 'https://objects.example.test', true, '4', '25'],
                array_map(fn ($field) => $media[$field], ['key', 'secret', 'region', 'bucket', 'endpoint', 'use_path_style_endpoint', 'connect_timeout', 'timeout']));
            $this->assertSame('private', $media['visibility']);
            $this->assertSame('private', $media['directory_visibility']);
            $this->assertTrue($media['throw']);
            $this->assertFalse($media['report']);
            $this->assertArrayNotHasKey('url', $media);
            $this->assertArrayNotHasKey('media_private', $loaded['links']);
            unset($loaded['disks']['media_private'], $before['disks']['media_private']);
            $this->assertSame($before, $loaded);

            $cache = tempnam(sys_get_temp_dir(), 'synthetic-media-config-');
            try {
                file_put_contents($cache, '<?php return '.var_export($media, true).';');
                $repository->set('MEDIA_PRIVATE_BUCKET', 'different-synthetic-bucket');
                $cached = require $cache;
                $this->assertSame($media, $cached);
                config(['filesystems.disks.media_private' => $cached]);
                [, , $client] = $this->disk();
                $this->assertSame('auto', $client->getRegion());
                $this->assertTrue($client->getConfig('use_path_style_endpoint'));
            } finally {
                unlink($cache);
            }
        } finally {
            foreach ($original as $name => $value) {
                $value === null ? $repository->clear($name) : $repository->set($name, $value);
            }
        }
    }

    #[DataProvider('invalidSettings')]
    public function test_invalid_configuration_fails_before_creating_an_s3_client(string $field, mixed $value): void
    {
        config(['filesystems.disks.media_private.'.$field => $value]);
        $manager = $this->app->make(FilesystemManager::class);
        $factory = Mockery::mock(FilesystemManager::class);
        $factory->shouldNotReceive('createS3Driver');
        $this->app->instance(FilesystemManager::class, $factory);
        $this->expectException(PrivateMediaStorageException::class);
        $this->expectExceptionMessage('configuration_invalid');
        $manager->disk('media_private');
    }

    public static function invalidSettings(): array
    {
        $cases = [];
        foreach (['key', 'secret', 'region', 'bucket', 'endpoint'] as $field) {
            foreach ([null, '', ' ', [], false] as $index => $value) {
                $cases[$field.' missing/invalid '.$index] = [$field, $value];
            }
        }
        foreach ([
            ['key', "synthetic\nkey"], ['secret', str_repeat('x', 4097)],
            ['endpoint', 'not-a-url'], ['endpoint', 'http://objects.example.test'],
            ['endpoint', 'https://user:password@objects.example.test'],
            ['endpoint', 'https://objects.example.test?token=synthetic'], ['endpoint', 'https://objects.example.test#synthetic'],
            ['bucket', 'Uppercase'], ['bucket', 'a'], ['bucket', 'invalid/bucket'],
            ['bucket', '127.0.0.1'], ['bucket', 'bad..bucket'], ['bucket', 'bad.-bucket'],
            ['region', 'invalid region'], ['use_path_style_endpoint', 'typo'],
            ['connect_timeout', 0], ['connect_timeout', 11], ['connect_timeout', 'invalid'],
            ['connect_timeout', true], ['connect_timeout', []],
            ['timeout', 0], ['timeout', 121], ['timeout', 'invalid'], ['timeout', 4],
            ['timeout', true], ['timeout', []],
            ['visibility', 'public'], ['directory_visibility', 'public'], ['throw', false], ['report', true],
        ] as $index => $case) {
            $cases['invalid value '.$index] = $case;
        }

        return $cases;
    }

    public function test_client_uses_explicit_credentials_and_bounded_tls_transport(): void
    {
        [, , $client] = $this->disk();
        $this->assertInstanceOf(S3Client::class, $client);
        $credentials = $client->getCredentials()->wait();
        $this->assertSame(self::KEY, $credentials->getAccessKeyId());
        $this->assertSame(self::SECRET, $credentials->getSecretKey());
        $this->assertStringNotContainsString('Name: retry,', (string) $client->getHandlerList());
        $http = $client->getCommand('HeadObject')['@http'];
        $this->assertSame(5, $http['connect_timeout']);
        $this->assertSame(30, $http['timeout']);
        $this->assertSame(30, $http['read_timeout']);
        $this->assertTrue($http['verify']);
        $this->assertFalse($http['allow_redirects']);
    }

    public function test_client_initialization_failures_are_sanitized(): void
    {
        $manager = $this->app->make(FilesystemManager::class);
        $factory = Mockery::mock(FilesystemManager::class);
        $factory->shouldReceive('createS3Driver')->once()->andThrow(new RuntimeException(self::SECRET.' '.self::PATH));
        $this->app->instance(FilesystemManager::class, $factory);
        try {
            $manager->disk('media_private');
            $this->fail('Client initialization failure was accepted.');
        } catch (PrivateMediaStorageException $exception) {
            $this->assertSame('configuration_invalid', $exception->reason);
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString(self::SECRET, $exception->getMessage());
            $this->assertStringNotContainsString(self::PATH, $exception->getMessage());
        }
    }

    public function test_r2_write_read_and_delete_requests_do_not_transmit_acl_headers(): void
    {
        config(['filesystems.disks.media_private.region' => 'auto',
            'filesystems.disks.media_private.endpoint' => 'https://synthetic-account.r2.cloudflarestorage.com']);
        $requests = [];
        $capture = function ($command, $request) use (&$requests) {
            $requests[] = [$command, $request];

            return new Result(['Body' => Utils::streamFor('synthetic')]);
        };
        [$disk] = $this->disk(array_fill(0, 3, $capture));
        $this->assertTrue($disk->put(self::PATH, 'synthetic'));
        $stream = $disk->readStream(self::PATH);
        try {
            $this->assertSame('synthetic', stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
        $this->assertTrue($disk->delete(self::PATH));
        $this->assertSame(['PUT', 'GET', 'DELETE'], array_map(fn ($pair) => $pair[1]->getMethod(), $requests));
        foreach ($requests as [$command, $request]) {
            $this->assertNull($command['ACL']);
            $this->assertFalse($request->hasHeader('x-amz-acl'));
            $this->assertStringNotContainsString('acl', $request->getUri()->getQuery());
            foreach (array_keys($request->getHeaders()) as $header) {
                $this->assertFalse(str_starts_with(strtolower($header), 'x-amz-grant-'));
            }
            $this->assertSame('https', $request->getUri()->getScheme());
            $this->assertStringContainsString('/auto/s3/aws4_request', $request->getHeaderLine('Authorization'));
        }
    }

    public function test_member_application_r2_round_trip_uses_the_guarded_sdk_adapter_without_acl_headers(): void
    {
        config(['services.supabase_storage.photo_transport' => 'r2', 'filesystems.disks.media_private.region' => 'auto',
            'filesystems.disks.media_private.endpoint' => 'https://synthetic-account.r2.cloudflarestorage.com']);
        $contents = '';
        $requests = [];
        $capture = function ($command, $request) use (&$contents, &$requests) {
            $requests[] = [$command, $request];
            if ($command->getName() === 'PutObject') $contents = (string) $request->getBody();

            return new Result(['ContentLength' => strlen($contents), 'ContentType' => 'image/png', 'Body' => Utils::streamFor($contents)]);
        };
        [, $handler] = $this->disk(array_fill(0, 6, $capture));
        $remote = Mockery::mock(SupabaseStorageService::class);
        $remote->shouldNotReceive('upload');
        $remote->shouldNotReceive('download');
        $remote->shouldNotReceive('delete');
        $photos = new MemberApplicationPhotoService($remote);
        $application = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $path = $photos->store(UploadedFile::fake()->image('portrait.png', 8, 8), $application);
        $photo = $photos->open($application, $path);
        try {
            $this->assertSame($contents, stream_get_contents($photo['stream']));
            $this->assertSame('image/png', $photo['mime_type']);
        } finally {
            fclose($photo['stream']);
        }
        $photos->discard($path, $application);
        $this->assertCount(0, $handler);
        $this->assertSame(['PutObject', 'HeadObject', 'HeadObject', 'HeadObject', 'GetObject', 'DeleteObject'],
            array_map(fn ($pair) => $pair[0]->getName(), $requests));
        foreach ($requests as [$command, $request]) {
            $this->assertSame($path, $command['Key']);
            $this->assertNull($command['ACL']);
            $this->assertFalse($request->hasHeader('x-amz-acl'));
        }
        $this->assertSame('image/png', $requests[0][1]->getHeaderLine('Content-Type'));
        $this->assertSame('private, no-store, max-age=0', $requests[0][1]->getHeaderLine('Cache-Control'));
    }

    public function test_automatic_multipart_upload_does_not_send_acl_headers(): void
    {
        config(['filesystems.disks.media_private.region' => 'auto',
            'filesystems.disks.media_private.endpoint' => 'https://synthetic-account.r2.cloudflarestorage.com']);
        $commands = [];
        $capture = function ($command, $request) use (&$commands) {
            $commands[] = $command->getName();
            $this->assertNull($command['ACL']);
            $this->assertFalse($request->hasHeader('x-amz-acl'));
            $this->assertSame('https', $request->getUri()->getScheme());
            $this->assertStringContainsString('/auto/s3/aws4_request', $request->getHeaderLine('Authorization'));

            return match ($command->getName()) {
                'CreateMultipartUpload' => new Result(['UploadId' => 'synthetic-upload']),
                'UploadPart', 'CompleteMultipartUpload' => new Result(['ETag' => 'synthetic-etag']),
                default => throw new RuntimeException('Unexpected synthetic multipart command.'),
            };
        };
        [$disk, $handler] = $this->disk(array_fill(0, 6, $capture));
        $stream = tmpfile();
        try {
            ftruncate($stream, 16 * 1024 * 1024 + 1);
            rewind($stream);
            $this->assertTrue($disk->writeStream(self::PATH, $stream, ['ContentType' => 'application/octet-stream']));
            $this->assertSame(['CreateMultipartUpload', 'UploadPart', 'UploadPart', 'UploadPart', 'UploadPart', 'CompleteMultipartUpload'], $commands);
            $this->assertCount(0, $handler);
        } finally {
            fclose($stream);
        }
    }

    public function test_laravel_put_file_streams_an_uploaded_file_without_acl_headers(): void
    {
        $body = null;
        [$disk, $handler] = $this->disk([function ($command, $request) use (&$body) {
            $body = (string) $request->getBody();

            return new Result;
        }]);
        $path = $disk->putFile('monitoring', UploadedFile::fake()->createWithContent('synthetic.txt', 'synthetic payload'), 'private');
        $this->assertIsString($path);
        $this->assertStringStartsWith('monitoring/', $path);
        $this->assertSame($path, $handler->getLastCommand()['Key']);
        $this->assertNull($handler->getLastCommand()['ACL']);
        $this->assertFalse($handler->getLastRequest()->hasHeader('x-amz-acl'));
        $this->assertSame('synthetic payload', $body);
    }

    public function test_laravel_storage_fake_remains_available_for_consumer_tests(): void
    {
        $fake = Storage::fake('media_private', ['visibility' => 'private', 'directory_visibility' => 'private']);
        $this->assertSame($fake, Storage::disk('media_private'));
        $this->assertTrue($fake->put(self::PATH, 'synthetic'));
        $this->assertSame('synthetic', $fake->get(self::PATH));
        $this->assertTrue($fake->exists(self::PATH));
        $this->assertTrue($fake->delete(self::PATH));
        $this->assertFalse($fake->exists(self::PATH));
    }

    public function test_directory_creation_copy_and_move_do_not_send_or_read_acl(): void
    {
        $commands = [];
        $requests = [];
        $capture = function ($command, $request) use (&$commands, &$requests) {
            $commands[] = $command;
            $requests[] = $request;

            return new Result(['ContentLength' => 17]);
        };
        [$disk] = $this->disk(array_fill(0, 6, $capture));
        $this->assertTrue($disk->makeDirectory('synthetic-folder'));
        $this->assertTrue($disk->copy(self::PATH, 'synthetic-copy.txt'));
        $this->assertTrue($disk->move(self::PATH, 'synthetic-moved.txt'));
        $this->assertSame(['PutObject', 'HeadObject', 'CopyObject', 'HeadObject', 'CopyObject', 'DeleteObject'],
            array_map(fn ($command) => $command->getName(), $commands));
        foreach ($commands as $command) {
            $this->assertNull($command['ACL']);
        }
        foreach ($requests as $request) {
            $this->assertFalse($request->hasHeader('x-amz-acl'));
            $this->assertStringNotContainsString('acl', $request->getUri()->getQuery());
        }
    }

    public function test_acl_inspection_and_mutation_are_explicitly_unsupported_without_requests(): void
    {
        [$disk, $handler] = $this->disk();
        foreach ([fn () => $disk->setVisibility(self::PATH, 'private'), fn () => $disk->getVisibility(self::PATH)] as $operation) {
            try {
                $operation();
                $this->fail('Unsupported ACL operation was accepted.');
            } catch (PrivateMediaStorageException $exception) {
                $this->assertSame('operation_unsupported', $exception->reason);
            }
        }
        $this->assertNull($handler->getLastRequest());
    }

    public function test_streamed_write_read_exists_and_delete_use_the_real_s3_adapter(): void
    {
        [$disk, $handler] = $this->disk([
            new Result, new Result(['ContentLength' => 17]),
            new Result(['Body' => Utils::streamFor('synthetic payload')]), new Result,
        ]);
        $input = fopen('php://temp', 'w+b');
        try {
            fwrite($input, 'synthetic payload');
            rewind($input);
            $this->assertTrue($disk->writeStream(self::PATH, $input, ['ContentType' => 'text/plain']));
            $this->assertTrue(is_resource($input));
            $this->assertSame('PutObject', $handler->getLastCommand()->getName());
            $this->assertNull($handler->getLastCommand()['ACL']);
            $this->assertFalse($handler->getLastRequest()->hasHeader('x-amz-acl'));
            $this->assertSame('text/plain', $handler->getLastCommand()['ContentType']);
            $this->assertSame('synthetic payload', (string) $handler->getLastRequest()->getBody());
            $this->assertTrue($disk->exists(self::PATH));
            $stream = $disk->readStream(self::PATH);
            try {
                $this->assertTrue(is_resource($stream));
                $this->assertSame('synthetic payload', stream_get_contents($stream));
                $this->assertTrue($handler->getLastCommand()['@http']['stream']);
            } finally {
                fclose($stream);
            }
            $this->assertTrue($disk->delete(self::PATH));
            $this->assertSame('DeleteObject', $handler->getLastCommand()->getName());
            $this->assertCount(0, $handler);
        } finally {
            fclose($input);
        }
    }

    public function test_string_put_and_get_are_supported_and_default_to_private(): void
    {
        [$disk, $handler] = $this->disk([new Result, new Result(['Body' => Utils::streamFor('synthetic')])]);
        $this->assertTrue($disk->put(self::PATH, 'synthetic'));
        $this->assertNull($handler->getLastCommand()['ACL']);
        $this->assertFalse($handler->getLastRequest()->hasHeader('x-amz-acl'));
        $this->assertSame('synthetic', $disk->get(self::PATH));
    }

    #[DataProvider('publicOptions')]
    public function test_public_or_unsafe_write_options_are_rejected_before_any_request(array $options): void
    {
        [$disk, $handler] = $this->disk();
        try {
            $disk->put(self::PATH, 'synthetic', $options);
            $this->fail('Unsafe write was accepted.');
        } catch (PrivateMediaStorageException $exception) {
            $this->assertSame('options_rejected', $exception->reason);
            $this->assertNull($handler->getLastRequest());
        }
    }

    public static function publicOptions(): array
    {
        return [
            [['visibility' => 'public']], [['directory_visibility' => 'public']],
            [['ACL' => 'public-read']], [['GrantRead' => 'uri=synthetic-public-group']],
            [['params' => ['ACL' => 'public-read']]], [['before_upload' => 'synthetic']],
        ];
    }

    public function test_unsafe_stream_copy_and_directory_options_never_reach_the_provider(): void
    {
        [$disk, $handler] = $this->disk();
        $input = fopen('php://temp', 'w+b');
        try {
            foreach ([fn () => $disk->writeStream(self::PATH, $input, ['ACL' => 'public-read']),
                fn () => $disk->getDriver()->copy(self::PATH, 'synthetic-copy', ['visibility' => 'public']),
                fn () => $disk->getDriver()->move(self::PATH, 'synthetic-move', ['GrantRead' => 'synthetic']),
                fn () => $disk->getDriver()->createDirectory('synthetic-folder', ['directory_visibility' => 'public'])] as $operation) {
                try {
                    $operation();
                    $this->fail('Unsafe storage options were accepted.');
                } catch (PrivateMediaStorageException $exception) {
                    $this->assertSame('options_rejected', $exception->reason);
                }
            }
            $this->assertNull($handler->getLastRequest());
        } finally {
            fclose($input);
        }
    }

    public function test_public_visibility_and_url_generation_are_unavailable(): void
    {
        [$disk, $handler] = $this->disk();
        foreach ([fn () => $disk->setVisibility(self::PATH, 'public'),
            fn () => $disk->url(self::PATH), fn () => $disk->temporaryUrl(self::PATH, now()->addMinute()),
            fn () => $disk->temporaryUploadUrl(self::PATH, now()->addMinute())] as $operation) {
            try {
                $operation();
                $this->fail('Public access was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringNotContainsString(self::PATH, $exception->getMessage());
            }
        }
        $this->assertFalse($disk->providesTemporaryUrls());
        $this->assertNull($handler->getLastRequest());
    }

    private function failure(string $code, int $status): S3Exception
    {
        return new S3Exception(self::SECRET.' '.self::PATH.' signed-provider-response', new Command('Synthetic'), [
            'code' => $code, 'response' => $status > 0 ? new Response($status) : null,
            'connection_error' => $status === 0,
        ]);
    }

    public function test_missing_object_check_is_false_missing_read_fails_and_delete_is_idempotent(): void
    {
        [$disk, $handler] = $this->disk([
            $this->failure('NotFound', 404), new Result,
            $this->failure('NoSuchKey', 404), new Result,
        ]);
        $this->assertFalse($disk->exists(self::PATH));
        try {
            $disk->readStream(self::PATH);
            $this->fail('Missing object was readable.');
        } catch (PrivateMediaStorageException $exception) {
            $this->assertSame('read_failed', $exception->reason);
            $this->assertNull($exception->getPrevious());
        }
        $this->assertTrue($disk->delete(self::PATH));
        $this->assertCount(0, $handler);
    }

    #[DataProvider('providerFailures')]
    public function test_provider_failures_are_sanitized_without_retry_fallback_or_raw_logging(string $operation, string $reason, string $code, int $status): void
    {
        Log::spy();
        [$disk, $handler] = $this->disk([$this->failure($code, $status)]);
        try {
            match ($operation) {
                'put' => $disk->put(self::PATH, 'synthetic'),
                'readStream' => $disk->readStream(self::PATH),
                'exists' => $disk->exists(self::PATH),
                'delete' => $disk->delete(self::PATH),
                'size' => $disk->size(self::PATH),
                'files' => $disk->files(self::PATH),
            };
            $this->fail('Provider failure was accepted.');
        } catch (PrivateMediaStorageException $exception) {
            $this->assertSame($reason, $exception->reason);
            $this->assertInstanceOf(ShouldntReport::class, $exception);
            $this->assertNull($exception->getPrevious());
            foreach ([self::KEY, self::SECRET, self::PATH, 'signed-provider-response'] as $sensitive) {
                $this->assertStringNotContainsString($sensitive, $exception->getMessage());
            }
        }
        $this->assertCount(0, $handler);
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
        $this->assertSame('local', config('filesystems.default'));
        $this->assertFalse(file_exists(storage_path('app/private/'.self::PATH)));
    }

    public static function providerFailures(): array
    {
        return [
            ['put', 'write_unconfirmed', 'AccessDenied', 403],
            ['put', 'write_unconfirmed', 'InvalidAccessKeyId', 403],
            ['put', 'write_unconfirmed', 'NoSuchBucket', 404],
            ['put', 'write_unconfirmed', 'ServiceUnavailable', 503],
            ['put', 'write_unconfirmed', 'ConnectionTimeout', 0],
            ['readStream', 'read_failed', 'ConnectionTimeout', 0],
            ['readStream', 'read_failed', 'ServiceUnavailable', 503],
            ['exists', 'exists_failed', 'AccessDenied', 403],
            ['delete', 'delete_unconfirmed', 'AccessDenied', 403],
            ['size', 'metadata_failed', 'AccessDenied', 403],
            ['files', 'list_failed', 'ServiceUnavailable', 503],
        ];
    }

    public function test_lazy_listing_failures_are_sanitized_during_iteration(): void
    {
        $inner = Mockery::mock(FlysystemAdapter::class);
        $inner->shouldReceive('listContents')->once()->andReturnUsing(function () {
            yield new FileAttributes('synthetic');
            throw new RuntimeException(self::SECRET.' '.self::PATH);
        });
        $adapter = new PrivateMediaAdapter($inner);
        $this->expectException(PrivateMediaStorageException::class);
        $this->expectExceptionMessage('list_failed');
        iterator_to_array($adapter->listContents('', false));
    }
}
