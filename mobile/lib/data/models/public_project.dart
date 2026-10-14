import 'geo_boundary.dart';

class PublicProject {
  const PublicProject({
    required this.id,
    required this.title,
    required this.associationName,
    required this.category,
    required this.areaLabel,
    required this.summary,
    required this.location,
    this.boundary,
  });

  final String id;
  final String title;
  final String associationName;
  final String category;
  final String areaLabel;
  final String summary;
  final GeoPoint location;
  final GeoBoundary? boundary;

  // This format belongs to local fixtures, not the Laravel API.
  factory PublicProject.fromFixture(Map<String, dynamic> json) {
    String field(String key) {
      final value = json[key];
      if (value is! String || value.trim().isEmpty) {
        throw FormatException('Missing fixture field: $key.');
      }
      return value.trim();
    }

    final boundary = json['boundary'];
    if (boundary != null && boundary is! Map<String, dynamic>) {
      throw const FormatException('Invalid fixture boundary.');
    }
    return PublicProject(
      id: field('id'),
      title: field('title'),
      associationName: field('associationName'),
      category: field('category'),
      areaLabel: field('areaLabel'),
      summary: field('summary'),
      location: GeoPoint.fromGeoJson(json['location']),
      boundary: boundary == null
          ? null
          : GeoBoundary.fromGeoJson(boundary as Map<String, dynamic>),
    );
  }

  bool matches(String query) {
    final normalized = query.trim().toLowerCase();
    return [
      title,
      associationName,
      category,
      areaLabel,
    ].any((field) => field.toLowerCase().contains(normalized));
  }
}
