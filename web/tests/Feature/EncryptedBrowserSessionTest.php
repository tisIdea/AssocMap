<?php

namespace Tests\Feature;

use Illuminate\Encryption\Encrypter;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\EncryptedStore;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

class EncryptedBrowserSessionTest extends StaffAuthenticationCharacterizationTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['session.encrypt' => true]);
        app('session')->forgetDrivers();
        $this->app->forgetInstance('session.store');
    }

    public function test_login_credentials_are_encrypted_at_rest_and_restore_in_an_independent_store(): void
    {
        Http::fake(['*/auth/v1/token*' => Http::response([
            'access_token' => 'synthetic_access_secret', 'refresh_token' => 'synthetic_refresh_secret',
            'user' => ['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'email' => 'synthetic@example.test'],
        ])]);
        $this->post('/login', ['email' => 'synthetic@example.test', 'password' => 'synthetic_password_secret'])
            ->assertRedirect(route('admin.dashboard'))->assertSessionMissing('supabase_refresh_token');
        $store = app('session.store');
        $this->assertInstanceOf(EncryptedStore::class, $store);
        $payload = $store->getHandler()->read($store->getId());
        foreach (['synthetic_access_secret', 'synthetic_refresh_secret', 'synthetic_password_secret', 'supabase_access_token'] as $secret) {
            $this->assertStringNotContainsString($secret, $payload);
        }
        $restored = new EncryptedStore(config('session.cookie'), $store->getHandler(), app('encrypter'), $store->getId());
        $restored->start();
        $this->assertSame('synthetic_access_secret', $restored->get('supabase_access_token'));
        $this->assertSame('system_admin', $restored->get('user_role'));
        $this->assertFalse($restored->has('supabase_refresh_token'));
    }

    public function test_file_driver_encrypts_persists_expires_and_destroys_only_temporary_sessions(): void
    {
        $files = new Filesystem;
        $directory = sys_get_temp_dir().'/assocmap-session-test-'.bin2hex(random_bytes(12));
        $files->makeDirectory($directory);
        try {
            config(['session.files' => $directory, 'session.lifetime' => 2]);
            $store = (new SessionManager($this->app))->driver('file');
            $this->assertInstanceOf(EncryptedStore::class, $store);
            $store->start();
            $store->put(['supabase_access_token' => 'synthetic_file_access', 'password_recovery_context' => str_repeat('a', 64)]);
            $store->save();
            $id = $store->getId();
            $this->assertStringNotContainsString('synthetic_file_access', $files->get($directory.'/'.$id));
            $restored = (new SessionManager($this->app))->driver('file');
            $restored->setId($id);
            $restored->start();
            $this->assertSame('synthetic_file_access', $restored->get('supabase_access_token'));
            $this->assertSame(str_repeat('a', 64), $restored->get('password_recovery_context'));
            $this->travel(3)->minutes();
            $expired = (new SessionManager($this->app))->driver('file');
            $expired->setId($id);
            $expired->start();
            $this->assertFalse($expired->has('supabase_access_token'));
            $this->assertFalse($expired->has('password_recovery_context'));
            $restored->invalidate();
            $this->assertFileDoesNotExist($directory.'/'.$id);
        } finally {
            // Delete only files in this test's randomly named temporary directory.
            foreach ($files->files($directory) as $file) {
                $files->delete($file->getPathname());
            }
            rmdir($directory);
        }
    }

    public function test_enabling_encryption_drops_plaintext_auth_and_recovery_state_without_migrating_old_storage(): void
    {
        $handler = new ArraySessionHandler(120);
        $plain = new Store('isolated', $handler);
        $plain->start();
        $plain->put(['supabase_access_token' => 'synthetic_old_access', 'password_recovery_context' => str_repeat('b', 64)]);
        $plain->save();
        $payload = $handler->read($plain->getId());
        $encrypted = new EncryptedStore('isolated', $handler, app('encrypter'), $plain->getId());
        $encrypted->start();
        $this->assertFalse($encrypted->has('supabase_access_token'));
        $this->assertFalse($encrypted->has('password_recovery_context'));
        $this->assertNotSame($plain->token(), $encrypted->token());
        $this->assertSame($payload, $handler->read($plain->getId()));
        // A read does not rewrite old files; switching off before rewriting can revive them.
        $rollback = new Store('isolated', $handler, $plain->getId());
        $rollback->start();
        $this->assertSame('synthetic_old_access', $rollback->get('supabase_access_token'));
        $encrypted->save();
        $rollback = new Store('isolated', $handler, $plain->getId());
        $rollback->start();
        $this->assertFalse($rollback->has('supabase_access_token'));
    }

    public function test_wrong_key_and_tampering_cannot_restore_encrypted_credentials(): void
    {
        $handler = new ArraySessionHandler(120);
        $store = new EncryptedStore('isolated', $handler, app('encrypter'));
        $store->start();
        $store->put('supabase_access_token', 'synthetic_encrypted_access');
        $store->save();
        $wrongKey = new EncryptedStore('isolated', $handler, new Encrypter(str_repeat('x', 32), 'AES-256-CBC'), $store->getId());
        $wrongKey->start();
        $this->assertFalse($wrongKey->has('supabase_access_token'));
        $handler->write($store->getId(), 'invalid-encrypted-payload');
        $tampered = new EncryptedStore('isolated', $handler, app('encrypter'), $store->getId());
        $tampered->start();
        $this->assertFalse($tampered->has('supabase_access_token'));
    }

    public function test_switching_accounts_replaces_identity_and_role_without_retaining_refresh_credentials(): void
    {
        $first = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $second = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        DB::table('roles')->insert(['id' => 2, 'code' => 'field_officer', 'is_active' => true]);
        DB::table('user_profiles')->insert(['id' => $second, 'role_id' => 2, 'display_name' => 'Other synthetic account', 'is_active' => true]);
        Http::fake(function ($request) use ($first, $second) {
            if (str_contains($request->url(), '/token?')) {
                $id = $request['email'] === 'synthetic@example.test' ? $first : $second;

                return Http::response(['access_token' => 'synthetic_access_'.$id, 'refresh_token' => 'synthetic_unused_refresh',
                    'user' => ['id' => $id, 'email' => $request['email']]]);
            }

            return Http::response(['id' => $second, 'email' => 'other@example.test']);
        });
        $remember = function ($response) {
            $cookie = $response->getCookie(config('session.cookie'));
            $this->withCookie($cookie->getName(), $cookie->getValue());

            return $response;
        };
        $firstLogin = $remember($this->post('/login', ['email' => 'synthetic@example.test', 'password' => 'synthetic_password']))
            ->assertSessionHas('user_profile_id', $first)->assertSessionHas('user_role', 'system_admin');
        $secondLogin = $remember($this->post('/login', ['email' => 'other@example.test', 'password' => 'synthetic_password']))
            ->assertRedirect(route('field-officer.dashboard'))->assertSessionHas('user_profile_id', $second)
            ->assertSessionHas('user_role', 'field_officer')->assertSessionMissing('supabase_refresh_token');
        $this->assertNotSame($firstLogin->getCookie(config('session.cookie'))->getValue(), $secondLogin->getCookie(config('session.cookie'))->getValue());
        $this->get('/_tests/authentication-probe')->assertForbidden();
        $store = app('session.store');
        $id = $store->getId();
        $csrf = $store->token();
        $this->post('/logout')->assertRedirect(route('login'))->assertSessionMissing('supabase_access_token')
            ->assertSessionMissing('user_profile_id')->assertSessionMissing('user_role');
        $this->assertSame('', $store->getHandler()->read($id));
        $this->assertNotSame($csrf, $store->token());
    }

    public function test_configured_previous_key_can_read_existing_encrypted_sessions(): void
    {
        $handler = new ArraySessionHandler(120);
        $old = new Encrypter(str_repeat('o', 32), 'AES-256-CBC');
        $store = new EncryptedStore('isolated', $handler, $old);
        $store->start();
        $store->put('supabase_access_token', 'synthetic_previous_key_access');
        $store->save();
        config(['app.previous_keys' => ['base64:'.base64_encode(str_repeat('o', 32))]]);
        $this->app->forgetInstance('encrypter');
        $restored = new EncryptedStore('isolated', $handler, app('encrypter'), $store->getId());
        $restored->start();
        $this->assertSame('synthetic_previous_key_access', $restored->get('supabase_access_token'));
    }

    #[DataProvider('invalidKeys')]
    public function test_missing_or_invalid_application_key_cannot_create_encrypted_sessions(?string $key, string $exception): void
    {
        config(['app.key' => $key]);
        $this->app->forgetInstance('encrypter');
        app('session')->forgetDrivers();
        $this->expectException($exception);
        app('session')->driver();
    }

    public static function invalidKeys(): array
    {
        return [[null, MissingAppKeyException::class], ['base64:'.base64_encode('short'), \RuntimeException::class]];
    }
}
