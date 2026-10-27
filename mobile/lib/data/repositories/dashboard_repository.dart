import '../models/association_dashboard.dart';

enum DashboardFailureKind {
  integration,
  offline,
  expired,
  denied,
  unavailable,
  rateLimited,
}

class DashboardFailure implements Exception {
  const DashboardFailure(this.kind);
  final DashboardFailureKind kind;

  String get message => switch (kind) {
    DashboardFailureKind.integration =>
      'Dashboard records are currently unavailable. Use your association web workspace.',
    DashboardFailureKind.offline =>
      'Unable to connect. Check your connection and retry. Dashboard records are not stored offline.',
    DashboardFailureKind.expired => 'Your session expired. Sign in again.',
    DashboardFailureKind.denied =>
      'Your account or association access is unavailable. Ask the administrator to review your active account link.',
    DashboardFailureKind.unavailable =>
      'Dashboard records could not be loaded. Please retry later.',
    DashboardFailureKind.rateLimited =>
      'Too many requests. Wait a minute before retrying.',
  };
}

abstract interface class DashboardRepository {
  Future<AssociationDashboard> load();
}

class UnavailableDashboardRepository implements DashboardRepository {
  const UnavailableDashboardRepository();

  @override
  Future<AssociationDashboard> load() async =>
      throw const DashboardFailure(DashboardFailureKind.integration);
}
