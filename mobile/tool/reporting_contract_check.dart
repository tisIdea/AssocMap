import 'dart:convert';
import 'dart:io';

import 'package:mobile/app/configuration/app_config.dart';
import 'package:mobile/data/models/analytics_report.dart';
import 'package:mobile/data/models/association_dashboard.dart';
import 'package:mobile/data/remote/api_analytics_repository.dart';
import 'package:mobile/data/repositories/analytics_repository.dart';

// Uses synthetic exports from the isolated PostgreSQL harness; no network or session.
Future<void> main(List<String> arguments) async {
  if (arguments.length != 1) throw ArgumentError('Provide the synthetic export-evidence directory.');
  final directory = Directory(arguments.single);
  final results = <Map<String, String>>[];
  Future<void> check(String name, Future<void> Function() test) async {
    try {
      await test();
      results.add({'test': name, 'status': 'PASS'});
    } catch (error) {
      results.add({'test': name, 'status': 'FAIL', 'error': error.toString()});
    }
  }
  void require(bool condition, String message) {
    if (!condition) throw StateError(message);
  }
  Map<String, dynamic> read(String name) =>
      jsonDecode(File('${directory.path}/$name.json').readAsStringSync()) as Map<String, dynamic>;
  void invalid(void Function() parse) {
    try { parse(); } on FormatException { return; }
    throw StateError('Invalid contract was accepted.');
  }
  for (final type in ['comprehensive', 'associations', 'members', 'projects', 'trainings', 'production', 'income', 'activities']) {
    await check('actual Laravel Python result parses in Dart: $type', () async {
      final report = AnalyticsReport.fromJson(read(type));
      require(report.sections.isNotEmpty && report.scope.isNotEmpty, 'Recorded report was lost.');
    });
  }
  await check('recorded zero monthly values retain their exact representation', () async {
    final report = AnalyticsReport.fromJson(read('production'));
    final section = report.sections.firstWhere((section) => section.columns.any((column) => column.key == 'activities'));
    require(section.rows.any((row) => row['activities'] == 0 && row['quantity'] == '0.00'), 'Recorded zero was converted to missing.');
  });
  await check('completed and terminated remain separate mobile categories', () async {
    final report = AnalyticsReport.fromJson(read('projects'));
    final rows = report.sections.firstWhere((section) => section.columns.any((column) => column.key == 'status')).rows;
    require(rows.any((row) => row['status'] == 'completed') && rows.any((row) => row['status'] == 'terminated'), 'Final outcomes were merged.');
  });
  await check('unsupported versions and unknown row fields fail closed', () async {
    final report = read('production')..['version'] = 'invalid';
    invalid(() => AnalyticsReport.fromJson(report));
    final malformed = read('production');
    ((malformed['sections'] as List).first['rows'] as List).first['unexpected'] = 'invalid';
    invalid(() => AnalyticsReport.fromJson(malformed));
  });
  const config = AppConfig(mode: DataMode.api, backendBaseUrl: 'https://backend.example.invalid/assocmap');
  await check('API adapter uses the authenticated-reader boundary and application prefix', () async {
    Uri? requested;
    final repository = ApiAnalyticsRepository(config: config, read: (uri) async {
      requested = uri;
      return {'data': read('production')};
    });
    await repository.load({'report_type': 'production', 'year': '2026', 'month': '1'});
    require(requested?.path == '/assocmap/api/v1/association/reports' && requested?.queryParameters['month'] == '1', 'Incorrect report URL or filters.');
  });
  await check('mobile cannot submit an arbitrary association filter', () async {
    var called = false;
    final repository = ApiAnalyticsRepository(config: config, read: (_) async { called = true; return {}; });
    try { await repository.load({'association_id': 'invalid'}); } on FormatException {
      require(!called, 'Unsupported filter reached the reader.');
      return;
    }
    throw StateError('Unsupported filter was accepted.');
  });
  await check('period options remain typed and reject missing years', () async {
    final repository = ApiAnalyticsRepository(config: config, read: (_) async => {'data': {'years': [2026], 'program_years': [2026]}});
    require((await repository.periods()).years.single == 2026, 'Recorded year lost.');
    final invalidRepository = ApiAnalyticsRepository(config: config, read: (_) async => {'data': {'years': [null], 'program_years': [2026]}});
    try { await invalidRepository.periods(); } on FormatException { return; }
    throw StateError('Null year was accepted.');
  });
  await check('default reporting repository cannot fabricate live data', () async {
    try { await const UnavailableAnalyticsRepository().load({'report_type': 'members'}); }
    on AnalyticsFailure catch (error) { require(error.kind == AnalyticsFailureKind.integration, 'Wrong integration boundary.'); return; }
    throw StateError('Unavailable repository returned data.');
  });
  await check('actual account dashboard parses with its own association only', () async {
    final data = read('association-dashboard');
    final dashboard = AssociationDashboard.fromJson(data);
    require(dashboard.associationId == data['association']['id'], 'Dashboard scope changed.');
    data['role'] = 'system_admin';
    invalid(() => AssociationDashboard.fromJson(data));
  });
  stdout.writeln(const JsonEncoder.withIndent('  ').convert(results));
  if (results.any((result) => result['status'] == 'FAIL')) exitCode = 1;
}
