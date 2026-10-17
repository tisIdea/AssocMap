<?php

namespace Tests\Feature;

use App\Services\SupabaseAuthService;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GlobalFeedbackTest extends TestCase
{
    use \Tests\Support\MonitoringIncomeFixture;

    private string $identity = self::ADMIN;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        Http::swap((new Factory)->preventStrayRequests());
        $this->withoutVite();
        $this->schema();
        $this->fixtures();
        View::addNamespace('feedback-tests', base_path('tests/Fixtures'));
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->andReturnUsing(fn () => ['id' => $this->identity]);
        $this->app->instance(SupabaseAuthService::class, $auth);
        $this->withSession(['supabase_access_token' => 'synthetic-token']);
        foreach (self::roles() as [$identity, $prefix, $role, $layout]) {
            Route::get('/'.$prefix.'/_feedback-test', function () use ($layout) {
                $catalog = ['modules' => [], 'options' => []];
                foreach (['users', 'areas', 'associations', 'members', 'projects', 'trainings', 'monitoring', 'program-activities'] as $key) {
                    $catalog['modules'][$key] = ['title' => $key, 'icon' => 'grid', 'rows' => []];
                }
                return view('feedback-tests::global-feedback', compact('layout', 'catalog'));
            })->middleware(['web', 'supabase.auth', 'role:'.$role]);
            Route::post('/'.$prefix.'/_feedback-flash', fn () => redirect('/'.$prefix.'/_feedback-test')
                ->with(request()->boolean('failure') ? 'error' : 'success', request()->boolean('failure') ? 'Synthetic operation failed.' : 'Synthetic operation saved.'))
                ->middleware(['web', 'supabase.auth', 'role:'.$role]);
        }
        Route::get('/admin/_feedback-failure/{status}', function (int $status) {
            abort($status, 'SYNTHETIC PRIVATE DATABASE DETAILS', ['Retry-After' => '12']);
        })->middleware('web');
        Route::get('/_test/feedback-association', fn () => view('admin.associations.index', [
            'module' => ['key' => 'associations', 'title' => 'Associations', 'description' => '', 'filters' => [], 'columns' => [], 'rows' => []],
            'catalog' => ['options' => ['area_records' => [], 'field_officer_records' => [], 'registration_agencies' => []]],
        ]))->middleware('web');
    }

    protected function tearDown(): void
    {
        try { Http::assertNothingSent(); parent::tearDown(); }
        finally { HandleExceptions::flushState($this); }
    }

    public static function roles(): array
    {
        return [
            'Admin' => [self::ADMIN, 'admin', 'system_admin', 'layouts.admin'],
            'Field Officer' => [self::OFFICER, 'field-officer', 'field_officer', 'layouts.field-officer'],
            'Association Account' => [self::ACCOUNT, 'association', 'association_account', 'layouts.association'],
        ];
    }

    private function payload(string $html): array
    {
        $this->assertSame(1, substr_count($html, 'id="global-feedback-modal"'));
        preg_match('/<script[^>]*data-feedback-payload[^>]*>(.*?)<\/script>/s', $html, $match);
        return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    }

    #[DataProvider('roles')]
    public function test_each_role_receives_one_flash_result_without_replay(string $identity, string $prefix): void
    {
        $this->identity = $identity;
        $this->post('/'.$prefix.'/_feedback-flash')->assertRedirect('/'.$prefix.'/_feedback-test');
        $first = $this->payload($this->get('/'.$prefix.'/_feedback-test')->assertOk()->getContent());
        $this->assertSame('success', $first['notification']['type']);
        $this->assertSame('Synthetic operation saved.', $first['notification']['message']);
        $this->assertNull($this->payload($this->get('/'.$prefix.'/_feedback-test')->assertOk()->getContent())['notification']);
        $this->post('/'.$prefix.'/_feedback-flash');
        $second = $this->payload($this->get('/'.$prefix.'/_feedback-test')->assertOk()->getContent());
        $this->assertNotSame($first['notification']['id'], $second['notification']['id']);
        $this->post('/'.$prefix.'/_feedback-flash', ['failure' => true]);
        $failure = $this->payload($this->get('/'.$prefix.'/_feedback-test')->assertOk()->getContent());
        $this->assertSame('error', $failure['notification']['type']);
        $this->assertSame('Synthetic operation failed.', $failure['notification']['message']);
        $this->assertNull($this->payload($this->get('/'.$prefix.'/_feedback-test')->assertOk()->getContent())['notification']);
    }

    #[DataProvider('roles')]
    public function test_error_and_named_validation_are_shared_without_losing_draft(string $identity, string $prefix): void
    {
        $this->identity = $identity;
        $bags = (new ViewErrorBag)->put('monitoringVisit', new MessageBag(['remarks' => ['Synthetic field detail.']]));
        $page = $this->withSession(['error' => '<script>synthetic</script>', 'success' => 'stale success',
            'errors' => $bags, '_old_input' => ['remarks' => 'Unsaved draft']])->get('/'.$prefix.'/_feedback-test')->assertOk();
        $page->assertSee('value="Unsaved draft"', false)->assertDontSee('<script>synthetic</script>', false);
        $payload = $this->payload($page->getContent());
        $this->assertSame('error', $payload['notification']['type']);
        $this->assertSame('<script>synthetic</script>', $payload['notification']['message']);
        $this->assertSame(['remarks' => ['Synthetic field detail.']], $payload['errors']['monitoringVisit']);
        $this->assertArrayNotHasKey('default', $payload['errors']);
        $this->assertStringNotContainsString('data-area-validation', $page->getContent());
    }

    public function test_validation_takes_priority_over_success_and_invitation_result_is_owned_once(): void
    {
        $errors = (new ViewErrorBag)->put('projectTermination', new MessageBag(['closure_remarks' => 'Required.']));
        $payload = $this->payload($this->withSession(['success' => 'stale', 'errors' => $errors])->get('/admin/_feedback-test')->assertOk()->getContent());
        $this->assertSame('Review Your Entries', $payload['notification']['title']);
        $this->withSession(['errors' => new ViewErrorBag, 'success' => null, 'invitation_result' => [
            'code' => 'provider_accepted', 'message' => 'Request accepted; activation is still required.',
        ]]);
        $payload = $this->payload($this->get('/admin/_feedback-test')->assertOk()->getContent());
        $this->assertSame('Invitation Request Accepted', $payload['notification']['title']);
    }

    public static function statuses(): array
    {
        return array_map(fn ($status) => [$status], [403, 419, 429, 500, 503]);
    }

    #[DataProvider('statuses')]
    public function test_safe_html_errors_keep_status_headers_and_hide_internal_details(int $status): void
    {
        $page = $this->get('/admin/_feedback-failure/'.$status)->assertStatus($status)->assertHeader('Retry-After', '12')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDontSee('SYNTHETIC PRIVATE DATABASE DETAILS', false);
        $this->assertSame('error', $this->payload($page->getContent())['notification']['type']);
    }

    public function test_json_failures_and_wrong_role_are_still_rejected(): void
    {
        $this->getJson('/admin/_feedback-failure/403')->assertForbidden()->assertDontSee('data-global-feedback', false);
        $this->identity = self::ACCOUNT;
        $this->get('/admin/_feedback-test')->assertForbidden();
    }

    public function test_association_marker_restores_only_its_own_input_and_errors(): void
    {
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['name' => 'Synthetic name error.']));
        foreach (['area_form', 'association_form'] as $marker) {
            $page = $this->withSession(['errors' => $errors, '_old_input' => [$marker => 'editor',
                'name' => 'Synthetic submitted name', 'registration' => ['remarks' => 'Synthetic registration draft']]])
                ->get('/_test/feedback-association')->assertOk();
            if ($marker === 'association_form') {
                $page->assertSee('data-has-errors="true"', false)->assertSee('data-association-validation', false)
                    ->assertSee('Synthetic submitted name')->assertSee('Synthetic registration draft')
                    ->assertSee('data-feedback-error-bag="default"', false);
            } else {
                $page->assertSee('data-has-errors="false"', false)->assertDontSee('data-association-validation', false)
                    ->assertDontSee('Synthetic submitted name')->assertDontSee('Synthetic registration draft');
            }
        }
    }

    public function test_feedback_templates_compile_to_valid_php(): void
    {
        foreach (['components/global-feedback', 'errors/feedback', 'reports/error', 'reports/page',
            'admin/reports/index', 'admin/audit-logs/error', 'admin/audit-logs/index', 'admin/users/register',
            'admin/associations/index', 'admin/areas/modals', 'layouts/admin', 'layouts/field-officer', 'layouts/association',
            'field-officer/members/index', 'field-officer/member-applications/index', 'association/member-applications/index',
            'associations/officers', 'monitoring/partials/editor-script', 'projects/partials/finalization-modal'] as $template) {
            $compiled = app('blade.compiler')->compileString(file_get_contents(resource_path('views/'.$template.'.blade.php')));
            $this->assertNotEmpty(token_get_all($compiled, TOKEN_PARSE), $template);
        }
    }
}
