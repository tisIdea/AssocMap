class GeoPoint {
  const GeoPoint(this.latitude, this.longitude);

  final double latitude;
  final double longitude;

  factory GeoPoint.fromGeoJson(Object? value) {
    if (value is! List ||
        value.length < 2 ||
        value[0] is! num ||
        value[1] is! num) {
      throw const FormatException('Invalid GeoJSON position.');
    }
    final longitude = (value[0] as num).toDouble();
    final latitude = (value[1] as num).toDouble();
    if (!longitude.isFinite ||
        !latitude.isFinite ||
        longitude < -180 ||
        longitude > 180 ||
        latitude < -90 ||
        latitude > 90) {
      throw const FormatException('GeoJSON position is outside WGS84 bounds.');
    }
    return GeoPoint(latitude, longitude);
  }

  @override
  bool operator ==(Object other) =>
      other is GeoPoint &&
      latitude == other.latitude &&
      longitude == other.longitude;

  @override
  int get hashCode => Object.hash(latitude, longitude);
}

class GeoPolygon {
  GeoPolygon(List<GeoPoint> exterior, List<List<GeoPoint>> holes)
    : exterior = List.unmodifiable(exterior),
      holes = List.unmodifiable(
        holes.map((ring) => List<GeoPoint>.unmodifiable(ring)),
      );

  final List<GeoPoint> exterior;
  final List<List<GeoPoint>> holes;
}

class GeoBoundary {
  GeoBoundary(List<GeoPolygon> polygons)
    : polygons = List.unmodifiable(polygons);

  final List<GeoPolygon> polygons;

  factory GeoBoundary.fromGeoJson(Map<String, dynamic> geometry) {
    final coordinates = geometry['coordinates'];
    if (coordinates is! List || coordinates.isEmpty) {
      throw const FormatException('Boundary coordinates are required.');
    }
    return switch (geometry['type']) {
      'Polygon' => GeoBoundary([_polygon(coordinates)]),
      'MultiPolygon' => GeoBoundary(coordinates.map(_polygon).toList()),
      _ => throw const FormatException(
        'Only Polygon and MultiPolygon are supported.',
      ),
    };
  }

  static GeoPolygon _polygon(Object? value) {
    if (value is! List || value.isEmpty) {
      throw const FormatException('A polygon requires an exterior ring.');
    }
    final rings = value.map(_ring).toList();
    return GeoPolygon(rings.first, rings.skip(1).toList());
  }

  static List<GeoPoint> _ring(Object? value) {
    if (value is! List || value.length < 4) {
      throw const FormatException(
        'A closed ring requires at least four positions.',
      );
    }
    final points = value.map(GeoPoint.fromGeoJson).toList();
    if (points.first != points.last || points.toSet().length < 3) {
      throw const FormatException(
        'A ring must close and have three distinct vertices.',
      );
    }
    return points;
  }
}
