import 'support/test_tile_provider.dart';
import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:mobile/app/configuration/app_config.dart';
import 'package:mobile/core/networking/public_gis_client.dart';
import 'package:mobile/data/remote/api_public_projects_repository.dart';
import 'package:mobile/features/public_map/public_map_screen.dart';

import 'support/public_gis_fixtures.dart';

http.Response _json(Object value) => http.Response(
  jsonEncode(value),
  200,
  headers: {'content-type': 'application/json'},
);

Future<void> _open(
  WidgetTester tester,
  Future<http.Response> Function(http.Request) respond,
) async {
  final repository = ApiPublicProjectsRepository(
    PublicGisClient(
      baseUri: () => Uri.parse('https://backend.example.invalid'),
      clientFactory: () => MockClient(respond),
    ),
  );
  await tester.pumpWidget(
    MaterialApp(
      home: PublicMapScreen(
        tileProvider: TestTileProvider(),
        config: const AppConfig(gisMode: GisDataMode.live),
        repository: repository,
      ),
    ),
  );
}

void main() {
  testWidgets(
    'Live loading and empty states use fake HTTP without demo fallback',
    (tester) async {
      final response = Completer<http.Response>();
      await _open(tester, (_) => response.future);
      await tester.pump();
      expect(find.text('Loading public projects…'), findsOneWidget);
      expect(find.text('Log in'), findsOneWidget);
      response.complete(_json(overview(empty: true)));
      await tester.pumpAndSettle();
      expect(find.text('No public projects yet'), findsOneWidget);
      expect(find.byType(FlutterMap), findsNothing);
      expect(find.textContaining('Demo Coastal'), findsNothing);
    },
  );

  testWidgets(
    'Failed live HTTP can retry and never displays server diagnostics',
    (tester) async {
      var calls = 0;
      await _open(tester, (request) async {
        calls++;
        if (calls == 1) {
          return http.Response('synthetic private server diagnostic', 503);
        }
        return _json(
          request.url.queryParameters['mode'] == 'overview'
              ? overview()
              : mapResponse(),
        );
      });
      await tester.pumpAndSettle();
      expect(find.text('Public map data unavailable'), findsOneWidget);
      expect(find.textContaining('private server'), findsNothing);
      expect(find.byType(FlutterMap), findsNothing);
      await tester.tap(find.text('Retry'));
      await tester.pumpAndSettle();
      expect(calls, 3);
      expect(find.byType(FlutterMap), findsOneWidget);
      expect(
        find.byKey(const ValueKey('marker-demo-project-01')),
        findsNothing,
      );
    },
  );

  testWidgets(
    'Live boundaries preserve holes/parts and open details without artificial markers',
    (tester) async {
      await _open(
        tester,
        (request) async => _json(
          request.url.queryParameters['mode'] == 'overview'
              ? overview()
              : mapResponse(
                  features: [boundaryFeature(multipart: true)],
                  truncated: true,
                ),
        ),
      );
      await tester.pumpAndSettle();
      final polygons = tester
          .widget<PolygonLayer>(find.byType(PolygonLayer))
          .polygons;
      expect(polygons, hasLength(2));
      expect(polygons.first.holePointsList, hasLength(1));
      expect(
        tester.widget<MarkerLayer>(find.byType(MarkerLayer)).markers,
        isEmpty,
      );
      expect(find.byKey(const ValueKey('gis-truncated')), findsOneWidget);
      expect(find.byType(PolylineLayer), findsNothing);
      final entry = find.byKey(const ValueKey('project-$boundaryId'));
      await tester.ensureVisible(entry);
      await tester.tap(entry);
      await tester.pumpAndSettle();
      expect(find.text('Fictional published description'), findsOneWidget);
      expect(find.textContaining('null'), findsNothing);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'Only submitted search/view actions request data; empty viewport stays navigable',
    (tester) async {
      final requests = <http.Request>[];
      var emptyViewport = false;
      await _open(tester, (request) async {
        requests.add(request);
        return _json(
          request.url.queryParameters['mode'] == 'overview'
              ? overview()
              : emptyViewport
              ? mapResponse(features: [], emptyReason: 'outside_view')
              : mapResponse(),
        );
      });
      await tester.pumpAndSettle();
      expect(requests, hasLength(2));
      await tester.enterText(
        find.byKey(const ValueKey('map-search')),
        'Fictional',
      );
      await tester.pumpAndSettle();
      expect(requests, hasLength(2));
      await tester.tap(find.byTooltip('Submit public GIS search'));
      await tester.pumpAndSettle();
      expect(requests, hasLength(4));
      expect(requests.last.url.queryParameters['q'], 'Fictional');
      await tester.tap(find.byTooltip('Zoom in'));
      await tester.pumpAndSettle();
      expect(requests, hasLength(4));
      emptyViewport = true;
      final button = find.byKey(const ValueKey('search-map-view'));
      await tester.ensureVisible(button);
      await tester.tap(button);
      await tester.pumpAndSettle();
      expect(requests, hasLength(5));
      expect(requests.last.url.queryParameters['mode'], 'map');
      expect(find.text('No locations in this view'), findsOneWidget);
      expect(find.byType(FlutterMap), findsOneWidget);
      expect(
        tester.widget<MarkerLayer>(find.byType(MarkerLayer)).markers,
        isEmpty,
      );
    },
  );

  for (final size in [
    const Size(360, 800),
    const Size(640, 360),
    const Size(1200, 900),
  ]) {
    testWidgets('Live GIS handles viewport $size', (tester) async {
      tester.view.devicePixelRatio = 1;
      tester.view.physicalSize = size;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      await _open(
        tester,
        (request) async => _json(
          request.url.queryParameters['mode'] == 'overview'
              ? overview()
              : mapResponse(),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.byType(FlutterMap), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
}
