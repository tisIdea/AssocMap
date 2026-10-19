<?php

namespace App\Services;

use App\Models\MonitoringProduction;
use App\Models\MonitoringRecord;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductionMonitoringService
{
    public function monthsForQuarter(int $quarterNumber): array
    {
        if ($quarterNumber < 1 || $quarterNumber > 4) {
            throw ValidationException::withMessages(['quarter_id' => 'Select a valid monitoring quarter.']);
        }

        $first = ($quarterNumber - 1) * 3 + 1;
        return [$first, $first + 1, $first + 2];
    }

    public function calculateActualOutput(int $year, int $quarterNumber, mixed $details, string $prefix = 'production'): string
    {
        $months = $this->monthsForQuarter($quarterNumber);
        $validator = Validator::make(['year' => $year, 'monthly_details' => $details], [
            'year' => ['required', 'integer', 'between:2000,2100'],
            'monthly_details' => ['required', 'array', 'size:3'],
            'monthly_details.*' => ['required', 'array:id,month,activity_count,total_quantity,remarks'],
            'monthly_details.*.id' => ['nullable', 'uuid', 'distinct'],
            'monthly_details.*.month' => ['required', 'integer', \Illuminate\Validation\Rule::in($months), 'distinct'],
            'monthly_details.*.activity_count' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'monthly_details.*.total_quantity' => ['required', 'regex:/\A\d{1,12}(?:\.\d{1,2})?\z/D'],
            'monthly_details.*.remarks' => ['nullable', 'string'],
        ], ['monthly_details.*.total_quantity.regex' => 'Enter a nonnegative quantity with at most 12 whole digits and 2 decimal places.']);
        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->messages() as $field => $messages) {
                $errors[$field === 'year' ? $field : $prefix.'.'.$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }

        // Add fixed-point decimal strings without binary floats or platform-sized integers.
        $sum = '0';
        foreach ($details as $detail) {
            [$whole, $fraction] = array_pad(explode('.', (string) $detail['total_quantity'], 2), 2, '');
            $digits = strrev($whole.str_pad($fraction, 2, '0'));
            $previous = strrev($sum);
            $next = '';
            $carry = 0;
            for ($i = 0; $i < max(strlen($digits), strlen($previous)) || $carry; $i++) {
                $digit = (int) ($digits[$i] ?? '0') + (int) ($previous[$i] ?? '0') + $carry;
                $next .= (string) ($digit % 10);
                $carry = intdiv($digit, 10);
            }
            $sum = ltrim(strrev($next), '0') ?: '0';
        }
        if (strlen($sum) > 14) {
            throw ValidationException::withMessages([$prefix.'.monthly_details' => 'Quarterly Actual Output exceeds NUMERIC(14,2).']);
        }
        $sum = str_pad($sum, 3, '0', STR_PAD_LEFT);
        return substr($sum, 0, -2).'.'.substr($sum, -2);
    }

    public function canEditMonthly(MonitoringProduction $production): bool
    {
        $record = $production->monitoringRecord;
        try {
            $details = $production->monthlyDetails->map(fn ($row) => $row->only([
                'id', 'month', 'activity_count', 'total_quantity', 'remarks',
            ]))->all();
            return $this->calculateActualOutput($record->year, $record->quarter->quarter_number, $details)
                === $production->actual_output;
        } catch (ValidationException) {
            return false;
        }
    }

    public function revision(MonitoringRecord $record): string
    {
        $rows = $record->production()->orderBy('id')->with('monthlyDetails')->get()->map(fn ($row) => [
            $row->id, $row->project_product_id, $row->target_output, $row->actual_output, $row->unit,
            $row->remarks, $row->updated_at?->format('Y-m-d\TH:i:s.uP'),
            $row->monthlyDetails->map(fn ($month) => [$month->id, $month->month, $month->activity_count,
                $month->total_quantity, $month->remarks, $month->updated_at?->format('Y-m-d\TH:i:s.uP')])->all(),
        ])->all();

        return hash('sha256', json_encode([$record->project_id, $record->year, $record->quarter_id,
            $record->project_status, $record->updated_at?->format('Y-m-d\TH:i:s.uP'), $rows], JSON_THROW_ON_ERROR));
    }

    public function assertRevision(MonitoringRecord $record, array $data): void
    {
        $revision = $data['production_revision'] ?? null;
        if (! is_string($revision) || ! hash_equals($this->revision($record), $revision)) {
            throw ValidationException::withMessages(['production_revision' => 'Production changed or this form is outdated. Reload and review before saving.'])->status(409);
        }
    }
}
