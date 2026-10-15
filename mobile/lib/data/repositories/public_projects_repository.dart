import '../models/public_gis_feature.dart';
import '../models/public_project.dart';

abstract interface class PublicProjectsRepository {
  Future<List<PublicProject>> getPublicProjects();
}

abstract interface class ViewportPublicProjectsRepository
    implements PublicProjectsRepository {
  Future<PublicGisResult> getMap({
    GisBounds? bounds,
    String query = '',
    String? locationType,
  });
}
