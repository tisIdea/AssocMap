<?php

namespace Tests\Unit;

use App\Services\GIS\GisGeometry;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GisGeometryTest extends TestCase
{
    public function test_point_uses_longitude_then_latitude(): void
    {
        $geometry = ['type' => 'Point', 'coordinates' => [123.8, 10.3]];
        $this->assertSame($geometry, (new GisGeometry)->normalize($geometry, 'project_point'));
    }

    public function test_polygon_holes_and_multiple_parts_are_preserved(): void
    {
        $outer = [[123, 10], [124, 10], [124, 11], [123, 11], [123, 10]];
        $hole = [[123.2, 10.2], [123.2, 10.8], [123.8, 10.8], [123.8, 10.2], [123.2, 10.2]];
        $second = [[125, 10], [126, 10], [126, 11], [125, 11], [125, 10]];
        $polygon = ['type' => 'Polygon', 'coordinates' => [$outer, $hole]];
        $multi = ['type' => 'MultiPolygon', 'coordinates' => [[$outer, $hole], [$second]]];
        $this->assertSame($polygon, (new GisGeometry)->normalize($polygon, 'project_boundary'));
        $this->assertSame($multi, (new GisGeometry)->normalize($multi, 'project_boundary'));
    }

    #[DataProvider('invalidGeometries')]
    public function test_malformed_or_incompatible_geometry_is_rejected(mixed $geometry, string $type): void
    {
        $this->expectException(ValidationException::class);
        (new GisGeometry)->normalize($geometry, $type);
    }

    public static function invalidGeometries(): array
    {
        return [
            [['type' => 'Point', 'coordinates' => [123, 10]], 'association_point'],
            [null, 'project_point'],
            [['type' => 'Point', 'coordinates' => [181, 10]], 'project_point'],
            [['type' => 'Point', 'coordinates' => [123, -91]], 'project_point'],
            [['type' => 'Point', 'coordinates' => ['123', 10]], 'project_point'],
            [['type' => 'Point', 'coordinates' => [true, 10]], 'project_point'],
            [['type' => 'Point', 'coordinates' => [123, 10, 5]], 'project_point'],
            [['type' => 'Point', 'coordinates' => [123, 10], 'crs' => 'EPSG:3857'], 'project_point'],
            [['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [123, 10]]], 'project_point'],
            [['type' => 'GeometryCollection', 'geometries' => []], 'project_boundary'],
            [['type' => 'Point', 'coordinates' => [123, 10]], 'project_boundary'],
            [['type' => 'Polygon', 'coordinates' => []], 'project_boundary'],
            [['type' => 'Polygon', 'coordinates' => [[[123, 10], [124, 10], [124, 11], [123, 11]]]], 'project_boundary'],
            [['type' => 'Polygon', 'coordinates' => [[[123, 10], [123, 10], [123, 10], [123, 10]]]], 'project_boundary'],
            [['type' => 'MultiPolygon', 'coordinates' => []], 'project_boundary'],
        ];
    }

    public function test_vertex_limit_applies_across_all_polygon_parts(): void
    {
        $ring = [];
        for ($index = 0; $index < 399; $index++) array_push($ring, [123, 10], [124, 10], [124, 11]);
        $ring[] = [123, 10];
        $this->expectException(ValidationException::class);
        (new GisGeometry)->normalize(['type' => 'MultiPolygon', 'coordinates' => [[$ring], [$ring]]], 'project_boundary');
    }
}
