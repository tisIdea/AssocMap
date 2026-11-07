import '../models/public_gis_feature.dart';
import '../models/public_project.dart';
import '../models/gis_site_type.dart';

abstract interface class PublicProjectsRepository {
  Future<List<PublicProject>> getPublicProjects();
}

abstract interface class ViewportPublicProjectsRepository
    implements PublicProjectsRepository {
  Future<PublicGisResult> getMap({
    GisBounds? bounds,
    String query = '',
    String? locationType,
    String? siteTypeId,
  });
}

abstract interface class GisSiteTypesRepository
    implements PublicProjectsRepository {
  Future<List<GisSiteType>> getSiteTypes();
}
