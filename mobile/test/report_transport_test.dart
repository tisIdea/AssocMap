import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:mobile/core/networking/bounded_http_client.dart';
import 'package:mobile/core/networking/laravel_api_client.dart';
import 'package:mobile/data/models/analytics_report.dart';
import 'package:mobile/data/remote/api_analytics_repository.dart';
import 'package:mobile/data/repositories/analytics_repository.dart';
import 'package:mobile/features/reports/association_analytics_screen.dart';

import 'analytics_report_test.dart' show report;
import 'mobile_m1_auth_test.dart' show config;

class _Transport extends http.BaseClient {
  _Transport(this.reply);
  final Future<http.StreamedResponse> Function(http.BaseRequest) reply;
  bool closed = false;

  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) =>
      reply(request);

  @override
  void close() => closed = true;
}

final _reportUri = Uri.parse(
  'https://backend.example.test/api/v1/association/reports?report_type=income',
);

http.StreamedResponse _response(
  String body, {
  int status = 200,
  int? contentLength,
}) => http.StreamedResponse(
  Stream.value(utf8.encode(body)),
  status,
  contentLength: contentLength,
  headers: {'content-type': 'application/json'},
);

LaravelApiClient _api(_Transport Function() factory) => LaravelApiClient(
  config: config,
  client: BoundedHttpClient(clientFactory: factory),
);

ApiAnalyticsRepository _analytics(LaravelApiClient api) =>
    ApiAnalyticsRepository(
      config: config,
      read: (uri) => api.get(uri, 'synthetic-token'),
    );

Matcher _failure(ApiFailureKind kind) =>
    isA<ApiFailure>().having((error) => error.kind, 'kind', kind);

Map<String, dynamic> _largeReport(String cell, int rows) {
  final data = report();
  data['sections'] = [
    {
      'key': 'synthetic',
      'title': 'Synthetic rows',
      'columns': [
        {'key': 'name', 'label': 'Name', 'type': 'text'},
      ],
      'rows': List.generate(rows, (_) => {'name': cell}),
      'measures': <Object>[],
      'notes': <String>[],
      'chart': <String, dynamic>{},
    },
  ];
  return data;
}

class _PendingAnalytics implements AnalyticsRepository {
  final pending = Completer<AnalyticsReport>();

  @override
  Future<AnalyticsPeriods> periods() async =>
      AnalyticsPeriods(years: [2026], programYears: [2026]);

  @override
  Future<AnalyticsReport> load(Map<String, String> filters) => pending.future;
}

void main() {
  test(
    'small normal report retains the report contract and safe GET',
    () async {
      final transport = _Transport((request) async {
        expect(request.method, 'GET');
        expect(request.followRedirects, isFalse);
        expect(request.headers['Authorization'], 'Bearer synthetic-token');
        return _response(jsonEncode({'data': report()}));
      });
      final api = _api(() => transport);
      addTearDown(api.close);
      final result = await _analytics(api).load({'report_type': 'income'});
      expect(result.sections.first.measures.first.value, '0.00');
      expect(transport.closed, isTrue);
    },
  );

  test('valid report above the former 4 MiB cap parses successfully', () async {
    final body = jsonEncode({'data': _largeReport('a' * 1000, 4500)});
    expect(utf8.encode(body).length, greaterThan(4 * 1024 * 1024));
    final api = _api(() => _Transport((_) async => _response(body)));
    addTearDown(api.close);
    final result = await _analytics(api).load({'report_type': 'members'});
    expect(result.sections.single.rows, hasLength(4500));
  });

  test(
    'near-8 MiB engine output survives Laravel Unicode expansion and envelope',
    () async {
      final data = _largeReport('é' * 1000, 4100);
      final engineBody = jsonEncode({
        'ok': true,
        'version': AnalyticsReport.version,
        'result': data,
      });
      expect(
        utf8.encode(engineBody).length,
        lessThanOrEqualTo(8 * 1024 * 1024),
      );
      expect(utf8.encode(engineBody).length, greaterThan(7800 * 1024));
      // Mirrors Laravel's default JSON_UNESCAPED_UNICODE=off encoding.
      final body = jsonEncode({'data': data}).replaceAll('é', r'\u00e9');
      expect(body.length, greaterThan(23 * 1024 * 1024));
      expect(body.length, lessThan(LaravelApiClient.reportMaxBytes));
      final api = _api(() => _Transport((_) async => _response(body)));
      addTearDown(api.close);
      final result = await _analytics(api).load({'report_type': 'members'});
      expect(result.sections.single.rows.last['name'], 'é' * 1000);
    },
  );

  testWidgets('slow valid headers and body share the report deadline', (
    tester,
  ) async {
    final body = StreamController<List<int>>();
    final api = _api(
      () => _Transport((_) async {
        await Future<void>.delayed(const Duration(seconds: 35));
        return http.StreamedResponse(
          body.stream,
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );
    addTearDown(api.close);
    final pending = _analytics(api).load({'report_type': 'income'});
    await tester.pump(const Duration(seconds: 35));
    await tester.pump(const Duration(seconds: 45));
    body.add(utf8.encode(jsonEncode({'data': report()})));
    unawaited(body.close());
    await tester.pump();
    expect((await pending).title, 'Synthetic income report');
  });

  for (final stalledHeaders in [true, false]) {
    testWidgets(
      '120s total deadline cancels stalled ${stalledHeaders ? 'headers' : 'body'}',
      (tester) async {
        var cancelled = false;
        final body = StreamController<List<int>>(
          onCancel: () {
            cancelled = true;
          },
        );
        final headers = Completer<http.StreamedResponse>();
        final transport = _Transport((_) => headers.future);
        final api = _api(() => transport);
        addTearDown(api.close);
        var done = false;
        final check = expectLater(
          api.get(_reportUri, 'synthetic-token').whenComplete(() {
            done = true;
          }),
          throwsA(_failure(ApiFailureKind.offline)),
        );
        await tester.pump(const Duration(seconds: 60));
        if (!stalledHeaders) {
          headers.complete(http.StreamedResponse(body.stream, 200));
        }
        await tester.pump(const Duration(seconds: 59));
        expect(done, isFalse);
        if (!stalledHeaders) body.add([32]); // Activity never resets the timer.
        await tester.pump(const Duration(seconds: 1));
        await check;
        expect(transport.closed, isTrue);
        if (stalledHeaders) {
          headers.complete(http.StreamedResponse(body.stream, 200));
          await tester.pump();
        }
        expect(cancelled, isTrue);
        unawaited(body.close());
      },
    );
  }

  test('real timer closes and unsubscribes from a stalled body', () async {
    var cancelled = false;
    final body = StreamController<List<int>>(
      onCancel: () {
        cancelled = true;
      },
    );
    final transport = _Transport(
      (_) async => http.StreamedResponse(body.stream, 200),
    );
    final client = BoundedHttpClient(
      clientFactory: () => transport,
      timeout: const Duration(milliseconds: 20),
    );
    addTearDown(client.close);
    await expectLater(client.get(_reportUri), throwsA(isA<TimeoutException>()));
    expect(transport.closed, isTrue);
    expect(cancelled, isTrue);
    await body.close();
  });

  for (final declaredLength in [true, false]) {
    test(
      'oversized report is rejected with ${declaredLength ? 'declared' : 'streamed'} length',
      () async {
        var cancelled = false;
        final chunk = Uint8List(1024 * 1024);
        final body = StreamController<List<int>>(
          onListen: () {},
          onCancel: () {
            cancelled = true;
          },
        );
        final transport = _Transport(
          (_) async => http.StreamedResponse(
            body.stream,
            200,
            contentLength: declaredLength
                ? LaravelApiClient.reportMaxBytes + 1
                : null,
            headers: {'content-type': 'application/json'},
          ),
        );
        final api = _api(() => transport);
        addTearDown(api.close);
        final check = expectLater(
          api.get(_reportUri, 'synthetic-token'),
          throwsA(_failure(ApiFailureKind.invalidResponse)),
        );
        if (!declaredLength) {
          for (var i = 0; i < 24; ++i) {
            body.add(chunk);
          }
          body.add(Uint8List(1025));
        }
        await check;
        expect(cancelled, isTrue);
        expect(transport.closed, isTrue);
        await body.close();
      },
    );
  }

  for (final entry in {
    401: ApiFailureKind.unauthenticated,
    403: ApiFailureKind.forbidden,
    429: ApiFailureKind.rateLimited,
    503: ApiFailureKind.unavailable,
  }.entries) {
    test(
      'report HTTP ${entry.key} retains its classification without transport retry',
      () async {
        var sends = 0;
        final api = _api(
          () => _Transport((_) async {
            sends++;
            return _response('{}', status: entry.key);
          }),
        );
        addTearDown(api.close);
        await expectLater(
          api.get(_reportUri, 'synthetic-token'),
          throwsA(_failure(entry.value)),
        );
        expect(sends, 1);
      },
    );
  }

  for (final body in ['{', '[]', '{"data": []}', '{"data": {}, "error": {}}']) {
    test('malformed or invalid report JSON fails closed: $body', () async {
      final api = _api(() => _Transport((_) async => _response(body)));
      addTearDown(api.close);
      await expectLater(
        api.get(_reportUri, 'synthetic-token'),
        throwsA(_failure(ApiFailureKind.invalidResponse)),
      );
    });
  }

  test(
    'cancelling a report settles it before late headers and permits another request',
    () async {
      final headers = Completer<http.StreamedResponse>();
      final first = _Transport((_) => headers.future);
      var sends = 0;
      final api = _api(
        () => ++sends == 1
            ? first
            : _Transport(
                (_) async => _response(jsonEncode({'data': report()})),
              ),
      );
      addTearDown(api.close);
      final check = expectLater(
        api.get(_reportUri, 'synthetic-token'),
        throwsA(_failure(ApiFailureKind.offline)),
      );
      api.cancelActive();
      await check;
      expect(first.closed, isTrue);
      var discarded = false;
      final body = StreamController<List<int>>(
        onCancel: () {
          discarded = true;
        },
      );
      headers.complete(http.StreamedResponse(body.stream, 200));
      await Future<void>.delayed(Duration.zero);
      expect(discarded, isTrue);
      await body.close();
      expect(
        (await _analytics(api).load({'report_type': 'income'})).empty,
        isFalse,
      );
    },
  );

  for (final suffix in [
    'me',
    'association/dashboard',
    'association/reports/options',
  ]) {
    testWidgets(
      '$suffix retains its 25-second deadline during a concurrent report',
      (tester) async {
        final api = _api(
          () => _Transport((_) => Completer<http.StreamedResponse>().future),
        );
        addTearDown(api.close);
        var reportDone = false;
        final reportCheck = expectLater(
          api.get(_reportUri, 'synthetic-token').whenComplete(() {
            reportDone = true;
          }),
          throwsA(_failure(ApiFailureKind.offline)),
        );
        final normalCheck = expectLater(
          api.get(
            Uri.parse('https://backend.example.test/api/v1/$suffix'),
            'synthetic-token',
          ),
          throwsA(_failure(ApiFailureKind.offline)),
        );
        await tester.pump(const Duration(seconds: 25));
        await normalCheck;
        expect(reportDone, isFalse);
        api.cancelActive();
        await tester.pump();
        await reportCheck;
      },
    );

    test('$suffix retains its 4 MiB response limit', () async {
      final api = _api(
        () => _Transport(
          (_) async => _response('{}', contentLength: 4 * 1024 * 1024 + 1),
        ),
      );
      addTearDown(api.close);
      await expectLater(
        api.get(
          Uri.parse('https://backend.example.test/api/v1/$suffix'),
          'synthetic-token',
        ),
        throwsA(_failure(ApiFailureKind.invalidResponse)),
      );
    });
  }

  testWidgets('disposing the report screen while loading discards late data', (
    tester,
  ) async {
    final repository = _PendingAnalytics();
    await tester.pumpWidget(
      MaterialApp(home: AssociationAnalyticsScreen(repository: repository)),
    );
    await tester.pump();
    expect(find.byType(CircularProgressIndicator), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
    repository.pending.complete(AnalyticsReport.fromJson(report()));
    await tester.pump();
    expect(tester.takeException(), isNull);
    expect(find.text('Synthetic income report'), findsNothing);
  });
}
