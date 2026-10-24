import '../../app/configuration/app_config.dart';
import '../../core/networking/laravel_api_client.dart';
import '../models/association_dashboard.dart';
import '../repositories/dashboard_repository.dart';

// The approved session coordinator must verify status codes and own credentials.
typedef AuthenticatedDashboardRead =
    Future<Map<String, dynamic>> Function(Uri uri);

class ApiDashboardRepository implements DashboardRepository {
  const ApiDashboardRepository({required this.config, required this.read});
  final AppConfig config;
  final AuthenticatedDashboardRead read;

  @override
  Future<AssociationDashboard> load() async {
    final base = config.backendBaseUri;
    if (base == null) {
      throw const DashboardFailure(DashboardFailureKind.integration);
    }
    final prefix = base.path.replaceFirst(RegExp(r'/+$'), '');
    final uri = base.replace(path: '$prefix/api/v1/association/dashboard');
    Map<String, dynamic> response;
    try {
      response = await read(uri);
    } on ApiFailure catch (error) {
      throw DashboardFailure(switch (error.kind) {
        ApiFailureKind.unauthenticated => DashboardFailureKind.expired,
        ApiFailureKind.forbidden => DashboardFailureKind.denied,
        ApiFailureKind.offline => DashboardFailureKind.offline,
        ApiFailureKind.rateLimited => DashboardFailureKind.rateLimited,
        _ => DashboardFailureKind.unavailable,
      });
    }
    final data = response['data'];
    if (data is! Map<String, dynamic>) {
      throw const FormatException('Invalid dashboard response.');
    }
    return AssociationDashboard.fromJson(data);
  }
}
