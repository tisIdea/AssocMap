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
import 'package:mobile/features/public_map/gis_site_symbols.dart';

import 'support/public_gis_fixtures.dart';

http.Response _json(Object value) => http.Response(
  jsonEncode(value),
  200,
  headers: {'content-type': 'application/json'},
);

Future<void> _open(
  WidgetTester tester,
  Future<http.Response> Function(http.Request) respond, {
  Future<http.Response> Function(http.Request)? catalogRespond,
  double textScale = 1,
}) async {
  final repository = ApiPublicProjectsRepository(
    PublicGisClient(
      baseUri: () => Uri.parse('https://backend.example.invalid'),
      clientFactory: () => MockClient(
        (request) => request.url.path.endsWith('/site-types')
            ? catalogRespond?.call(request) ??
                  Future.value(_json(siteCatalog()))
            : respond(request),
      ),
    ),
  );
  await tester.pumpWidget(
    MaterialApp(
      builder: (context, child) => MediaQuery(
        data: MediaQuery.of(
          context,
        ).copyWith(textScaler: TextScaler.linear(textScale)),
        child: child!,
      ),
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
    'Classification visuals stay consistent across legend, markers, list and selected details',
    (tester) async {
      tester.view.devicePixelRatio = 1;
      tester.view.physicalSize = const Size(1200, 1000);
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      const codes = [
        'fish_cage',
        'fish_pen',
        'culture_pond',
        'seaweed_cultivation_site',
        'hatchery_nursery',
        'payao_installation',
        'post_harvest_facility',
        null,
      ];
      final features = [
        for (var index = 0; index < codes.length; index++)
          (index.isOdd ? classifiedBoundary() : pointFeature())
            ..['id'] =
                '11111111-1111-4111-8111-${index.toString().padLeft(12, '0')}',
      ];
      for (var index = 0; index < codes.length; index++) {
        final code = codes[index];
        (features[index]['properties'] as Map<String, dynamic>).addAll({
          'title': 'Visual site $index',
          'site_type_id': code == null ? null : index + 1,
          'site_type_code': code,
          'site_type_name': code == null ? null : 'Classified $code',
        });
      }
      final before = jsonEncode(features);
      final requests = <http.Request>[];
      await _open(
        tester,
        (request) async {
          requests.add(request);
          return _json(
            request.url.queryParameters['mode'] == 'overview'
                ? overview()
                : mapResponse(features: features),
          );
        },
        catalogRespond: (request) async {
          requests.add(request);
          return _json({
            'data': [
              for (var index = 0; index < codes.length - 1; index++)
                {
                  'id': index + 1,
                  'code': codes[index],
                  'name': 'Classified ${codes[index]}',
                },
            ],
          });
        },
      );
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text('Map legend'));
      await tester.tap(find.text('Map legend'));
      await tester.pumpAndSettle();
      final requestCount = requests.length;
      for (var index = 0; index < codes.length; index++) {
        final code = codes[index];
        final label = code == null ? 'Unclassified' : 'Classified $code';
        final legend = tester.widget<ListTile>(
          find.widgetWithText(
            ListTile,
            code == null ? 'Unclassified / unknown symbol' : label,
          ),
        );
        expect((legend.leading! as Icon).icon, gisSiteSymbol(code));
        expect((legend.leading! as Icon).color, gisSiteColor(code));
        final marker = find.byKey(ValueKey('marker-${features[index]['id']}'));
        final button = tester.widget<IconButton>(marker);
        expect((button.icon as Icon).icon, gisSiteSymbol(code));
        expect(button.color, gisSiteColor(code));
        button.onPressed!();
        await tester.pumpAndSettle();
        final detailRow = find
            .ancestor(
              of: find.text('Site classification: $label'),
              matching: find.byType(Row),
            )
            .first;
        final detailIcon = tester.widget<Icon>(
          find.descendant(of: detailRow, matching: find.byType(Icon)),
        );
        expect(detailIcon.icon, gisSiteSymbol(code));
        expect(detailIcon.color, gisSiteColor(code));
        await tester.tap(find.byTooltip('Close location details'));
        await tester.pumpAndSettle();
        final decoration =
            tester
                    .widget<DecoratedBox>(
                      find
                          .ancestor(
                            of: marker,
                            matching: find.byType(DecoratedBox),
                          )
                          .first,
                    )
                    .decoration
                as BoxDecoration;
        expect(
          decoration.shape,
          index.isOdd ? BoxShape.rectangle : BoxShape.circle,
        );
        expect((decoration.border! as Border).top.color, gisSelectionColor);
        expect(tester.widget<IconButton>(marker).color, gisSiteColor(code));
        final list = find.byKey(ValueKey('project-${features[index]['id']}'));
        await tester.scrollUntilVisible(
          list,
          100,
          scrollable: find.descendant(
            of: find.byKey(const ValueKey('public-project-list')),
            matching: find.byType(Scrollable),
          ),
        );
        final avatar = tester.widget<ListTile>(list).leading! as CircleAvatar;
        expect((avatar.child! as Icon).icon, gisSiteSymbol(code));
        expect((avatar.child! as Icon).color, gisSiteColor(code));
      }
      expect(requests.length, requestCount);
      expect(jsonEncode(features), before);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'Live loading and empty states use fake HTTP without demo fallback',
    (tester) async {
      final response = Completer<http.Response>();
      await _open(tester, (_) => response.future);
      await tester.pump();
      expect(find.text('Loading public locations…'), findsOneWidget);
      expect(find.text('Log in'), findsOneWidget);
      response.complete(_json(overview(empty: true)));
      await tester.pumpAndSettle();
      expect(find.text('No public locations found'), findsOneWidget);
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
    'Older boundaries stay in the list with an explicit missing-marker explanation',
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
      expect(find.byType(PolygonLayer), findsNothing);
      expect(
        tester.widget<MarkerLayer>(find.byType(MarkerLayer)).markers,
        isEmpty,
      );
      expect(find.byKey(const ValueKey('gis-truncated')), findsOneWidget);
      expect(find.byType(PolylineLayer), findsNothing);
      final entry = find.byKey(const ValueKey('project-$boundaryId'));
      final title = find.descendant(
        of: entry,
        matching: find.text('Synthetic public boundary'),
      );
      await tester.ensureVisible(title);
      await tester.tap(title);
      await tester.pumpAndSettle();
      expect(find.text('Fictional published description'), findsOneWidget);
      expect(
        find.textContaining(
          'Representative marker unavailable from this server',
        ),
        findsOneWidget,
      );
      expect(find.textContaining('null'), findsNothing);
      expect(tester.takeException(), isNull);
    },
  );

  for (final multipart in [false, true]) {
    testWidgets(
      'Public boundary uses its server marker and keeps original geometry (multipart=$multipart)',
      (tester) async {
        await _open(
          tester,
          (request) async => _json(
            request.url.queryParameters['mode'] == 'overview'
                ? overview()
                : mapResponse(
                    features: [
                      pointFeature(),
                      classifiedBoundary(multipart: multipart),
                    ],
                  ),
          ),
        );
        await tester.pumpAndSettle();
        expect(find.byType(PolygonLayer), findsNothing);
        final markers = tester
            .widget<MarkerLayer>(find.byType(MarkerLayer))
            .markers;
        expect(markers, hasLength(2));
        expect(markers.last.point.latitude, 11.06);
        expect(markers.last.point.longitude, 124.96);
        final pointDecoration =
            tester
                    .widget<DecoratedBox>(
                      find
                          .ancestor(
                            of: find.byKey(const ValueKey('marker-$pointId')),
                            matching: find.byType(DecoratedBox),
                          )
                          .first,
                    )
                    .decoration
                as BoxDecoration;
        final boundaryDecoration =
            tester
                    .widget<DecoratedBox>(
                      find
                          .ancestor(
                            of: find.byKey(
                              const ValueKey('marker-$boundaryId'),
                            ),
                            matching: find.byType(DecoratedBox),
                          )
                          .first,
                    )
                    .decoration
                as BoxDecoration;
        expect(pointDecoration.shape, BoxShape.circle);
        expect(boundaryDecoration.shape, BoxShape.rectangle);
        await tester.tap(find.byKey(const ValueKey('marker-$boundaryId')));
        await tester.pumpAndSettle();
        expect(find.text('Geometry type: Project Boundary'), findsOneWidget);
        expect(find.text('Site classification: Synthetic cage'), findsWidgets);
        expect(find.textContaining('all parts and holes'), findsOneWidget);
        expect(find.text('Fictional published description'), findsOneWidget);
        await tester.tap(find.byTooltip('Close location details'));
        await tester.pumpAndSettle();
        final selected =
            tester
                    .widget<DecoratedBox>(
                      find
                          .ancestor(
                            of: find.byKey(
                              const ValueKey('marker-$boundaryId'),
                            ),
                            matching: find.byType(DecoratedBox),
                          )
                          .first,
                    )
                    .decoration
                as BoxDecoration;
        expect((selected.border as Border).top.width, 4);
        final semantics = tester.widget<Semantics>(
          find
              .ancestor(
                of: find.byKey(const ValueKey('marker-$boundaryId')),
                matching: find.byType(Semantics),
              )
              .first,
        );
        expect(semantics.properties.selected, isTrue);
        expect(semantics.properties.label, contains('representative marker'));
        expect(tester.takeException(), isNull);
      },
    );
  }

  testWidgets(
    'Complete catalog filters use server IDs, preserve geometry filter and clear both',
    (tester) async {
      final requests = <http.Request>[];
      await _open(tester, (request) async {
        requests.add(request);
        return _json(
          request.url.queryParameters['mode'] == 'overview'
              ? overview()
              : mapResponse(),
        );
      });
      await tester.pumpAndSettle();
      final geometry = find.widgetWithText(FilterChip, 'Project Boundary');
      await tester.ensureVisible(geometry);
      await tester.tap(geometry);
      await tester.pumpAndSettle();
      final filter = find.byType(DropdownButtonFormField<String>);
      await tester.ensureVisible(filter);
      await tester.tap(filter);
      await tester.pumpAndSettle();
      final choice = find.text('Synthetic site absent from map').last;
      await tester.ensureVisible(choice);
      await tester.tap(choice);
      await tester.pumpAndSettle();
      expect(requests.last.url.queryParameters['site_type_id'], '912');
      expect(
        requests.last.url.queryParameters['location_type'],
        'project_boundary',
      );
      await tester.tap(find.byType(DropdownButtonFormField<String>));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Unclassified').last);
      await tester.pumpAndSettle();
      expect(requests.last.url.queryParameters['site_type_id'], 'unclassified');
      final clear = find.text('Clear filters');
      await tester.ensureVisible(clear);
      await tester.tap(clear);
      await tester.pumpAndSettle();
      expect(
        requests.last.url.queryParameters.containsKey('site_type_id'),
        isFalse,
      );
      expect(
        requests.last.url.queryParameters.containsKey('location_type'),
        isFalse,
      );
      expect(find.byTooltip('Toggle boundaries'), findsNothing);
      expect(tester.takeException(), isNull);
    },
  );

  for (final catalog in [
    <String, dynamic>{'data': []},
    {'data': 'malformed'},
    null,
  ]) {
    testWidgets('Empty or unavailable catalog leaves map usable ($catalog)', (
      tester,
    ) async {
      var catalogCalls = 0;
      await _open(
        tester,
        (request) async => _json(
          request.url.queryParameters['mode'] == 'overview'
              ? overview()
              : mapResponse(),
        ),
        catalogRespond: (_) async {
          catalogCalls++;
          return catalogCalls > 1
              ? _json(siteCatalog())
              : catalog == null
              ? http.Response('Private diagnostics', 404)
              : _json(catalog);
        },
      );
      await tester.pumpAndSettle();
      expect(find.byType(FlutterMap), findsOneWidget);
      expect(find.textContaining('Private diagnostics'), findsNothing);
      if (catalog?['data'] is List) {
        expect(
          find.textContaining('No site classifications configured'),
          findsOneWidget,
        );
      } else {
        final retry = find.text('Retry site classifications');
        await tester.ensureVisible(retry);
        await tester.tap(retry);
        await tester.pumpAndSettle();
        expect(catalogCalls, 2);
        expect(find.text('Retry site classifications'), findsNothing);
      }
      expect(tester.takeException(), isNull);
    });
  }

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

  testWidgets(
    'Narrow screen supports large text, keyboard, legend and details',
    (tester) async {
      tester.view.devicePixelRatio = 1;
      tester.view.physicalSize = const Size(360, 800);
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      await _open(
        tester,
        (request) async => _json(
          request.url.queryParameters['mode'] == 'overview'
              ? overview()
              : mapResponse(features: [classifiedBoundary()]),
        ),
        textScale: 2,
      );
      await tester.pumpAndSettle();
      final legend = find.text('Map legend');
      await tester.ensureVisible(legend);
      await tester.tap(legend);
      await tester.pumpAndSettle();
      expect(find.textContaining('Green outline: selected'), findsOneWidget);
      await tester.ensureVisible(find.byKey(const ValueKey('map-search')));
      await tester.tap(find.byKey(const ValueKey('map-search')));
      await tester.showKeyboard(find.byKey(const ValueKey('map-search')));
      tester.view.viewInsets = const FakeViewPadding(bottom: 260);
      addTearDown(tester.view.resetViewInsets);
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      tester.view.resetViewInsets();
      await tester.testTextInput.receiveAction(TextInputAction.done);
      await tester.pumpAndSettle();
      final entry = find.byKey(const ValueKey('project-$boundaryId'));
      final title = find.descendant(
        of: entry,
        matching: find.text('Synthetic public boundary'),
      );
      await tester.ensureVisible(title);
      await tester.tap(title);
      await tester.pumpAndSettle();
      expect(find.text('Geometry type: Project Boundary'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );
}
