<?php

namespace Tests\Unit;

use App\Services\Auth\SupabaseAuthService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvitationConfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.supabase.url' => 'https://project.example.test',
            'services.supabase.invitation_redirect_url' => 'https://application.example.test/invitation',
            'services.supabase.service_role_key' => 'synthetic.server.signature']);
        Http::preventStrayRequests();
    }

    public function test_supported_configuration_passes_without_a_provider_request(): void
    {
        $service = app(SupabaseAuthService::class);
        $this->assertTrue($service->invitationConfigurationAvailable());
        $this->assertSame([], $service->invitationConfigurationProblems());
        Http::assertNothingSent();
    }

    #[DataProvider('invalidSettings')]
    public function test_each_failed_configuration_check_identifies_the_setting_without_its_value(string $setting,
        mixed $value, string $name): void
    {
        config(['services.supabase.'.$setting => $value]);
        $service = app(SupabaseAuthService::class);
        $this->assertFalse($service->invitationConfigurationAvailable());
        $problems = implode(' ', $service->invitationConfigurationProblems());
        $this->assertStringContainsString($name, $problems);
        if (is_string($value) && $value !== '') $this->assertStringNotContainsString($value, $problems);
        Http::assertNothingSent();
    }

    public static function invalidSettings(): array
    {
        return [
            'missing URL' => ['url', null, 'SUPABASE_URL'],
            'HTTP project URL' => ['url', 'http://project.example.test', 'SUPABASE_URL'],
            'API path' => ['url', 'https://project.example.test/rest/v1/', 'SUPABASE_URL'],
            'project query' => ['url', 'https://project.example.test/?private=synthetic', 'SUPABASE_URL'],
            'embedded credentials' => ['url', 'https://synthetic:withheld@project.example.test', 'SUPABASE_URL'],
            'HTTP redirect' => ['invitation_redirect_url', 'http://application.example.test/invitation', 'SUPABASE_INVITATION_REDIRECT_URL'],
            'redirect fragment' => ['invitation_redirect_url', 'https://application.example.test/invitation#private', 'SUPABASE_INVITATION_REDIRECT_URL'],
            'missing legacy key' => ['service_role_key', null, 'SUPABASE_SERVICE_ROLE_KEY'],
            'unsupported modern key' => ['service_role_key', 'sb_secret_synthetic_test_only', 'SUPABASE_SERVICE_ROLE_KEY'],
            'malformed legacy key' => ['service_role_key', 'synthetic-invalid-key', 'SUPABASE_SERVICE_ROLE_KEY'],
        ];
    }

    public function test_activation_cache_selection_does_not_generate_invitation_configuration_failure(): void
    {
        config(['services.supabase.activation_cache_store' => 'array']);
        $this->assertTrue(app(SupabaseAuthService::class)->invitationConfigurationAvailable());
        Http::assertNothingSent();
    }
}
