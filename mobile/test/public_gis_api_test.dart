import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:mobile/core/errors/public_gis_failure.dart';
import 'package:mobile/core/networking/public_gis_client.dart';
import 'package:mobile/data/models/public_gis_feature.dart';
import 'package:mobile/data/remote/api_public_projects_repository.dart';

import 'support/public_gis_fixtures.dart';

Matcher failure(GisFailureCode code) => throwsA(
  isA<PublicGisFailure>().having((error) => error.code, 'code', code));

PublicGisClient clientFor(Future<http.Response> Function(http.Request) respond) =>
  PublicGisClient(baseUri: () => Uri.parse('https://backend.example.invalid/assocmap/'),
    clientFactory: () => MockClient(respond));

http.Response jsonResponse(Object json) => http.Response(jsonEncode(json), 200,
  headers: {'content-type': 'application/json; charset=utf-8'});

class _Transport extends http.BaseClient {
  _Transport(this.respond);
  final Future<http.StreamedResponse> Function(http.BaseRequest) respond;
  bool closed = false;
  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) => respond(request);
  @override
  void close() => closed = true;
}

void main() {
  test('Overview then map use only the public route and supported filters', () async {
    final requests = <http.Request>[];
    final repository = ApiPublicProjectsRepository(clientFor((request) async {
      requests.add(request);
      return jsonResponse(request.url.queryParameters['mode'] == 'overview'
        ? overview() : mapResponse(features: [boundaryFeature(multipart: true)]));
    }));
    final result = await repository.getMap(query: ' public ', locationType: 'project_boundary');
    expect(result.projects, hasLength(1));
    expect(requests, hasLength(2));
    for (final request in requests) {
      expect(request.method, 'GET');
      expect(request.url.path, '/assocmap/map/data');
      expect(request.headers['Accept'], 'application/json');
      expect(request.headers.containsKey('Authorization'), isFalse);
      expect(request.headers.containsKey('Cookie'), isFalse);
      expect(request.url.queryParameters['q'], 'public');
      expect(request.url.queryParameters['location_type'], 'project_boundary');
    }
    final query = requests.last.url.queryParameters;
    expect(query['bbox[0]'], '124.9');
    expect(query['bbox[1]'], '11.0');
    expect(query['bbox[2]'], '125.2');
    expect(query['bbox[3]'], '11.1');
    expect(query.keys, unorderedEquals(['mode', 'q', 'location_type',
      'bbox[0]', 'bbox[1]', 'bbox[2]', 'bbox[3]']));
  });

  test('Empty overview makes no map request and never returns synthetic records', () async {
    var calls = 0;
    final repository = ApiPublicProjectsRepository(clientFor((_) async {
      calls++;
      return jsonResponse(overview(empty: true));
    }));
    final result = await repository.getMap();
    expect(result.projects, isEmpty);
    expect(result.bounds, isNull);
    expect(result.emptyReason, 'no_records');
    expect(calls, 1);
  });

  test('Viewport request skips overview and retains empty/truncation metadata', () async {
    var calls = 0;
    final repository = ApiPublicProjectsRepository(clientFor((request) async {
      calls++;
      expect(request.url.queryParameters['mode'], 'map');
      return jsonResponse(mapResponse(features: [], truncated: true, emptyReason: 'display_limit'));
    }));
    final result = await repository.getMap(bounds: GisBounds.fromJson([124, 10, 126, 12]));
    expect(calls, 1);
    expect(result.truncated, isTrue);
    expect(result.emptyReason, 'display_limit');
  });

  test('Invalid queries are rejected before transport allocation', () async {
    final repository = ApiPublicProjectsRepository(clientFor((_) async {
      fail('Invalid query must not reach transport.');
    }));
    await expectLater(repository.getMap(query: List.filled(101, 'x').join()),
      failure(GisFailureCode.invalidQuery));
    await expectLater(repository.getMap(locationType: 'association_point'),
      failure(GisFailureCode.invalidQuery));
  });

  test('Missing/insecure configuration fails before creating a client', () async {
    for (final base in [null, Uri.parse('http://backend.example.invalid'),
      Uri.parse('https://user:synthetic@backend.example.invalid')]) {
      final client = PublicGisClient(baseUri: () => base,
        clientFactory: () { fail('Unsafe configuration must not allocate transport.'); });
      await expectLater(client.getData({'mode': 'overview'}), failure(GisFailureCode.configuration));
    }
  });

  test('Malformed overview, geometry and map metadata fail safely', () async {
    final bounds = GisBounds.fromJson([124, 10, 126, 12]);
    for (final json in [
      {'type': 'FeatureCollection', 'features': [], 'meta': {}},
      overview()..['features'] = [pointFeature()],
    ]) {
      await expectLater(ApiPublicProjectsRepository(clientFor((_) async => jsonResponse(json))).getMap(),
        failure(GisFailureCode.invalidResponse));
    }
    final invalid = pointFeature()..['geometry'] = {'type': 'LineString', 'coordinates': [[124, 11], [125, 12]]};
    await expectLater(ApiPublicProjectsRepository(clientFor((_) async =>
      jsonResponse(mapResponse(features: [invalid])))).getMap(bounds: bounds),
      failure(GisFailureCode.invalidResponse));
  });

  test('Transport failures do not leak diagnostics or substitute fixtures', () async {
    final repository = ApiPublicProjectsRepository(clientFor((_) async {
      throw http.ClientException('synthetic private transport detail');
    }));
    await expectLater(repository.getMap(), throwsA(isA<PublicGisFailure>()
      .having((error) => error.code, 'code', GisFailureCode.network)
      .having((error) => error.message, 'safe message', isNot(contains('private transport')))));
  });

  test('HTTP failures and throttling are explicit, with no automatic retries', () async {
    for (final status in [302, 403, 404, 422, 429, 500, 503]) {
      var calls = 0;
      final client = clientFor((_) async {
        calls++;
        return http.Response('synthetic server diagnostic', status);
      });
      await expectLater(client.getData({'mode': 'overview'}),
        failure(status == 429 ? GisFailureCode.rateLimited : GisFailureCode.http));
      expect(calls, 1);
    }
  });

  test('HTML, malformed JSON and invalid top-level JSON are rejected', () async {
    for (final response in [
      http.Response('<html>proxy challenge</html>', 200, headers: {'content-type': 'text/html'}),
      http.Response('not json', 200, headers: {'content-type': 'application/json'}),
      jsonResponse([]),
    ]) {
      await expectLater(clientFor((_) async => response).getData({}),
        failure(GisFailureCode.invalidResponse));
    }
  });

  test('Requests disable redirects and close their owned client on success', () async {
    late _Transport transport;
    transport = _Transport((request) async {
      expect(request.followRedirects, isFalse);
      expect(request.headers.keys, unorderedEquals(['Accept']));
      return http.StreamedResponse(Stream.value(utf8.encode(jsonEncode(overview()))), 200,
        headers: {'content-type': 'application/json'});
    });
    final client = PublicGisClient(baseUri: () => Uri.parse('https://backend.example.invalid'),
      clientFactory: () => transport);
    await client.getData({'mode': 'overview'});
    expect(transport.closed, isTrue);
  });

  test('Streaming bodies are bounded even when content length is absent', () async {
    final transport = _Transport((_) async => http.StreamedResponse(
      Stream.fromIterable([List.filled(6, 32), List.filled(6, 32)]), 200,
      headers: {'content-type': 'application/json'}));
    final client = PublicGisClient(baseUri: () => Uri.parse('https://backend.example.invalid'),
      clientFactory: () => transport, maxResponseBytes: 10);
    await expectLater(client.getData({}), failure(GisFailureCode.invalidResponse));
    expect(transport.closed, isTrue);
  });

  test('Timeout covers a stalled response body and cancels/closes transport', () async {
    var cancelled = false;
    final body = StreamController<List<int>>(onCancel: () { cancelled = true; });
    final transport = _Transport((_) async => http.StreamedResponse(body.stream, 200,
      headers: {'content-type': 'application/json'}));
    final client = PublicGisClient(baseUri: () => Uri.parse('https://backend.example.invalid'),
      clientFactory: () => transport, timeout: const Duration(milliseconds: 10));
    await expectLater(client.getData({}), failure(GisFailureCode.timeout));
    expect(cancelled, isTrue);
    expect(transport.closed, isTrue);
    await body.close();
  });

  test('Timeout also covers waiting for response headers', () async {
    final pending = Completer<http.StreamedResponse>();
    final transport = _Transport((_) => pending.future);
    final client = PublicGisClient(baseUri: () => Uri.parse('https://backend.example.invalid'),
      clientFactory: () => transport, timeout: const Duration(milliseconds: 10));
    await expectLater(client.getData({}), failure(GisFailureCode.timeout));
    expect(transport.closed, isTrue);
    pending.complete(http.StreamedResponse(const Stream.empty(), 200));
    await Future<void>.delayed(Duration.zero);
  });
}
