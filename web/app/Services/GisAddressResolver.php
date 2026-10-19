<?php

namespace App\Services;

use App\Models\Association;
use Illuminate\Support\Facades\DB;

class GisAddressResolver
{
    public function forAssociation(Association $association): array
    {
        $area = DB::table('areas as ar')->join('barangays as b', 'b.id', '=', 'ar.barangay_id')
            ->join('municipalities as m', 'm.id', '=', 'b.municipality_id')
            ->where('ar.id', $association->area_id)
            ->first(['ar.address as area_address', 'b.name as barangay_name', 'm.name as municipality_name', 'm.province', 'm.region']);

        return $this->fromRow($area);
    }

    public function fromRow(?object $area): array
    {
        $address = trim($area->area_address ?? '');
        $context = array_filter([$area->barangay_name ?? null, $area->municipality_name ?? null,
            $area->province ?? null, $area->region ?? null], fn ($value) => is_string($value) && trim($value) !== '');
        if ($address === '') {
            return ['address' => null, 'context' => implode(', ', $context),
                'message' => 'No reliable address is available in the existing area records.'];
        }
        if (mb_strlen($address) > 2000) {
            return ['address' => null, 'context' => implode(', ', $context),
                'message' => 'The registered-area address exceeds the reference display limit. Review the full address in Area Management.'];
        }

        return ['address' => $address, 'context' => implode(', ', $context), 'message' => null];
    }
}
