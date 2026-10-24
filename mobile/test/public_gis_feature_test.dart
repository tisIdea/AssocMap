import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/data/models/public_gis_feature.dart';

import 'support/public_gis_fixtures.dart';

void main() {
  test('Public Point preserves longitude/latitude order without synthetic fields', () {
    final feature = PublicGisFeature.fromJson(pointFeature());
    final project = feature.toPresentation();
    expect(project.location!.longitude, 124.95);
    expect(project.location!.latitude, 11.05);
    expect(project.associationName, isNull);
    expect(project.category, isNull);
    expect(project.areaLabel, 'Fictional public address');
    expect(project.summary, isEmpty);
    expect(project.boundary, isNull);
  });

  test('Polygon preserves exterior and holes without manufacturing a marker', () {
    final feature = PublicGisFeature.fromJson(boundaryFeature());
    expect(feature.point, isNull);
    expect(feature.boundary!.polygons, hasLength(1));
    final polygon = feature.boundary!.polygons.single;
    expect(polygon.exterior, hasLength(5));
    expect(polygon.holes.single, hasLength(5));
    expect(polygon.holes.single.first.longitude, 124.92);
    expect(feature.toPresentation().location, isNull);
  });

  test('MultiPolygon preserves every part and its interior rings', () {
    final feature = PublicGisFeature.fromJson(boundaryFeature(multipart: true));
    expect(feature.boundary!.polygons, hasLength(2));
    expect(feature.boundary!.polygons.first.holes, hasLength(1));
    expect(feature.boundary!.polygons.last.holes, isEmpty);
    expect(feature.boundary!.polygons.last.exterior.first.longitude, 125.1);
  });

  test('Unsupported geometry and mismatched location type are rejected', () {
    for (final geometry in [
      {'type': 'LineString', 'coordinates': [[124, 11], [125, 12]]},
      {'type': 'Polygon', 'coordinates': [outerRing()]},
      {'type': 'GeometryCollection', 'geometries': []},
    ]) {
      final json = pointFeature()..['geometry'] = geometry;
      expect(() => PublicGisFeature.fromJson(json), throwsFormatException);
    }
  });

  test('Malformed public fields are rejected instead of filled with demo values', () {
    final cases = <Map<String, dynamic>>[
      pointFeature()..['id'] = 'not-a-location-uuid',
      pointFeature()..['properties'] = {'title': 'Incomplete'},
      pointFeature()..['type'] = 'NotFeature',
    ];
    for (final key in ['title', 'location_type', 'address', 'description']) {
      final json = pointFeature();
      final properties = Map<String, dynamic>.from(json['properties'] as Map);
      properties[key] = 123;
      json['properties'] = properties;
      cases.add(json);
    }
    for (final json in cases) {
      expect(() => PublicGisFeature.fromJson(json), throwsFormatException);
    }
  });

  test('Out-of-range, nonfinite, wrong-dimensional and malformed positions fail', () {
    for (final coordinates in [
      [181, 11], [124, 91], [double.nan, 11], [124, 11, 5], ['bad', 11], [],
    ]) {
      final json = pointFeature()..['geometry'] = {'type': 'Point', 'coordinates': coordinates};
      expect(() => PublicGisFeature.fromJson(json), throwsFormatException);
    }
  });

  test('Open rings and excessive geometry are rejected', () {
    final open = boundaryFeature()..['geometry'] = {
      'type': 'Polygon', 'coordinates': [outerRing().take(4).toList()],
    };
    expect(() => PublicGisFeature.fromJson(open), throwsFormatException);
    final excessive = boundaryFeature()..['geometry'] = {
      'type': 'Polygon', 'coordinates': [List.generate(2001, (_) => [124.9, 11.0])],
    };
    expect(() => PublicGisFeature.fromJson(excessive), throwsFormatException);
  });

  test('Bounds retain bbox ordering and expand only degenerate extents', () {
    final bounds = GisBounds.fromJson([124.9, 11.0, 125.2, 11.1]);
    expect(bounds.queryBounds.values, bounds.values);
    final point = GisBounds.fromJson([180, 90, 180, 90]).queryBounds;
    expect(point.west, lessThan(point.east));
    expect(point.south, lessThan(point.north));
    expect(point.east, 180);
    expect(point.north, 90);
    for (final invalid in [[125, 11, 124, 12], [-181, 11, 125, 12], [124, 11, 125]]) {
      expect(() => GisBounds.fromJson(invalid), throwsFormatException);
    }
  });

  test('Empty and truncated datasets retain source metadata', () {
    final bounds = GisBounds.fromJson([124, 10, 126, 12]);
    final empty = PublicGisResult.fromMapJson(
      mapResponse(features: [], emptyReason: 'outside_view'), bounds);
    expect(empty.projects, isEmpty);
    expect(empty.emptyReason, 'outside_view');
    expect(empty.bounds, same(bounds));
    final partial = PublicGisResult.fromMapJson(mapResponse(truncated: true), bounds);
    expect(partial.truncated, isTrue);
    expect(() => partial.projects.clear(), throwsUnsupportedError);
  });

  test('Duplicate IDs and malformed or excessive map metadata fail atomically', () {
    final bounds = GisBounds.fromJson([124, 10, 126, 12]);
    for (final json in [
      mapResponse(features: [pointFeature(), pointFeature()]),
      mapResponse(features: List.generate(101, (_) => pointFeature())),
      mapResponse()..['meta'] = {'limit': 20, 'truncated': false},
      mapResponse()..['meta'] = {'limit': 100, 'truncated': 'false'},
      mapResponse(features: []),
      mapResponse()..['features'] = [pointFeature(), {'type': 'invalid'}],
    ]) {
      expect(() => PublicGisResult.fromMapJson(json, bounds), throwsFormatException);
    }
  });
}
