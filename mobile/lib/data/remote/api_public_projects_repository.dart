import '../../core/errors/app_failure.dart';
import '../models/public_project.dart';
import '../repositories/public_projects_repository.dart';

class ApiPublicProjectsRepository implements PublicProjectsRepository {
  const ApiPublicProjectsRepository();

  @override
  Future<List<PublicProject>> getPublicProjects() async {
    throw const AppFailure(
      FailureKind.integrationUnavailable,
      'Live GIS integration is not configured. Desktop 1 must supply and verify '
      'the public Laravel API contract before this mode can load records.',
    );
  }
}
