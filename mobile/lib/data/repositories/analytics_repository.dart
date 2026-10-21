import '../models/analytics_report.dart';

enum AnalyticsFailureKind {
  integration,
  offline,
  expired,
  denied,
  unavailable,
  rateLimited,
}

class AnalyticsFailure implements Exception {
  const AnalyticsFailure(this.kind);
  final AnalyticsFailureKind kind;

  String get message => switch (kind) {
    AnalyticsFailureKind.integration =>
      'Reports are unavailable until secure account sign-in is connected. Use your association web workspace.',
    AnalyticsFailureKind.offline =>
      'Unable to connect. Check your internet connection and retry. Reports are not available offline.',
    AnalyticsFailureKind.expired => 'Your session expired. Sign in again.',
    AnalyticsFailureKind.denied => 'Your account cannot access these reports.',
    AnalyticsFailureKind.unavailable =>
      'Reports are temporarily unavailable. Please retry later.',
    AnalyticsFailureKind.rateLimited =>
      'Too many requests. Wait a minute before retrying.',
  };
}

class AnalyticsPeriods {
  const AnalyticsPeriods({required this.years, required this.programYears});
  final List<int> years;
  final List<int> programYears;
}

abstract interface class AnalyticsRepository {
  Future<AnalyticsPeriods> periods();
  Future<AnalyticsReport> load(Map<String, String> filters);
}

class UnavailableAnalyticsRepository implements AnalyticsRepository {
  const UnavailableAnalyticsRepository();

  @override
  Future<AnalyticsPeriods> periods() async =>
      throw const AnalyticsFailure(AnalyticsFailureKind.integration);

  @override
  Future<AnalyticsReport> load(Map<String, String> filters) async =>
      throw const AnalyticsFailure(AnalyticsFailureKind.integration);
}
