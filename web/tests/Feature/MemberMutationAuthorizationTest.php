<?php

namespace Tests\Feature;

use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\IsolatedDatabaseManager;
use Tests\Support\MemberMutationFixture;
use Tests\TestCase;

class MemberMutationAuthorizationTest extends TestCase
{
    use MemberMutationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertInstanceOf(IsolatedDatabaseManager::class, DB::getFacadeRoot());
        Http::swap((new Factory)->preventStrayRequests());
        $this->sec2Schema();
        $this->sec2Fixtures();
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
            parent::tearDown();
        } finally {
            HandleExceptions::flushState($this);
        }
    }

    #[DataProvider('sec2Operations')]
    public function test_valid_mutation_and_query_budget(string $operation, string $role): void
    {
        $context = $this->sec2Context($operation);
        $actor = $this->sec2Actor($role);
        $before = $this->sec2State();
        $transactionQueries = [];
        DB::listen(function ($query) use (&$transactionQueries) {
            if (DB::transactionLevel() > 0) {
                $transactionQueries[] = $query->sql;
            }
        });
        DB::enableQueryLog();
        $this->sec2Mutate($operation, $actor, $context);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertNotEquals($before, $this->sec2State());
        $budget = ['update' => 7, 'archive' => 8, 'unarchive' => 12, 'approve' => 15, 'reject' => 6,
            'assign' => 10, 'replace' => 13, 'remove' => 9, 'submit' => 11];
        $this->assertCount($budget[$operation], $queries, 'Retain the measured pre-SEC-2 service query count.');
        $this->assertCount($budget[$operation] - ($operation === 'submit' ? 2 : 0), $transactionQueries);
        $this->assertCount(1, array_filter($transactionQueries,
            fn ($sql) => str_contains($sql, 'from "user_invitations"')), 'Eligibility is queried once and reused by audit.');
    }

    public static function deniedActors(): array
    {
        $cases = [];
        foreach (self::sec2Operations() as $name => [$operation, $role]) {
            foreach (['revoked', 'inactive_role', 'changed_role', 'cutoff', 'email_hold', 'invitation_hold'] as $state) {
                $cases[$name.' '.$state] = [$operation, $role, $state];
            }
        }

        return $cases;
    }

    #[DataProvider('deniedActors')]
    public function test_stale_authenticated_actor_is_denied_without_business_or_audit_changes(
        string $operation, string $role, string $state,
    ): void {
        $context = $this->sec2Context($operation);
        $actor = $this->sec2Actor($role);
        $this->sec2Change($role, $state);
        $before = $this->sec2State();
        try {
            $this->sec2Mutate($operation, $actor, $context);
            $this->fail('A stale authenticated actor must be denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($before, $this->sec2State());
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function deniedParents(): array
    {
        $cases = [];
        foreach (self::sec2Operations() as $name => [$operation, $role]) {
            foreach (['archived', 'inactive', ...($role === 'officer' ? ['reassigned'] : []),
                ...($role === 'account' ? ['unlinked'] : [])] as $state) {
                $cases[$name.' '.$state] = [$operation, $role, $state];
            }
        }

        return $cases;
    }

    #[DataProvider('deniedParents')]
    public function test_current_parent_or_assignment_is_required_without_partial_writes(
        string $operation, string $role, string $state,
    ): void {
        $context = $this->sec2Context($operation);
        $actor = $this->sec2Actor($role);
        $this->sec2Change($role, $state);
        $before = $this->sec2State();
        try {
            $this->sec2Mutate($operation, $actor, $context);
            $this->fail('An ineligible parent or association link must be denied.');
        } catch (HttpException|ValidationException $exception) {
            $this->assertContains($exception instanceof HttpException ? $exception->getStatusCode() : $exception->status, [403, 422]);
        }
        $this->assertSame($before, $this->sec2State());
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function forbiddenRoles(): array
    {
        $cases = [];
        foreach (['update', 'archive', 'unarchive', 'approve', 'reject'] as $operation) {
            $cases[$operation.' admin'] = [$operation, 'admin', null];
            $cases[$operation.' account'] = [$operation, 'account', null];
        }
        foreach (['assign', 'replace', 'remove'] as $operation) {
            $cases[$operation.' account promoted'] = [$operation, 'account', 1];
            $cases[$operation.' admin demoted'] = [$operation, 'admin', 2];
        }

        return $cases;
    }

    #[DataProvider('forbiddenRoles')]
    public function test_staff_workflows_preserve_the_authenticated_role_and_field_officer_only_boundaries(
        string $operation, string $role, ?int $newRole,
    ): void {
        $context = $this->sec2Context($operation);
        $actor = $this->sec2Actor($role);
        DB::table('associations')->where('id', self::ASSOCIATION)->update(['primary_field_officer_id' => $actor->id]);
        if ($newRole !== null) {
            DB::table('user_profiles')->where('id', $actor->id)->update(['role_id' => $newRole]);
        }
        $before = $this->sec2State();
        try {
            $this->sec2Mutate($operation, $actor, $context);
            $this->fail('A different authenticated role must be denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($before, $this->sec2State());
    }

    public static function reparentedRecords(): array
    {
        return array_map(fn ($operation) => [$operation], ['update', 'archive', 'unarchive', 'approve', 'reject']);
    }

    #[DataProvider('reparentedRecords')]
    public function test_reparented_records_are_rejected_using_the_locked_current_association(string $operation): void
    {
        $context = $this->sec2Context($operation);
        $actor = $this->sec2Actor('officer');
        $table = in_array($operation, ['approve', 'reject'], true) ? 'member_applications' : 'members';
        DB::table($table)->where('id', $table === 'members' ? self::CANDIDATE : self::APPLICATION)
            ->update(['association_id' => self::OTHER_ASSOCIATION]);
        $before = $this->sec2State();
        try {
            $this->sec2Mutate($operation, $actor, $context);
            $this->fail('A reparented record must be reloaded.');
        } catch (ValidationException $exception) {
            $this->assertSame(409, $exception->status);
        }
        $this->assertSame($before, $this->sec2State());
    }
}
