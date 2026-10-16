<?php

namespace App\Services\Reporting;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Exceptions\ReportingUnavailableException;

class ReportService
{
    public function __construct(private ReportScope $scope, private ReportDataset $datasets, private PythonReportEngine $engine) {}

    public function options(Request $request, array $filters): array
    {
        $request->attributes->set('reporting_stage', 'filter_options');
        $actor = $this->scope->actor($request);
        $ids = $this->scope->ids($actor, []);
        $options = $this->datasets->options($actor, $filters);
        $this->scope->recheck($request, [], $ids);

        return $options;
    }

    public function generate(Request $request, array $filters, string $action = 'analytics'): array
    {
        $request->attributes->set('reporting_stage', 'scope_resolution');
        abort_unless(in_array($action, ['analytics', 'pdf', 'xlsx'], true), 404);
        $actor = $this->scope->actor($request, $action !== 'analytics');
        $ids = $this->scope->ids($actor, $filters);
        if (DB::connection()->getDriverName() !== 'pgsql') throw new ReportingUnavailableException('reporting_requires_postgresql');
        $request->attributes->set('reporting_stage', 'dataset_extraction');
        $datasets = DB::transaction(function () use ($actor, $filters, $ids) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            DB::statement("SET LOCAL statement_timeout = '10000ms'");
            abort_unless($this->scope->ids($actor, $filters) === $ids, 403);

            return $this->datasets->extract($actor, $filters, $ids);
        });
        $request->attributes->set('reporting_stage', 'python_engine');
        $result = $this->engine->run([
            'version' => ReportCatalog::VERSION, 'action' => $action,
            'meta' => [
                'report_type' => $filters['report_type'], 'filters' => $filters,
                'generated_at' => now('Asia/Manila')->toIso8601String(), 'timezone' => 'Asia/Manila',
                'scope' => array_column($datasets['associations'], 'name'),
                'scope_basis' => match ($actor->role->code) {
                    'system_admin' => 'System-wide authorized associations; archived associations included. Unlinked program activities appear only without geographic filters.',
                    'field_officer' => 'Currently assigned active, unarchived associations.',
                    default => 'Current active linked association only.',
                },
                'currency' => 'PHP',
            ],
            'datasets' => $datasets,
        ], $actor->id);
        $request->attributes->set('reporting_stage', 'scope_recheck');
        $this->scope->recheck($request, $filters, $ids, $action !== 'analytics');

        return $result;
    }
}
