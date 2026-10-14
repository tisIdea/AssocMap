import '../models/public_project.dart';

abstract interface class PublicProjectsRepository {
  Future<List<PublicProject>> getPublicProjects();
}
