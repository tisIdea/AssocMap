<?php

namespace App\Services\Associations;

use App\Models\Area;
use App\Models\Association;
use App\Models\Barangay;
use App\Models\Municipality;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class AssociationGeographyService
{
    public function enabled(): bool
    {
        return $this->usesDirectGeography() && config('associations.direct_geography') === true;
    }

    public function usesDirectGeography(): bool
    {
        return config('associations.geography_schema_ready') === true;
    }

    public function barangayRelation(string $prefix = ''): string
    {
        return ($prefix === '' ? '' : $prefix.'.').($this->usesDirectGeography() ? 'barangay' : 'area.barangay');
    }

    public function relations(string $prefix = ''): array
    {
        $barangay = $this->barangayRelation($prefix);

        return [($prefix === '' ? '' : $prefix.'.').'area:id,barangay_id,address',
            $barangay.':id,municipality_id,name', $barangay.'.municipality:id,name'];
    }

    public function displayRelations(string $prefix = '', bool $withStatus = false): array
    {
        $prefix = $prefix === '' ? '' : $prefix.'.';
        if ($this->usesDirectGeography()) {
            return [$prefix.'area:id,barangay_id,address'.($withStatus ? ',is_active' : ''),
                $prefix.'barangay' => fn ($relation) => $this->joinDisplayGeography($relation->getQuery(), false, $withStatus)];
        }

        return [$prefix.'area' => fn ($relation) => $this->joinDisplayGeography($relation->getQuery(), true, $withStatus)];
    }

    private function joinDisplayGeography(Builder $query, bool $area, bool $withStatus): void
    {
        $table = $area ? 'areas' : 'barangays';
        $columns = $area ? ['id', 'barangay_id', 'address'] : ['id', 'municipality_id', 'name'];
        if ($withStatus) $columns[] = 'is_active';
        $query->select(array_map(fn ($column) => $table.'.'.$column, $columns));
        $barangay = $area ? 'display_barangay' : $table;
        if ($area) $query->leftJoin('barangays as display_barangay', 'display_barangay.id', '=', 'areas.barangay_id');
        $query->leftJoin('municipalities as display_municipality', 'display_municipality.id', '=', $barangay.'.municipality_id');
        $joined = ['municipality' => $withStatus ? ['id', 'name', 'province', 'region', 'is_active'] : ['id', 'name']];
        if ($area) $joined['barangay'] = $withStatus ? ['id', 'municipality_id', 'name', 'is_active'] : ['id', 'municipality_id', 'name'];
        foreach ($joined as $relation => $fields) {
            foreach ($fields as $field) $query->addSelect('display_'.$relation.'.'.$field.' as display_'.$relation.'_'.$field);
        }

        // Keep the existing selected attributes, casts and nested relationship shape.
        $query->afterQuery(function (Collection $records) use ($area, $joined): Collection {
            foreach ($records as $record) {
                $attributes = $record->getAttributes();
                $models = [];
                foreach ($joined as $relation => $fields) {
                    $values = [];
                    foreach ($fields as $field) {
                        $alias = 'display_'.$relation.'_'.$field;
                        $values[$field] = $attributes[$alias];
                        unset($attributes[$alias]);
                    }
                    $model = $relation === 'barangay' ? new Barangay : new Municipality;
                    $models[$relation] = $values['id'] === null ? null : $model->newFromBuilder($values, $record->getConnectionName());
                }
                $record->setRawAttributes($attributes, true);
                $barangay = $area ? $models['barangay'] : $record;
                $barangay?->setRelation('municipality', $models['municipality']);
                if ($area) $record->setRelation('barangay', $barangay);
            }

            return $records;
        });
    }

    public function associationColumns(array $columns): array
    {
        return $this->usesDirectGeography() ? array_values(array_unique([...$columns, 'barangay_id'])) : $columns;
    }

    public function joinGeography(Builder|QueryBuilder $query, string $municipalityAlias = 'm'): Builder|QueryBuilder
    {
        return $query->leftJoin('areas as ar', 'ar.id', '=', 'a.area_id')
            ->join('barangays as b', 'b.id', '=', $this->usesDirectGeography() ? 'a.barangay_id' : 'ar.barangay_id')
            ->join('municipalities as '.$municipalityAlias, $municipalityAlias.'.id', '=', 'b.municipality_id');
    }

    public function rules(array $input, bool $updating, bool $checkEligibility = true): array
    {
        if (! $this->enabled()) {
            return [
                'area_id' => ['bail', 'required', 'uuid', Rule::exists('areas', 'id')->where('is_active', true)],
                'barangay_id' => ['prohibited'],
                'specific_address' => ['prohibited'],
                ...($this->usesDirectGeography() ? ['expected_updated_at' => [$updating ? 'required' : 'prohibited', 'string', 'max:50']] : []),
            ];
        }

        return [
            'municipality_id' => ['bail', 'required', 'uuid', Rule::when($checkEligibility,
                [Rule::exists('municipalities', 'id')->where('is_active', true)])],
            'barangay_id' => ['bail', 'required', 'uuid', Rule::when($checkEligibility,
                [Rule::exists('barangays', 'id')->where('is_active', true)
                    ->where('municipality_id', Str::isUuid($input['municipality_id'] ?? null) ? $input['municipality_id'] : null)])],
            'area_id' => ['bail', 'nullable', 'uuid', Rule::when($checkEligibility,
                [Rule::exists('areas', 'id')->where('is_active', true)
                    ->where('barangay_id', Str::isUuid($input['barangay_id'] ?? null) ? $input['barangay_id'] : null)])],
            'specific_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'expected_updated_at' => [$updating ? 'required' : 'prohibited', 'string', 'max:50'],
        ];
    }

    public function assertCurrent(Association $association, array $data): void
    {
        if ($this->usesDirectGeography() && ($data['expected_updated_at'] ?? null) !== $association->updated_at?->format('Y-m-d\TH:i:s.uP')) {
            throw ValidationException::withMessages(['association' => 'This association changed after you opened the form. Reload it before saving.']);
        }
    }

    public function apply(Association $association, array $data): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Association geography writes require a transaction.');
        }
        if (! $this->enabled()) {
            if (empty($data['area_id'])) {
                throw ValidationException::withMessages(['area_id' => 'Please select a specific area.']);
            }
            $association->area_id = $data['area_id'];
            if ($this->usesDirectGeography()) {
                $location = DB::table('areas as ar')->join('barangays as b', 'b.id', '=', 'ar.barangay_id')
                    ->join('municipalities as m', 'm.id', '=', 'b.municipality_id')
                    ->where('ar.id', $data['area_id'])->where('ar.is_active', true)
                    ->where('b.is_active', true)->where('m.is_active', true)->sharedLock()
                    ->first(['ar.id', 'ar.barangay_id']);
                if (! $location) {
                    throw ValidationException::withMessages(['area_id' => 'Select an active area with active geographic parents.']);
                }
                $association->barangay_id = $location->barangay_id;
            }

            return;
        }

        // Revalidate and lock the selected parents until the association write commits.
        validator($data, $this->rules($data, $association->exists, false))->validate();
        $municipality = Municipality::whereKey($data['municipality_id'])->sharedLock()->first();
        $barangay = Barangay::whereKey($data['barangay_id'])->sharedLock()->first();
        if (! $municipality?->is_active) {
            throw ValidationException::withMessages(['municipality_id' => 'Select an active municipality / city.']);
        }
        if (! $barangay?->is_active || strcasecmp($barangay->municipality_id, $municipality->id) !== 0) {
            throw ValidationException::withMessages(['barangay_id' => 'Select an active barangay in this municipality / city.']);
        }
        $area = filled($data['area_id'] ?? null) ? Area::whereKey($data['area_id'])->sharedLock()->first() : null;
        if (filled($data['area_id'] ?? null) && (! $area?->is_active || strcasecmp($area->barangay_id, $barangay->id) !== 0)) {
            throw ValidationException::withMessages(['area_id' => 'Select an active area belonging to this barangay.']);
        }
        $association->barangay_id = $barangay->id;
        $association->area_id = $area?->id;
        if (array_key_exists('specific_address', $data)) {
            $address = is_string($data['specific_address']) ? trim($data['specific_address']) : null;
            $association->specific_address = $address === '' ? null : $address;
        } elseif (! $association->exists) {
            $association->specific_address = null;
        }
    }
}
