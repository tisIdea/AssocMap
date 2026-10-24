<?php

namespace App\Services\Reporting;

use App\Exceptions\ReportDatasetLimitException;
use App\Exceptions\ReportingUnavailableException;
use App\Models\UserProfile;
use Illuminate\Database\QueryException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ReportDataset
{
    private int $rows = 0;

    private float $deadline = 0;

    public function __construct(private ReportScope $scope) {}

    private function table(string $table): Builder
    {
        return DB::table($table)->useWritePdo();
    }

    private function component(Builder $query, string $alias): Builder
    {
        return $query->join('saad_activities as sa', 'sa.id', '=', $alias.'.saad_activity_id')
            ->join('saad_subcomponents as sc', 'sc.id', '=', 'sa.subcomponent_id')
            ->join('saad_components as c', 'c.id', '=', 'sc.component_id');
    }

    private function program(Builder $query, string $alias, array $filters): Builder
    {
        if (isset($filters['program_year'])) $query->where($alias.'.program_year', $filters['program_year']);
        if (isset($filters['component_id'])) $query->where('c.id', $filters['component_id']);

        return $query;
    }

    private function dates(Builder $query, string $column, array $filters): Builder
    {
        $year = $filters['year'];
        $startMonth = isset($filters['quarter']) ? ($filters['quarter'] - 1) * 3 + 1 : 1;
        $endMonth = isset($filters['quarter']) ? $startMonth + 2 : 12;

        return $query->whereBetween($column, [sprintf('%04d-%02d-01', $year, $startMonth),
            date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $endMonth)))]);
    }

    private function take(Builder $query, array $columns, string $dataset): array
    {
        if (microtime(true) > $this->deadline) throw new ReportingUnavailableException('dataset_timeout', 'resource_limit', $dataset);
        $limit = (int) config('reporting.max_rows');
        try {
            $records = $query->limit($limit + 1)->get($columns);
        } catch (QueryException $exception) {
            $sqlState = $exception->errorInfo[0] ?? null;
            $sqlState = is_string($sqlState) && preg_match('/\A[A-Z0-9]{5}\z/', $sqlState) ? $sqlState : null;
            throw new ReportingUnavailableException('database_extraction_failed', 'dataset_extraction', $dataset, sqlState: $sqlState);
        }
        $this->rows += $records->count();
        if ($records->count() > $limit || $this->rows > (int) config('reporting.max_total_rows')) {
            Log::notice('Reporting dataset limit reached.', ['stage' => 'resource_limit', 'reason' => 'dataset_row_limit', 'dataset' => $dataset]);
            throw ReportDatasetLimitException::withMessages(['filters' => 'This report is too large. Select a smaller association, project or period.']);
        }

        return $records->map(fn ($record) => (array) $record)->all();
    }

    public function options(UserProfile $actor, array $filters): array
    {
        $associations = $this->scope->associations($actor)->orderBy('a.name')->limit(1001)
            ->get(['a.id', 'a.name', 'mu.id as municipality_id', 'mu.name as municipality_name']);
        if ($associations->count() > 1000) throw new ReportingUnavailableException('options_limit');
        $ids = $this->scope->ids($actor, $filters);
        $projects = $this->table('projects')->whereIn('association_id', $ids)->orderBy('title')
            ->limit(1001)->get(['id', 'title as name', 'association_id']);
        if ($projects->count() > 1000) throw new ReportingUnavailableException('options_limit');
        if (isset($filters['project_id'])) abort_unless($projects->contains('id', $filters['project_id']), 403);
        $products = $this->table('project_products')->whereIn('project_id', $projects->pluck('id'))
            ->when(isset($filters['project_id']), fn ($q) => $q->where('project_id', $filters['project_id']))
            ->orderBy('product_name')->limit(1001)->get(['id', 'product_name as name', 'project_id']);
        if ($products->count() > 1000) throw new ReportingUnavailableException('options_limit');

        $components = collect();
        $programYears = collect();
        $years = $this->table('monitoring_records as mr')->join('projects as p', 'p.id', '=', 'mr.project_id')
            ->whereIn('p.association_id', $ids)->distinct()->pluck('mr.year');
        foreach (['projects' => 'implementation_start_date', 'trainings' => 'start_date', 'program_activity_records' => 'activity_date'] as $table => $date) {
            $base = $this->table($table.' as r')->where(function ($query) use ($table, $ids, $actor, $filters) {
                $query->whereIn('r.association_id', $ids);
                if ($table === 'program_activity_records' && $actor->role->code === 'system_admin'
                    && ! isset($filters['association_id']) && ! isset($filters['municipality_id'])) $query->orWhereNull('r.association_id');
            });
            $components = $components->merge($this->component(clone $base, 'r')->distinct()->get(['c.id', 'c.name']));
            $programYears = $programYears->merge((clone $base)->distinct()->pluck('r.program_year'));
            // Only distinct years are returned, not private activity records.
            $years = $years->merge((clone $base)->selectRaw('EXTRACT(YEAR FROM r.'.$date.')::integer as report_year')
                ->distinct()->pluck('report_year'));
        }
        $years->push((int) now('Asia/Manila')->format('Y'));

        return [
            'associations' => $associations->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'municipality_id' => $a->municipality_id])->all(),
            'municipalities' => $associations->unique('municipality_id')->map(fn ($a) => ['id' => $a->municipality_id, 'name' => $a->municipality_name])->values()->all(),
            'projects' => $projects->all(), 'products' => $products->all(),
            'components' => $components->unique('id')->sortBy('name')->values()->all(),
            'program_years' => $programYears->unique()->sortDesc()->values()->all(),
            'years' => $years->unique()->sortDesc()->values()->all(),
        ];
    }

    public function extract(UserProfile $actor, array $filters, array $ids): array
    {
        $this->rows = 0;
        $this->deadline = microtime(true) + 30;
        $type = $filters['report_type'];
        $all = $type === 'comprehensive';
        $data = array_fill_keys(['associations', 'members', 'classifications', 'projects', 'trainings', 'participants',
            'monitoring', 'production', 'monthly', 'income', 'activities', 'visits'], []);
        $data['associations'] = $this->take($this->scope->selected($actor, $filters)->orderBy('a.id'), [
            'a.id', 'a.name', 'a.status', 'a.archived_at', 'mu.id as municipality_id', 'mu.name as municipality',
            'b.id as barangay_id', 'b.name as barangay',
        ], 'associations');

        if ($all || $type === 'members') {
            $data['members'] = $this->take($this->table('members as m')->whereIn('m.association_id', $ids)->orderBy('m.id'),
                ['m.id', 'm.association_id', 'm.member_status as status', 'm.archived_at'], 'members');
            $data['classifications'] = $this->take($this->table('member_classification_assignments as mca')
                ->join('members as m', 'm.id', '=', 'mca.member_id')->join('member_classifications as mc', 'mc.id', '=', 'mca.classification_id')
                ->whereIn('m.association_id', $ids)->orderBy('m.id')->orderBy('mc.id'), ['mca.member_id', 'mc.id as classification_id', 'mc.name as classification'], 'classifications');
        }

        $projects = $this->program($this->component($this->table('projects as p')->whereIn('p.association_id', $ids), 'p'), 'p', $filters);
        if (isset($filters['project_id'])) {
            abort_unless($this->table('projects as selected_project')->whereIn('selected_project.association_id', $ids)
                ->where('selected_project.id', $filters['project_id'])->exists(), 403);
            $projects->where('p.id', $filters['project_id']);
        }
        if (isset($filters['component_id']) && ! $this->table('saad_components')->where('id', $filters['component_id'])->exists()) {
            throw ValidationException::withMessages(['component_id' => 'Choose a recorded program component.']);
        }
        if ($all || in_array($type, ['projects', 'production', 'income', 'activities'], true)) {
            $data['projects'] = $this->take((clone $projects)->join('fisheries_project_categories as fc', 'fc.id', '=', 'p.fisheries_category_id')->orderBy('p.id'),
                ['p.id', 'p.association_id', 'p.title', 'p.program_year', 'p.status', 'p.archived_at', 'c.name as component', 'fc.name as category'], 'projects');
        }

        if ($all || $type === 'trainings') {
            $training = $this->program($this->component($this->table('trainings as t')->whereIn('t.association_id', $ids), 't'), 't', $filters);
            $this->dates($training, 't.start_date', $filters);
            if (isset($filters['project_id'])) $training->where('t.related_project_id', $filters['project_id']);
            $data['trainings'] = $this->take((clone $training)->orderBy('t.id'),
                ['t.id', 't.association_id', 't.title', 't.program_year', 't.start_date', 't.end_date', 't.status', 't.archived_at', 'c.name as component'], 'trainings');
            $data['participants'] = $this->take($this->table('training_participants as tp')
                ->join('members as m', 'm.id', '=', 'tp.member_id')
                ->join('trainings as t', 't.id', '=', 'tp.training_id')->whereColumn('m.association_id', 't.association_id')
                ->whereIn('tp.training_id', array_column($data['trainings'], 'id'))
                ->whereIn('m.association_id', $ids)->orderBy('tp.id'), ['tp.training_id', 'tp.member_id', 'tp.attendance_status'], 'participants');
        }

        if ($all || in_array($type, ['production', 'income', 'activities'], true)) {
            $records = $this->table('monitoring_records as mr')->join('quarters as q', 'q.id', '=', 'mr.quarter_id')
                ->whereIn('mr.project_id', array_column($data['projects'], 'id'))->where('mr.year', $filters['year']);
            if (isset($filters['quarter'])) $records->where('q.quarter_number', $filters['quarter']);
            if (isset($filters['month'])) $records->where('q.quarter_number', (int) ceil($filters['month'] / 3));
            $data['monitoring'] = $this->take($records->orderBy('mr.id'),
                ['mr.id', 'mr.project_id', 'mr.year', 'q.quarter_number as quarter', 'mr.monitoring_date', 'mr.project_status'], 'monitoring');
        }

        if ($all || in_array($type, ['production', 'income'], true)) {
            $production = $this->table('monitoring_production as mp')->join('project_products as pp', 'pp.id', '=', 'mp.project_product_id')
                ->join('monitoring_records as production_record', 'production_record.id', '=', 'mp.monitoring_record_id')
                ->whereColumn('pp.project_id', 'production_record.project_id')
                ->whereIn('mp.monitoring_record_id', array_column($data['monitoring'], 'id'));
            if (isset($filters['product_id'])) {
                abort_unless($this->table('project_products as pp')->whereIn('pp.project_id', $projects->select('p.id'))
                    ->where('pp.id', $filters['product_id'])->exists(), 403);
                $production->where('mp.project_product_id', $filters['product_id']);
            }
            $data['production'] = $this->take($production->orderBy('mp.id'), ['mp.id', 'mp.monitoring_record_id', 'mp.project_product_id',
                'pp.product_name as product', 'mp.target_output', DB::raw("CASE WHEN mp.quantity_source = 'daily_harvest'
                    AND NOT EXISTS (SELECT 1 FROM monitoring_harvest_entries h
                        WHERE h.monitoring_production_id = mp.id AND h.voided_at IS NULL)
                    THEN NULL ELSE mp.actual_output END AS actual_output"), 'mp.unit'], 'production');
            $productionIds = array_column($data['production'], 'id');
            if ($all || $type === 'production') {
                $data['monthly'] = $this->take($this->table('monitoring_production_monthly as monthly')
                    ->join('monitoring_production as parent', 'parent.id', '=', 'monthly.monitoring_production_id')
                    ->whereIn('monthly.monitoring_production_id', $productionIds)
                    ->where(fn ($q) => $q->where('parent.quantity_source', 'aggregate')->orWhere('monthly.activity_count', '>', 0))
                    ->when(isset($filters['month']), fn ($q) => $q->where('monthly.month', $filters['month']))->orderBy('monthly.id'),
                    ['monthly.monitoring_production_id as production_id', 'monthly.month', 'monthly.activity_count', 'monthly.total_quantity'], 'monthly');
            }
            if ($all || $type === 'income') {
                // Keep quarterly rows for coverage notices even when a month is requested.
                $data['income'] = $this->take($this->table('monitoring_income')->whereIn('monitoring_production_id', $productionIds)->orderBy('id'),
                    ['id', 'monitoring_production_id as production_id', 'period_type', 'month', 'gross_income'], 'income');
            }
        }

        if ($all || $type === 'activities') {
            $activities = $this->component($this->table('program_activity_records as act'), 'act');
            $activities->where(function ($q) use ($ids, $actor, $filters) {
                $q->whereIn('act.association_id', $ids);
                if ($actor->role->code === 'system_admin' && ! isset($filters['association_id']) && ! isset($filters['municipality_id'])) {
                    $q->orWhereNull('act.association_id');
                }
            });
            $this->program($activities, 'act', $filters);
            $this->dates($activities, 'act.activity_date', $filters);
            if (isset($filters['project_id'])) $activities->where('act.related_project_id', $filters['project_id']);
            $data['activities'] = $this->take($activities->orderBy('act.id'), ['act.id', 'act.association_id', 'act.title', 'act.program_year',
                'act.activity_date', 'act.status', 'act.archived_at', 'c.name as component'], 'activities');
            $data['visits'] = $this->take($this->table('monitoring_visit_reports')->whereIn('monitoring_record_id', array_column($data['monitoring'], 'id'))->orderBy('id'),
                ['id', 'monitoring_record_id as record_id', 'monitoring_date', 'is_legacy_import'], 'visits');
        }

        return $data;
    }
}
