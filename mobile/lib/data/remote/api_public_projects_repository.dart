import '../../core/errors/public_gis_failure.dart';
import '../../core/networking/public_gis_client.dart';
import '../models/public_gis_feature.dart';
import '../models/public_project.dart';
import '../repositories/public_projects_repository.dart';

class ApiPublicProjectsRepository implements ViewportPublicProjectsRepository {
  ApiPublicProjectsRepository(this.client);
  final PublicGisClient client;

  @override
  Future<List<PublicProject>> getPublicProjects() async =>
      (await getMap()).projects;

  @override
  Future<PublicGisResult> getMap({
    GisBounds? bounds,
    String query = '',
    String? locationType,
  }) async {
    final search = query.trim();
    if (search.runes.length > 100 ||
        (locationType != null &&
            !const [
              'project_point',
              'project_boundary',
            ].contains(locationType))) {
      throw PublicGisFailure(GisFailureCode.invalidQuery);
    }
    final filters = <String, String>{
      if (search.isNotEmpty) 'q': search,
      'location_type': ?locationType,
    };
    try {
      if (bounds == null) {
        final overview = await client.getData({'mode': 'overview', ...filters});
        final meta = overview['meta'];
        final features = overview['features'];
        if (overview['type'] != 'FeatureCollection' ||
            features is! List ||
            features.isNotEmpty ||
            meta is! Map<String, dynamic> ||
            !meta.containsKey('bounds') ||
            !meta.containsKey('empty_reason')) {
          throw const FormatException('Invalid public GIS overview.');
        }
        final reason = meta['empty_reason'];
        if (reason != null && reason is! String) {
          throw const FormatException('Invalid overview reason.');
        }
        if (meta['bounds'] == null) {
          if (reason is! String || reason.trim().isEmpty) {
            throw const FormatException('Missing empty reason.');
          }
          return PublicGisResult(projects: const [], emptyReason: reason);
        }
        if (reason != null) {
          throw const FormatException('Contradictory overview metadata.');
        }
        bounds = GisBounds.fromJson(meta['bounds']);
      }
      final extent = bounds.queryBounds;
      final json = await client.getData({
        'mode': 'map',
        ...filters,
        for (var index = 0; index < 4; index++)
          'bbox[$index]': extent.values[index].toString(),
      });
      return PublicGisResult.fromMapJson(json, extent);
    } on FormatException {
      throw PublicGisFailure(GisFailureCode.invalidResponse);
    }
  }
}
