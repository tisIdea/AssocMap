<?php

namespace App\Services\Reporting;

use App\Exceptions\ReportingUnavailableException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use JsonException;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class PythonReportEngine
{
    public function run(array $payload, string $actorId): array
    {
        $python = config('reporting.python');
        if (! is_string($python) || ! $this->absolute($python) || ! is_file($python)) {
            throw new ReportingUnavailableException('python_not_configured', 'python_process');
        }
        try {
            $input = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ReportingUnavailableException('input_serialization_failed', 'input_contract');
        }
        $max = (int) config('reporting.max_json_bytes');
        if (strlen($input) > $max) throw new ReportingUnavailableException('input_limit', 'resource_limit');
        $timeout = (int) config('reporting.timeout');
        $lock = Cache::lock('reports:user:'.$actorId, $timeout + 30);
        if (! $lock->get()) {
            Log::notice('Reporting concurrency limit.', ['stage' => 'concurrency_limit', 'reason' => 'user_slot_busy']);
            abort(429);
        }
        $slot = null;
        $workspace = null;
        $process = null;
        try {
            for ($i = 0; $i < (int) config('reporting.concurrency'); $i++) {
                $candidate = Cache::lock('reports:slot:'.$i, $timeout + 30);
                if ($candidate->get()) { $slot = $candidate; break; }
            }
            if (! $slot) {
                Log::notice('Reporting concurrency limit.', ['stage' => 'concurrency_limit', 'reason' => 'global_slots_busy']);
                abort(429);
            }
            $root = storage_path('app/private/reporting');
            File::ensureDirectoryExists($root, 0700, true);
            if (is_link($root)) throw new ReportingUnavailableException('unsafe_workspace');
            $workspace = $root.DIRECTORY_SEPARATOR.Str::uuid();
            if (! mkdir($workspace, 0700)) throw new ReportingUnavailableException('workspace_unavailable');

            $environment = array_fill_keys(array_unique(array_merge(array_keys(getenv()), array_keys($_ENV), array_keys($_SERVER))), false);
            foreach (['SystemRoot', 'SYSTEMROOT', 'WINDIR'] as $key) {
                if (getenv($key) !== false) $environment[$key] = getenv($key);
            }
            foreach (['TEMP', 'TMP', 'TMPDIR'] as $key) $environment[$key] = $workspace;
            $environment['ASSOCMAP_REPORT_FONT_REGULAR'] = (string) config('reporting.font_regular');
            $environment['ASSOCMAP_REPORT_FONT_BOLD'] = (string) config('reporting.font_bold');
            $entry = dirname(base_path()).DIRECTORY_SEPARATOR.'reporting'.DIRECTORY_SEPARATOR.'python'.DIRECTORY_SEPARATOR.'main.py';
            $process = new Process([$python, '-I', '-B', '-X', 'utf8', $entry], $workspace, $environment, $input, $timeout);
            $bytes = 0;
            try {
                $process->run(function ($type, $buffer) use (&$bytes, $max) {
                    $bytes += strlen($buffer);
                    if ($bytes > $max) throw new ReportingUnavailableException('output_limit', 'resource_limit');
                });
            } catch (ProcessTimedOutException $exception) {
                throw new ReportingUnavailableException('python_timeout', 'resource_limit');
            } catch (ProcessException $exception) {
                throw new ReportingUnavailableException('python_process_failed', 'python_process');
            }
            try {
                $output = json_decode($process->getOutput(), true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                if (! $process->isSuccessful()) throw new ReportingUnavailableException('python_process_failed', 'python_process');
                throw new ReportingUnavailableException('invalid_engine_json', 'result_contract');
            }
            if (! $process->isSuccessful()) {
                $error = is_array($output) ? ($output['error'] ?? null) : null;
                $stages = ['input_contract', 'calculation', 'export', 'serialization', 'resource_limit'];
                $codes = ['input_contract_failed', 'calculation_failed', 'export_failed', 'serialization_failed',
                    'input_limit', 'output_limit', 'artifact_limit', 'dependency_unavailable', 'font_unavailable',
                    'font_missing_glyph', 'xlsx_numeric_precision_limit'];
                if (is_array($output) && ($output['version'] ?? null) === ReportCatalog::VERSION && ($output['ok'] ?? null) === false
                    && is_array($error) && in_array($error['stage'] ?? null, $stages, true)
                    && in_array($error['code'] ?? null, $codes, true)) {
                    // Accept names only from the server-selected input shape, never arbitrary process text.
                    $dataset = is_string($error['dataset'] ?? null) && array_key_exists($error['dataset'], $payload['datasets']) ? $error['dataset'] : null;
                    $fields = $dataset === null ? [] : array_keys($payload['datasets'][$dataset][0] ?? []);
                    $field = in_array($error['field'] ?? null, $fields, true) ? $error['field'] : null;
                    throw new ReportingUnavailableException($error['code'], $error['stage'], $dataset, $field);
                }
                throw new ReportingUnavailableException('engine_failed', 'python_process');
            }
            if (! is_array($output) || ($output['ok'] ?? null) !== true
                || ($output['version'] ?? null) !== ReportCatalog::VERSION) {
                throw new ReportingUnavailableException('invalid_engine_response');
            }
            $result = $output['result'] ?? null;
            try {
                $this->validateResult($result, $payload['meta']);
            } catch (ReportingUnavailableException $exception) {
                throw new ReportingUnavailableException($exception->getMessage(), $exception->stage ?? 'result_contract', field: $exception->field);
            }
            foreach ($result['sections'] as &$section) {
                if ($section['chart'] === []) $section['chart'] = (object) [];
            }
            unset($section);
            $artifact = null;
            if ($payload['action'] !== 'analytics') {
                $name = 'report.'.$payload['action'];
                $path = $workspace.DIRECTORY_SEPARATOR.$name;
                if (($output['artifact']['name'] ?? null) !== $name || is_link($path)
                    || ! is_file($path) || dirname((string) realpath($path)) !== realpath($workspace)) {
                    throw new ReportingUnavailableException('invalid_artifact');
                }
                $size = filesize($path);
                if ($size < 1 || $size > (int) config('reporting.max_artifact_bytes') || ($output['artifact']['size'] ?? null) !== $size) {
                    throw new ReportingUnavailableException('artifact_limit', 'resource_limit');
                }
                $artifact = file_get_contents($path);
                if (! is_string($artifact) || strlen($artifact) !== $size
                    || ! str_starts_with($artifact, $payload['action'] === 'pdf' ? '%PDF-' : "PK\x03\x04")) {
                    throw new ReportingUnavailableException('invalid_artifact');
                }
            }

            return ['result' => $result, 'artifact' => $artifact];
        } finally {
            try {
                if ($process?->isRunning()) $process->stop(0);
                // Only this server-created UUID workspace is ever removed.
                if ($workspace !== null && is_dir($workspace) && ! is_link($workspace)) {
                    File::deleteDirectory($workspace);
                    clearstatcache(true, $workspace);
                    if (is_dir($workspace)) \Illuminate\Support\Facades\Log::warning('Report workspace cleanup incomplete.', ['code' => 'report_cleanup_failed']);
                }
            } finally {
                $slot?->release();
                $lock->release();
            }
        }
    }

    private function absolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function validateResult(mixed $result, array $meta): void
    {
        if (! is_array($result) || ($result['meta'] ?? null) !== $meta) throw new ReportingUnavailableException('invalid_metadata');
        if (array_diff(array_keys($result), ['version', 'title', 'empty', 'notes', 'sections', 'meta'])
            || ! is_bool($result['empty'] ?? null)) throw new ReportingUnavailableException('invalid_result_shape');
        $validator = Validator::make($result, [
            'version' => ['required', 'in:'.ReportCatalog::VERSION],
            'title' => ['required', 'string', 'max:150'],
            'empty' => ['required', 'boolean'],
            'notes' => ['present', 'array', 'max:50'],
            'notes.*' => ['string', 'max:2000'],
            'sections' => ['present', 'array', 'max:25'],
            'sections.*.key' => ['required', 'string', 'regex:/\A[a-z_]+\z/'],
            'sections.*.title' => ['required', 'string', 'max:150'],
            'sections.*.columns' => ['required', 'array', 'min:1', 'max:12'],
            'sections.*.columns.*.key' => ['required', 'string', 'regex:/\A[a-z_]+\z/'],
            'sections.*.columns.*.label' => ['required', 'string', 'max:100'],
            'sections.*.columns.*.type' => ['required', 'in:text,integer,decimal,date'],
            'sections.*.rows' => ['present', 'array', 'max:5000'],
            'sections.*.measures' => ['present', 'array', 'max:15'],
            'sections.*.measures.*.label' => ['required', 'string', 'max:150'],
            'sections.*.measures.*.value' => ['present'],
            'sections.*.notes' => ['present', 'array', 'max:30'],
            'sections.*.notes.*' => ['string', 'max:2000'],
            'sections.*.chart' => ['present', 'array:label_key,value_key,unit,title'],
            'sections.*.chart.title' => ['sometimes', 'string', 'max:150'],
            'sections.*.chart.unit' => ['sometimes', 'string', 'max:80'],
        ]);
        if ($validator->fails()) throw new ReportingUnavailableException('invalid_result', 'result_contract', field: array_key_first($validator->failed()));
        $count = 0;
        foreach ($result['sections'] as $section) {
            if (array_diff(array_keys($section), ['key', 'title', 'columns', 'rows', 'measures', 'notes', 'chart'])) {
                throw new ReportingUnavailableException('invalid_section');
            }
            $keys = array_column($section['columns'], 'key');
            if (count($keys) !== count(array_unique($keys))) throw new ReportingUnavailableException('duplicate_column');
            $count += count($section['rows']);
            foreach ($section['rows'] as $row) {
                if (! is_array($row) || array_diff(array_keys($row), array_column($section['columns'], 'key'))) {
                    throw new ReportingUnavailableException('invalid_row');
                }
                foreach ($section['columns'] as $column) {
                    if (! array_key_exists($column['key'], $row)) throw new ReportingUnavailableException('missing_cell');
                    $value = $row[$column['key']];
                    if ($value === null) continue;
                    $valid = match ($column['type']) {
                        'integer' => is_int($value) && $value >= 0,
                        'decimal' => is_string($value) && preg_match('/\A\d{1,18}\.\d{2}\z/', $value),
                        'date' => is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value),
                        default => is_string($value) && mb_strlen($value) <= 1000,
                    };
                    if (! $valid) throw new ReportingUnavailableException('invalid_cell');
                }
            }
            foreach ($section['measures'] as $measure) {
                $value = $measure['value'];
                if ($value !== null && ! (is_int($value) && $value >= 0)
                    && ! (is_string($value) && preg_match('/\A\d{1,18}\.\d{2}\z/', $value))) {
                    throw new ReportingUnavailableException('invalid_measure');
                }
            }
            if ($section['chart'] && (! in_array($section['chart']['label_key'] ?? null, array_column($section['columns'], 'key'), true)
                || ! in_array($section['chart']['value_key'] ?? null, array_column($section['columns'], 'key'), true))) {
                throw new ReportingUnavailableException('invalid_chart');
            }
            if ($section['chart']) {
                $columns = array_column($section['columns'], 'type', 'key');
                if (! in_array($columns[$section['chart']['value_key']], ['integer', 'decimal'], true)) {
                    throw new ReportingUnavailableException('invalid_chart_value');
                }
            }
        }
        if ($count > 20000) throw new ReportingUnavailableException('result_limit', 'resource_limit');
    }
}
