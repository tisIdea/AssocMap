<?php

namespace App\Services\GIS;

use App\Models\GisLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

class GisGeometry
{
    public const MAX_BYTES = 131072;
    public const MAX_VERTICES = 2000;

    public function normalize(mixed $geometry, string $locationType): array
    {
        if (! is_array($geometry) || array_is_list($geometry)
            || array_diff(array_keys($geometry), ['type', 'coordinates']) !== []
            || ! in_array($locationType, GisLocation::TYPES, true)) $this->invalid();
        try {
            if (strlen(json_encode($geometry, JSON_THROW_ON_ERROR)) > self::MAX_BYTES) $this->invalid();
        } catch (JsonException) {
            $this->invalid();
        }
        $type = $geometry['type'] ?? null;
        $coordinates = $geometry['coordinates'] ?? null;
        $vertices = 0;
        if ($locationType !== 'project_boundary') {
            if ($type !== 'Point') $this->invalid('A point location requires GeoJSON Point geometry.');
            $this->position($coordinates, $vertices);
        } elseif ($type === 'Polygon') {
            $this->polygon($coordinates, $vertices);
        } elseif ($type === 'MultiPolygon') {
            if (! is_array($coordinates) || ! array_is_list($coordinates) || count($coordinates) < 1 || count($coordinates) > 30) $this->invalid();
            foreach ($coordinates as $polygon) $this->polygon($polygon, $vertices);
        } else {
            $this->invalid('A project boundary requires Polygon or MultiPolygon geometry.');
        }

        return ['type' => $type, 'coordinates' => $coordinates];
    }

    public function validateSpatial(array $geometry): void
    {
        $result = DB::selectOne('SELECT extensions.ST_IsValid(geom, 0) AS valid,
            extensions.ST_IsEmpty(geom) AS empty, extensions.ST_SRID(geom) AS srid
            FROM (SELECT extensions.ST_SetSRID(extensions.ST_GeomFromGeoJSON(?), 4326) AS geom) AS checked',
            [json_encode($geometry, JSON_THROW_ON_ERROR)]);
        if (! in_array($result->valid, [true, 1, '1', 't', 'true'], true)
            || in_array($result->empty, [true, 1, '1', 't', 'true'], true) || (int) $result->srid !== 4326) {
            $this->invalid('Geometry is invalid or empty. Check boundary intersections, overlapping parts, and holes.');
        }
    }

    private function polygon(mixed $rings, int &$vertices): void
    {
        if (! is_array($rings) || ! array_is_list($rings) || count($rings) < 1 || count($rings) > 50) $this->invalid();
        foreach ($rings as $ring) {
            if (! is_array($ring) || ! array_is_list($ring) || count($ring) < 4 || count($ring) > self::MAX_VERTICES) $this->invalid();
            $distinct = [];
            foreach ($ring as $position) {
                $this->position($position, $vertices);
                $distinct[json_encode([(float) $position[0], (float) $position[1]])] = true;
            }
            $first = $ring[0];
            $last = $ring[count($ring) - 1];
            if ((float) $first[0] !== (float) $last[0] || (float) $first[1] !== (float) $last[1] || count($distinct) < 3) {
                $this->invalid('Each boundary ring must close and contain at least three distinct positions.');
            }
        }
    }

    private function position(mixed $position, int &$vertices): void
    {
        if (! is_array($position) || ! array_is_list($position) || count($position) !== 2) $this->invalid();
        foreach ($position as $number) {
            if ((! is_int($number) && ! is_float($number)) || ! is_finite((float) $number)) $this->invalid();
        }
        if ($position[0] < -180 || $position[0] > 180 || $position[1] < -90 || $position[1] > 90) {
            $this->invalid('Coordinates must use longitude (-180 to 180), then latitude (-90 to 90).');
        }
        if (++$vertices > self::MAX_VERTICES) $this->invalid('Geometry exceeds the 2,000-position limit.');
    }

    private function invalid(string $message = 'Provide bounded two-dimensional GeoJSON geometry, with valid coordinates and rings.'): never
    {
        throw ValidationException::withMessages(['geometry' => $message]);
    }
}
