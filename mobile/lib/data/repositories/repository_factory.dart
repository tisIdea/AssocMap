import '../../app/configuration/app_config.dart';
import '../../core/errors/app_failure.dart';
import '../mock/demo_auth_repository.dart';
import '../mock/mock_public_projects_repository.dart';
import '../models/public_project.dart';
import '../remote/api_public_projects_repository.dart';
import '../remote/api_auth_repository.dart';
import 'auth_repository.dart';
import 'public_projects_repository.dart';

abstract final class RepositoryFactory {
  static AuthRepository authentication(AppConfig config) =>
      switch (config.mode) {
        DataMode.mock => DemoAuthRepository(),
        DataMode.api => const ApiAuthRepository(),
        DataMode.invalid => const ApiAuthRepository(invalidConfiguration: true),
      };

  static PublicProjectsRepository publicProjects(AppConfig config) =>
      switch (config.mode) {
        DataMode.mock => MockPublicProjectsRepository(),
        DataMode.api => const ApiPublicProjectsRepository(),
        DataMode.invalid => const _InvalidConfigurationRepository(),
      };
}

class _InvalidConfigurationRepository implements PublicProjectsRepository {
  const _InvalidConfigurationRepository();

  @override
  Future<List<PublicProject>> getPublicProjects() async {
    throw const AppFailure(
      FailureKind.invalidConfiguration,
      'Invalid data mode. Configure ASSOCMAP_DATA_MODE as mock or api.',
    );
  }
}
