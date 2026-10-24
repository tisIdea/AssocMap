import '../../app/configuration/app_config.dart';
import '../../core/networking/laravel_api_client.dart';
import '../models/analytics_report.dart';
import '../repositories/analytics_repository.dart';

// Uses the session coordinator's authenticated JSON reader.
// This adapter does not create, store, refresh or log credentials.
typedef AuthenticatedAnalyticsRead =
    Future<Map<String, dynamic>> Function(Uri uri);

class ApiAnalyticsRepository implements AnalyticsRepository {
  const ApiAnalyticsRepository({required this.config, required this.read});
  final AppConfig config;
  final AuthenticatedAnalyticsRead read;

  Future<Map<String, dynamic>> _read(Uri uri) async {
    try {
      return await read(uri);
    } on ApiFailure catch (error) {
      throw AnalyticsFailure(switch (error.kind) {
        ApiFailureKind.unauthenticated => AnalyticsFailureKind.expired,
        ApiFailureKind.forbidden => AnalyticsFailureKind.denied,
        ApiFailureKind.offline => AnalyticsFailureKind.offline,
        ApiFailureKind.rateLimited => AnalyticsFailureKind.rateLimited,
        _ => AnalyticsFailureKind.unavailable,
      });
    }
  }

  Uri _uri(String suffix, Map<String, String> filters) {
    final base = config.backendBaseUri;
    if (base == null) {
      throw const AnalyticsFailure(AnalyticsFailureKind.integration);
    }
    final prefix = base.path.replaceFirst(RegExp(r'/+$'), '');
    return base.replace(
      path: '$prefix/api/v1/association/reports$suffix',
      queryParameters: filters,
    );
  }

  @override
  Future<AnalyticsPeriods> periods() async {
    final response = await _read(_uri('/options', const {}));
    final data = response['data'];
    if (data is! Map<String, dynamic>) {
      throw const FormatException('Invalid reporting options.');
    }
    List<int> years(dynamic value) {
      if (value is! List || value.length > 101) {
        throw const FormatException('Invalid reporting periods.');
      }
      return value
          .map((year) {
            final parsed = year is int ? year : int.tryParse(year.toString());
            if (parsed == null || parsed < 2000 || parsed > 2100) {
              throw const FormatException('Invalid reporting year.');
            }
            return parsed;
          })
          .toList(growable: false);
    }

    return AnalyticsPeriods(
      years: years(data['years']),
      programYears: years(data['program_years']),
    );
  }

  @override
  Future<AnalyticsReport> load(Map<String, String> filters) async {
    if (filters.keys.any(
      (key) => ![
        'report_type',
        'year',
        'quarter',
        'month',
        'program_year',
      ].contains(key),
    )) {
      throw const FormatException('Unsupported mobile report filter.');
    }
    final response = await _read(_uri('', filters));
    final data = response['data'];
    if (data is! Map<String, dynamic>) {
      throw const FormatException('Invalid reporting response.');
    }
    return AnalyticsReport.fromJson(data);
  }
}
