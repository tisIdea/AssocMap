<?php

namespace Tests\Unit;

use App\Services\MemberExecutivePositionService;
use Illuminate\Database\QueryException;
use PDOException;
use Tests\TestCase;

class MemberExecutivePositionServiceTest extends TestCase
{
    public function test_recognizes_only_the_named_executive_unique_index(): void
    {
        $service = new MemberExecutivePositionService;

        $this->assertTrue($service->isExecutiveConflict($this->exception('23505',
            'ERROR: duplicate key value violates unique constraint "uq_members_active_executive_position"')));
        $this->assertFalse($service->isExecutiveConflict($this->exception('23505',
            'ERROR: duplicate key value violates unique constraint "uq_members_fishr_registration_number"')));
        $this->assertFalse($service->isExecutiveConflict($this->exception('23505',
            'ERROR: duplicate key value violates unique constraint "members_source_application_id_key"')));
    }

    public function test_requires_unique_violation_sqlstate(): void
    {
        $service = new MemberExecutivePositionService;

        foreach (['23503', '42501', '40001', 'P0001'] as $state) {
            $this->assertFalse($service->isExecutiveConflict($this->exception($state,
                'ERROR: constraint "uq_members_active_executive_position" failed')));
        }
    }

    public function test_detail_values_and_sql_bindings_cannot_misclassify_an_unrelated_error(): void
    {
        $exception = $this->exception('23505',
            "ERROR: duplicate key value violates unique constraint \"uq_members_fishr_registration_number\"\n"
            ."DETAIL: Key (fishr_registration_number)=(\"uq_members_active_executive_position\") already exists.");

        $this->assertFalse((new MemberExecutivePositionService)->isExecutiveConflict($exception));
        $this->assertFalse((new MemberExecutivePositionService)->isExecutiveConflict($this->exception('23505',
            'ERROR: duplicate key violation without a constraint identifier')));
    }

    public function test_recognizes_the_identifier_in_a_localized_driver_diagnostic(): void
    {
        $this->assertTrue((new MemberExecutivePositionService)->isExecutiveConflict($this->exception('23505',
            'ERROR: doppelter Schlüssel verletzt Unique-Constraint "uq_members_active_executive_position"')));
    }

    private function exception(string $state, string $diagnostic): QueryException
    {
        $previous = new PDOException($diagnostic);
        $previous->errorInfo = [$state, 7, $diagnostic];

        return new QueryException('pgsql', 'insert into members (fishr_registration_number) values (?)',
            ['uq_members_active_executive_position'], $previous);
    }
}
