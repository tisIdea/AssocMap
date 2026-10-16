<?php

namespace Tests\Unit;

use App\Models\GisLocation;
use App\Services\GisFeatureSerializer;
use Tests\TestCase;

class GisFeatureSerializerTest extends TestCase
{
    public function test_public_projection_excludes_internal_fields_and_has_no_private_fallback(): void
    {
        $location = $this->location();
        $feature = (new GisFeatureSerializer)->make($location, true, true);
        $this->assertSame(['type', 'id', 'geometry', 'properties'], array_keys($feature));
        $this->assertSame(['location_type', 'title', 'address', 'description'], array_keys($feature['properties']));
        $this->assertSame('Approved title', $feature['properties']['title']);
        $this->assertNull($feature['properties']['address']);
        $this->assertNull($feature['properties']['description']);
        $serialized = json_encode($feature);
        foreach (['Internal location', 'Private address', 'Private project', 'private@example.test', 'private-note', 'private_raw_geom'] as $private) {
            $this->assertStringNotContainsString($private, $serialized);
        }
    }

    public function test_association_viewer_does_not_receive_lifecycle_or_actor_metadata(): void
    {
        $feature = (new GisFeatureSerializer)->make($this->location(), false, false);
        $this->assertSame('Internal location', $feature['properties']['title']);
        foreach (['version', 'created_by', 'published_by', 'archived_by', 'association_id', 'project_id', 'is_published'] as $field) {
            $this->assertArrayNotHasKey($field, $feature['properties']);
        }
    }

    public function test_boundaries_remain_multipolygons_and_missing_geometry_does_not_create_a_point(): void
    {
        $location = $this->location();
        $geometry = ['type' => 'MultiPolygon', 'coordinates' => [
            [[[123, 10], [124, 10], [124, 11], [123, 10]]],
            [[[125, 10], [126, 10], [126, 11], [125, 10]]],
        ]];
        $location->geometry_json = json_encode($geometry);
        $this->assertSame($geometry, (new GisFeatureSerializer)->make($location, true)['geometry']);
        $location->geometry_json = null;
        $this->assertNull((new GisFeatureSerializer)->make($location, false)['geometry']);
    }

    public function test_managers_receive_finalization_state_without_expanding_public_data(): void
    {
        foreach (['completed', 'terminated', 'ongoing', 'planned', 'suspended', 'cancelled'] as $status) {
            $location = $this->location()->forceFill(['project_status' => $status]);
            $manager = (new GisFeatureSerializer)->make($location, false, true);
            $this->assertSame(in_array($status, ['completed', 'terminated'], true), $manager['properties']['project_finalized']);
            $this->assertSame($status, $manager['properties']['project_status']);
            $this->assertArrayNotHasKey('project_finalized', (new GisFeatureSerializer)->make($location, true, true)['properties']);
            $this->assertArrayNotHasKey('project_finalized', (new GisFeatureSerializer)->make($location, false, false)['properties']);
            $this->assertArrayNotHasKey('project_status', (new GisFeatureSerializer)->make($location, true, true)['properties']);
            $this->assertArrayNotHasKey('project_status', (new GisFeatureSerializer)->make($location, false, false)['properties']);
        }
    }

    public function test_a_closure_marker_also_protects_gis_identity(): void
    {
        $location = $this->location()->forceFill(['project_status' => 'ongoing', 'project_closed_at' => '2026-10-16 08:00:00']);
        $this->assertTrue((new GisFeatureSerializer)->make($location, false, true)['properties']['project_finalized']);
    }

    private function location(): GisLocation
    {
        return (new GisLocation)->forceFill([
            'id' => 'ab7716b4-7041-4c59-8d97-2cb12336bb05', 'location_type' => 'project_boundary',
            'location_name' => 'Internal location', 'address' => 'Private address', 'public_title' => 'Approved title',
            'public_address' => null, 'public_description' => null, 'project_title' => 'Private project',
            'association_name' => 'Private association', 'email' => 'private@example.test', 'notes' => 'private-note',
            'geom' => 'private_raw_geom', 'geometry_json' => null, 'created_by' => 'actor-id',
            'association_id' => null, 'project_id' => 'project-id', 'is_published' => true, 'version' => 'private-version',
        ]);
    }
}
