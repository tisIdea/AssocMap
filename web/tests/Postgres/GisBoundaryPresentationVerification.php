<?php

// Included by the guarded canonical 023/024 runner; every fixture is rolled back.
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

DB::beginTransaction();
try {
    $geometries = [
        'concave' => ['type' => 'Polygon', 'coordinates' => [[[0, 0], [6, 0], [6, 1], [1, 1], [1, 5], [6, 5], [6, 6], [0, 6], [0, 0]]]],
        'hole' => ['type' => 'Polygon', 'coordinates' => [
            [[20, 0], [26, 0], [26, 6], [20, 6], [20, 0]], [[21, 1], [21, 5], [25, 5], [25, 1], [21, 1]],
        ]],
        'disjoint' => ['type' => 'MultiPolygon', 'coordinates' => [
            [[[40, 0], [42, 0], [42, 2], [40, 2], [40, 0]]], [[[60, 0], [64, 0], [64, 4], [60, 4], [60, 0]]],
        ]],
    ];
    $cases = [];
    foreach ($geometries as $name => $geometry) {
        $data = array_replace($payload, ['idempotency_key' => (string) Str::uuid(), 'location_type' => 'project_boundary',
            'location_name' => 'Private boundary '.$name, 'public_title' => 'Public boundary presentation '.$name,
            'site_type_id' => 5, 'geometry' => $geometry]);
        $createdBoundary = $locations->store($data, $officer, $request)['feature'];
        $boundaryId = $createdBoundary['id'];
        $locations->lifecycle($boundaryId, 'publish', $createdBoundary['properties']['version'], $officer, $request);
        $before = DB::selectOne('SELECT encode(extensions.ST_AsEWKB(geom), \'hex\') bytes, updated_at::text version,
            published_at::text published, location_type, site_type_id FROM gis_locations WHERE id=?', [$boundaryId]);
        $features = $queries->features(null, ['mode' => 'list', 'site_type_id' => '5'])['features'];
        $feature = collect($features)->firstWhere('id', $boundaryId);
        verify($feature !== null && $feature['geometry'] === $geometry, $name.' public API retains full original boundary');
        verify($feature['properties']['location_type'] === 'project_boundary', $name.' geometry discriminator remains boundary');
        $point = $feature['properties']['display_point'];
        verify($point['type'] === 'Point' && count($point['coordinates']) === 2, $name.' exposes one additive display point');
        $spatial = DB::selectOne('SELECT extensions.ST_CoveredBy(extensions.ST_SetSRID(extensions.ST_GeomFromGeoJSON(?),4326), geom) covered,
            NOT extensions.ST_CoveredBy(extensions.ST_Centroid(geom),geom) centroid_outside,
            extensions.ST_Equals(extensions.ST_SetSRID(extensions.ST_GeomFromGeoJSON(?),4326), extensions.ST_PointOnSurface(geom)) exact
            FROM gis_locations WHERE id=?', [json_encode($point), json_encode($point), $boundaryId]);
        verify($spatial->covered && $spatial->exact, $name.' point is PostGIS PointOnSurface within the actual polygon');
        verify($spatial->centroid_outside, $name.' regression fixture proves centroid would be unsafe');
        [$x, $y] = $point['coordinates'];
        $bounds = [$x - .02, $y - .02, $x + .02, $y + .02];
        $visible = $queries->features(null, ['mode' => 'map', 'site_type_id' => '5', 'bbox' => $bounds]);
        verify(in_array($boundaryId, array_column($visible['features'], 'id'), true), $name.' marker inside viewport is never omitted');
        verify(collect($visible['features'])->firstWhere('id', $boundaryId)['properties']['display_point'] === $point,
            $name.' point is independent of viewport and does not jump');
        $vertex = $geometry['type'] === 'MultiPolygon' ? $geometry['coordinates'][0][0][0] : $geometry['coordinates'][0][0];
        $edgeBounds = [$vertex[0] - .02, $vertex[1] - .02, $vertex[0] + .02, $vertex[1] + .02];
        $publicEdge = $queries->features(null, ['mode' => 'map', 'site_type_id' => '5', 'bbox' => $edgeBounds]);
        verify(! in_array($boundaryId, array_column($publicEdge['features'], 'id'), true), $name.' polygon edge alone does not include an offscreen public marker');
        $staffEdge = $queries->features($officer, ['mode' => 'map', 'site_type_id' => '5', 'bbox' => $edgeBounds]);
        $staffFeature = collect($staffEdge['features'])->firstWhere('id', $boundaryId);
        verify($staffFeature !== null && $staffFeature['geometry'] === $geometry, $name.' staff viewport still uses full polygon intersection');
        verify(! array_key_exists('display_point', $staffFeature['properties']), $name.' private feature contract remains unchanged');
        verify($queries->detail($officer, $boundaryId)['geometry'] === $geometry, $name.' editor receives the original full boundary');
        $single = $queries->features(null, ['mode' => 'overview', 'q' => $data['public_title']]);
        verify($single['meta']['bounds'] === [(float) $x, (float) $y, (float) $x, (float) $y], $name.' single-marker fitting uses representative coordinates');
        $after = DB::selectOne('SELECT encode(extensions.ST_AsEWKB(geom), \'hex\') bytes, updated_at::text version,
            published_at::text published, location_type, site_type_id FROM gis_locations WHERE id=?', [$boundaryId]);
        verify($before == $after, $name.' presentation queries leave EWKB, version and publication unchanged');
        $cases[] = ['id' => $boundaryId, 'point' => $point, 'data' => $data];
    }
    $xs = array_map(fn ($case) => (float) $case['point']['coordinates'][0], $cases);
    $ys = array_map(fn ($case) => (float) $case['point']['coordinates'][1], $cases);
    $overview = $queries->features(null, ['mode' => 'overview', 'site_type_id' => '5']);
    verify($overview['meta']['bounds'] === [min($xs), min($ys), max($xs), max($ys)], 'public overview fits display points rather than polygon envelopes');
    $staffOverview = $queries->features($officer, ['mode' => 'overview', 'site_type_id' => '5']);
    verify($staffOverview['meta']['bounds'] === [0.0, 0.0, 64.0, 6.0], 'staff overview retains full boundary extents');
    DB::enableQueryLog();
    DB::flushQueryLog();
    $all = $queries->features(null, ['mode' => 'map', 'site_type_id' => '5', 'bbox' => [-1, -1, 65, 7]]);
    $log = DB::getQueryLog();
    DB::disableQueryLog();
    verify(count($all['features']) === 3 && count($log) === 1, 'three representative markers load in one query');
    verify(substr_count($log[0]['query'], 'ST_PointOnSurface') === 1 && str_contains($log[0]['query'], 'OFFSET 0'),
        'public viewport and projection reuse one lateral representative-point expression');
    DB::table('media_photos')->insert(photo('gis_location_id', $cases[0]['id'], ['approved_by' => ADMIN, 'approved_at' => now()]));
    verify(count($gallery->gallery($gallery->parent($cases[0]['id']), $gallery->publicAuthorization($cases[0]['id']), true)['photos']) === 1,
        'representative boundary retains approved public photo gallery');
    DB::beginTransaction();
    for ($index = 0; $index < 101; $index++) {
        DB::insert('INSERT INTO gis_locations(id,project_id,location_type,location_name,geom,public_title,is_published,
            published_by,published_at,site_type_id,created_by,updated_by)
            SELECT ?,project_id,location_type,location_name,geom,public_title,is_published,published_by,published_at,
                site_type_id,created_by,updated_by FROM gis_locations WHERE id=?', [(string) Str::uuid(), $cases[0]['id']]);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $many = $queries->features(null, ['mode' => 'map', 'site_type_id' => '5', 'bbox' => [-1, -1, 65, 7]]);
    verify(count($many['features']) === 100 && $many['meta']['limit'] === 100 && $many['meta']['truncated'], 'boundary markers retain 100-feature limit');
    verify(count(DB::getQueryLog()) === 1, '100 representative markers still use one database query');
    DB::disableQueryLog();
    DB::rollBack();
    $unclassifiedData = array_replace($payload, ['idempotency_key' => (string) Str::uuid(), 'location_type' => 'project_boundary',
        'site_type_id' => null, 'public_title' => 'Unclassified boundary presentation',
        'geometry' => ['type' => 'Polygon', 'coordinates' => [[[80, 0], [82, 0], [82, 2], [80, 0]]]]]);
    $unclassified = $locations->store($unclassifiedData, $officer, $request)['feature'];
    $locations->lifecycle($unclassified['id'], 'publish', $unclassified['properties']['version'], $officer, $request);
    $legacyBoundary = $queries->features(null, ['q' => 'Unclassified boundary presentation'])['features'][0];
    verify($legacyBoundary['properties']['site_type_id'] === null && $legacyBoundary['properties']['display_point']['type'] === 'Point',
        'unclassified published boundary has an additive representative point');
    foreach (['unpublish', 'archive'] as $index => $action) {
        $case = $cases[$index];
        $version = $queries->detail($officer, $case['id'])['properties']['version'];
        $locations->lifecycle($case['id'], $action, $version, $officer, $request);
        $remaining = $queries->features(null, ['mode' => 'map', 'site_type_id' => '5', 'bbox' => [-1, -1, 65, 7]]);
        verify(! in_array($case['id'], array_column($remaining['features'], 'id'), true), $action.' removes boundary marker from public map');
        denied(fn () => $gallery->gallery($gallery->parent($case['id']), $gallery->publicAuthorization($case['id']), true), HttpException::class, 404);
    }
} finally {
    DB::rollBack();
}
