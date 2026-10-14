import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/data/models/geo_boundary.dart';

void main() {
  const exterior = [
    [120, 10],
    [121, 10],
    [121, 11],
    [120, 10],
  ];
  const hole = [
    [120.2, 10.1],
    [120.3, 10.1],
    [120.3, 10.2],
    [120.2, 10.1],
  ];

  test('GeoJSON longitude-latitude is converted to map latitude-longitude', () {
    final point = GeoPoint.fromGeoJson([124.97, 11.15]);
    expect(point.latitude, 11.15);
    expect(point.longitude, 124.97);
  });

  test('Polygon retains its exterior and holes', () {
    final boundary = GeoBoundary.fromGeoJson({
      'type': 'Polygon',
      'coordinates': [exterior, hole],
    });
    expect(boundary.polygons, hasLength(1));
    expect(boundary.polygons.single.holes, hasLength(1));
    expect(
      () => boundary.polygons.single.exterior.clear(),
      throwsUnsupportedError,
    );
  });

  test('MultiPolygon retains disconnected parts', () {
    final boundary = GeoBoundary.fromGeoJson({
      'type': 'MultiPolygon',
      'coordinates': [
        [exterior],
        [exterior, hole],
      ],
    });
    expect(boundary.polygons, hasLength(2));
    expect(boundary.polygons.last.holes, hasLength(1));
  });

  test('Malformed, unclosed, and degenerate rings are rejected', () {
    for (final coordinates in [
      [],
      [
        [
          [120, 10],
          [121, 10],
        ],
      ],
      [
        [
          [120, 10],
          [121, 10],
          [121, 11],
          [120, 11],
        ],
      ],
      [
        [
          [120, 10],
          [120, 10],
          [120, 10],
          [120, 10],
        ],
      ],
    ]) {
      expect(
        () => GeoBoundary.fromGeoJson({
          'type': 'Polygon',
          'coordinates': coordinates,
        }),
        throwsFormatException,
      );
    }
  });

  test(
    'Unsupported geometry types and out-of-range positions are rejected',
    () {
      expect(
        () => GeoBoundary.fromGeoJson({
          'type': 'LineString',
          'coordinates': exterior,
        }),
        throwsFormatException,
      );
      for (final point in [
        [181, 10],
        [120, 91],
        [double.nan, 10],
        ['120', 10],
      ]) {
        expect(() => GeoPoint.fromGeoJson(point), throwsFormatException);
      }
    },
  );
}
