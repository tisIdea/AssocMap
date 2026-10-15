import 'geo_boundary.dart';
import 'public_project.dart';

class PublicGisFeature {
  const PublicGisFeature({
    required this.id,
    required this.locationType,
    required this.title,
    required this.address,
    required this.description,
    this.point,
    this.boundary,
  });

  final String id;
  final String locationType;
  final String title;
  final String? address;
  final String? description;
  final GeoPoint? point;
  final GeoBoundary? boundary;

  factory PublicGisFeature.fromJson(Map<String, dynamic> json) {
    final id = json['id'];
    final properties = json['properties'];
    final geometry = json['geometry'];
    if (json['type'] != 'Feature' ||
        id is! String ||
        !RegExp(r'^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$').hasMatch(id) ||
        properties is! Map<String, dynamic> ||
        geometry is! Map<String, dynamic>) {
      throw const FormatException('Invalid public GIS feature.');
    }
    final title = properties['title'];
    final type = properties['location_type'];
    if (title is! String ||
        title.trim().isEmpty ||
        !const ['project_point', 'project_boundary'].contains(type)) {
      throw const FormatException('Invalid public GIS properties.');
    }
    String? optionalText(String key) {
      if (!properties.containsKey(key)) {
        throw const FormatException('Missing public GIS field.');
      }
      final value = properties[key];
      if (value != null && value is! String) {
        throw const FormatException('Invalid public GIS text.');
      }
      final text = (value as String?)?.trim();
      return text == null || text.isEmpty ? null : text;
    }
    final address = optionalText('address');
    final description = optionalText('description');
    final geometryType = geometry['type'];
    if ((type == 'project_point' && geometryType != 'Point') ||
        (type == 'project_boundary' &&
            !const ['Polygon', 'MultiPolygon'].contains(geometryType))) {
      throw const FormatException('Unsupported or mismatched public GIS geometry.');
    }
    var positions = 0;
    void validatePositions(Object? value, [int depth = 0]) {
      if (value is! List || value.isEmpty || depth > 3) {
        throw const FormatException('Invalid coordinates.');
      }
      if (value.first is List) {
        for (final child in value) {
          validatePositions(child, depth + 1);
        }
      } else {
        if (value.length != 2 || ++positions > 2000) {
          throw const FormatException(
            'Invalid dimensionality or excessive geometry.',
          );
        }
        GeoPoint.fromGeoJson(value);
      }
    }
    validatePositions(geometry['coordinates']);
    return PublicGisFeature(
      id: id,
      locationType: type as String,
      title: title.trim(),
      address: address,
      description: description,
      point: geometryType == 'Point'
          ? GeoPoint.fromGeoJson(geometry['coordinates'])
          : null,
      boundary: geometryType == 'Point'
          ? null
          : GeoBoundary.fromGeoJson(geometry),
    );
  }

  PublicProject toPresentation() => PublicProject(
    id: id,
    title: title,
    summary: description ?? '',
    areaLabel: address,
    locationType: locationType,
    location: point,
    boundary: boundary,
  );
}

class GisBounds {
  const GisBounds._(this.west, this.south, this.east, this.north);
  final double west;
  final double south;
  final double east;
  final double north;

  factory GisBounds.fromJson(Object? value) {
    if (value is! List ||
        value.length != 4 ||
        value.any((item) => item is! num)) {
      throw const FormatException('Invalid GIS bounds.');
    }
    final numbers = value.map((item) => (item as num).toDouble()).toList();
    if (numbers.any((item) => !item.isFinite) ||
        numbers[0] < -180 ||
        numbers[2] > 180 ||
        numbers[1] < -90 ||
        numbers[3] > 90 ||
        numbers[0] > numbers[2] ||
        numbers[1] > numbers[3]) {
      throw const FormatException(
        'GIS bounds must be west, south, east, north without wrapping.',
      );
    }
    return GisBounds._(numbers[0], numbers[1], numbers[2], numbers[3]);
  }

  GisBounds get queryBounds => GisBounds._(
    west == east ? (west - .001).clamp(-180, 180).toDouble() : west,
    south == north ? (south - .001).clamp(-90, 90).toDouble() : south,
    west == east ? (east + .001).clamp(-180, 180).toDouble() : east,
    south == north ? (north + .001).clamp(-90, 90).toDouble() : north,
  );

  List<double> get values => [west, south, east, north];
}

class PublicGisResult {
  PublicGisResult({
    required List<PublicProject> projects,
    this.bounds,
    this.truncated = false,
    this.emptyReason,
  }) : projects = List.unmodifiable(projects);
  final List<PublicProject> projects;
  final GisBounds? bounds;
  final bool truncated;
  final String? emptyReason;

  factory PublicGisResult.fromMapJson(
    Map<String, dynamic> json,
    GisBounds bounds,
  ) {
    final features = json['features'];
    final meta = json['meta'];
    if (json['type'] != 'FeatureCollection' ||
        features is! List ||
        meta is! Map<String, dynamic> ||
        meta['limit'] is! int ||
        meta['limit'] != 100 ||
        meta['truncated'] is! bool ||
        features.length > 100) {
      throw const FormatException('Invalid public GIS map response.');
    }
    final reason = meta['empty_reason'];
    if ((reason != null && reason is! String) ||
        (features.isEmpty && (reason is! String || reason.trim().isEmpty))) {
      throw const FormatException('Invalid empty-data reason.');
    }
    final projects = <PublicProject>[];
    final ids = <String>{};
    for (final feature in features) {
      if (feature is! Map<String, dynamic>) {
        throw const FormatException('Invalid feature.');
      }
      final parsed = PublicGisFeature.fromJson(feature);
      if (!ids.add(parsed.id.toLowerCase())) {
        throw const FormatException('Duplicate public location.');
      }
      projects.add(parsed.toPresentation());
    }
    return PublicGisResult(
      projects: projects,
      bounds: bounds,
      truncated: meta['truncated'] as bool,
      emptyReason: reason as String?,
    );
  }
}
