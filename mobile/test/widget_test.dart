import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/app/app.dart';
import 'package:mobile/app/configuration/app_config.dart';
import 'package:mobile/core/errors/app_failure.dart';
import 'package:mobile/data/mock/mock_public_projects_repository.dart';
import 'package:mobile/data/models/public_project.dart';
import 'package:mobile/data/repositories/public_projects_repository.dart';
import 'package:mobile/data/repositories/repository_factory.dart';

class _Repository implements PublicProjectsRepository {
  _Repository(this.load);
  final Future<List<PublicProject>> Function() load;

  @override
  Future<List<PublicProject>> getPublicProjects() => load();
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  WidgetController.hitTestWarningShouldBeFatal = true;
  late List<PublicProject> projects;

  setUpAll(() async {
    projects = await MockPublicProjectsRepository().getPublicProjects();
  });

  Future<void> openMap(
    WidgetTester tester, {
    PublicProjectsRepository? repository,
    DataMode mode = DataMode.mock,
  }) async {
    await tester.pumpWidget(
      AssocMapApp(
        config: AppConfig(mode: mode),
        publicProjects: repository ?? _Repository(() async => projects),
      ),
    );
    await tester.ensureVisible(find.text('Explore map'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Explore map'));
    await tester.pumpAndSettle();
  }

  testWidgets(
    'Public map opens without a session and excludes hidden fixtures',
    (tester) async {
      await openMap(tester);
      expect(find.byType(FlutterMap), findsOneWidget);
      expect(find.text('No login required'), findsOneWidget);
      expect(find.text('Demo mode · Synthetic data only'), findsOneWidget);
      expect(
        tester.widget<MarkerLayer>(find.byType(MarkerLayer)).markers,
        hasLength(3),
      );
      expect(find.textContaining('Hidden synthetic'), findsNothing);
    },
  );

  testWidgets('Polygon holes and all MultiPolygon parts reach the map', (
    tester,
  ) async {
    await openMap(tester);
    final layer = tester.widget<PolygonLayer>(find.byType(PolygonLayer));
    expect(layer.polygons, hasLength(3));
    expect(layer.polygons.first.holePointsList, hasLength(1));
    await tester.tap(find.text('Boundaries'));
    await tester.pumpAndSettle();
    expect(find.byType(PolygonLayer), findsNothing);
    expect(find.byType(MarkerLayer), findsOneWidget);
  });

  testWidgets(
    'Marker selection opens a public summary without raw coordinates',
    (tester) async {
      await openMap(tester);
      await tester.tap(find.byKey(const ValueKey('marker-demo-project-01')));
      await tester.pumpAndSettle();
      expect(find.text('Public project summary'), findsOneWidget);
      expect(find.text(projects.first.summary), findsOneWidget);
      expect(find.textContaining('124.97'), findsNothing);
      await tester.tap(find.byTooltip('Close project details'));
      await tester.pumpAndSettle();
      expect(find.text('Public project summary'), findsNothing);
    },
  );

  testWidgets(
    'Search filters map and list together and recovers from no results',
    (tester) async {
      await openMap(tester);
      await tester.enterText(
        find.byKey(const ValueKey('map-search')),
        '  GREENFIELDS  ',
      );
      await tester.pumpAndSettle();
      expect(
        tester.widget<MarkerLayer>(find.byType(MarkerLayer)).markers,
        hasLength(1),
      );
      expect(
        find.byKey(const ValueKey('project-demo-project-02')),
        findsOneWidget,
      );
      await tester.enterText(
        find.byKey(const ValueKey('map-search')),
        'does-not-exist',
      );
      await tester.pumpAndSettle();
      expect(find.text('No matching projects'), findsOneWidget);
      expect(find.byType(MarkerLayer), findsNothing);
      await tester.tap(find.text('Clear filters'));
      await tester.pumpAndSettle();
      expect(
        tester.widget<MarkerLayer>(find.byType(MarkerLayer)).markers,
        hasLength(3),
      );
    },
  );

  testWidgets('Category filtering preserves only its marker and geometry', (
    tester,
  ) async {
    await openMap(tester);
    await tester.ensureVisible(find.text('Agriculture'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Agriculture'));
    await tester.pumpAndSettle();
    expect(
      tester.widget<MarkerLayer>(find.byType(MarkerLayer)).markers,
      hasLength(1),
    );
    expect(
      tester.widget<PolygonLayer>(find.byType(PolygonLayer)).polygons,
      hasLength(2),
    );
  });

  testWidgets('Map zoom controls and pan change the geographic camera', (
    tester,
  ) async {
    await openMap(tester);
    final map = tester.widget<FlutterMap>(find.byType(FlutterMap));
    final controller = map.mapController!;
    final originalZoom = controller.camera.zoom;
    await tester.tap(find.byTooltip('Zoom in'));
    await tester.pumpAndSettle();
    expect(controller.camera.zoom, greaterThan(originalZoom));
    await tester.tap(find.byTooltip('Zoom out'));
    await tester.pumpAndSettle();
    expect(controller.camera.zoom, closeTo(originalZoom, .001));
    final originalCenter = controller.camera.center;
    await tester.dragFrom(
      tester.getCenter(find.byType(FlutterMap)) + const Offset(0, 80),
      const Offset(60, 0),
    );
    await tester.pumpAndSettle();
    expect(controller.camera.center, isNot(originalCenter));
    await tester.tap(find.byTooltip('Fit visible projects'));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
  });

  testWidgets('Repository loading and empty states are honest', (tester) async {
    final completer = Completer<List<PublicProject>>();
    await tester.pumpWidget(
      AssocMapApp(
        config: const AppConfig(),
        publicProjects: _Repository(() => completer.future),
      ),
    );
    await tester.tap(find.text('Explore map'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
    expect(find.text('Loading public projects…'), findsOneWidget);
    completer.complete([]);
    await tester.pumpAndSettle();
    expect(find.text('No public projects yet'), findsOneWidget);
    expect(find.byType(FlutterMap), findsNothing);
  });

  testWidgets(
    'A repository failure can retry without exposing exception details',
    (tester) async {
      var calls = 0;
      await openMap(
        tester,
        repository: _Repository(() async {
          calls++;
          if (calls == 1) throw StateError('internal transport diagnostic');
          return projects;
        }),
      );
      expect(find.text('Public map data unavailable'), findsOneWidget);
      expect(
        find.textContaining('internal transport diagnostic'),
        findsNothing,
      );
      await tester.tap(find.text('Retry'));
      await tester.pumpAndSettle();
      expect(calls, 2);
      expect(find.byType(FlutterMap), findsOneWidget);
    },
  );

  testWidgets('API mode stays blocked and never shows mock records', (
    tester,
  ) async {
    const config = AppConfig(mode: DataMode.api);
    await openMap(
      tester,
      mode: DataMode.api,
      repository: RepositoryFactory.publicProjects(config),
    );
    expect(
      find.textContaining('Live GIS integration is not configured'),
      findsOneWidget,
    );
    expect(find.byType(FlutterMap), findsNothing);
    expect(find.text('Demo Coastal Grow-Out'), findsNothing);
    await tester.tap(find.text('Retry'));
    await tester.pumpAndSettle();
    expect(find.byType(FlutterMap), findsNothing);
  });

  testWidgets('Invalid configuration fails closed', (tester) async {
    const config = AppConfig(mode: DataMode.invalid);
    await openMap(
      tester,
      mode: DataMode.invalid,
      repository: RepositoryFactory.publicProjects(config),
    );
    expect(find.textContaining('Invalid data mode'), findsOneWidget);
    expect(find.byType(FlutterMap), findsNothing);
  });

  testWidgets('Association sign-in retains access to the public GIS map', (
    tester,
  ) async {
    await tester.pumpWidget(
      AssocMapApp(
        config: const AppConfig(),
        publicProjects: _Repository(() async => projects),
      ),
    );
    await tester.ensureVisible(find.text('Association access'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Association access'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('login-email')), findsOneWidget);
    await tester.ensureVisible(find.text('Open public map'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Open public map'));
    await tester.pumpAndSettle();
    expect(find.byType(FlutterMap), findsOneWidget);
    await tester.pageBack();
    await tester.pumpAndSettle();
    expect(find.text('Explore map'), findsOneWidget);
  });

  for (final size in [
    const Size(360, 800),
    const Size(640, 360),
    const Size(1200, 900),
  ]) {
    testWidgets('Public map fits viewport ${size.width} x ${size.height}', (
      tester,
    ) async {
      tester.view.devicePixelRatio = 1;
      tester.view.physicalSize = size;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      await openMap(tester);
      expect(find.byType(FlutterMap), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('Typed repository failures show their safe user message', (
    tester,
  ) async {
    await openMap(
      tester,
      repository: _Repository(() async {
        throw const AppFailure(
          FailureKind.invalidData,
          'Synthetic fixture is invalid.',
        );
      }),
    );
    expect(find.text('Synthetic fixture is invalid.'), findsOneWidget);
  });
}
