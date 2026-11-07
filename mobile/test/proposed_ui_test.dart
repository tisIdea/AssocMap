import 'dart:convert';
import 'dart:io';
import 'dart:ui' as ui;
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/app/configuration/app_config.dart';
import 'package:mobile/app/theme/app_theme.dart';
import 'package:mobile/core/authentication/auth_controller.dart';
import 'package:mobile/core/authentication/auth_failure.dart';
import 'package:mobile/core/widgets/assocmap_widgets.dart';
import 'package:mobile/data/models/analytics_report.dart';
import 'package:mobile/data/models/association_dashboard.dart';
import 'package:mobile/data/models/auth_session.dart';
import 'package:mobile/data/models/geo_boundary.dart';
import 'package:mobile/data/models/public_gis_feature.dart';
import 'package:mobile/data/models/public_project.dart';
import 'package:mobile/data/repositories/analytics_repository.dart';
import 'package:mobile/data/repositories/auth_repository.dart';
import 'package:mobile/data/repositories/dashboard_repository.dart';
import 'package:mobile/data/repositories/public_projects_repository.dart';
import 'package:mobile/features/account/association_access_screen.dart';
import 'package:mobile/features/account/association_home_screen.dart';
import 'package:mobile/features/public_map/public_map_screen.dart';
import 'association_dashboard_test.dart' show dashboard;
import 'analytics_report_test.dart' show report;
import 'support/test_tile_provider.dart';
import 'support/member_application_fixtures.dart';

final _activeSession = AuthSession(
  userId: '11111111-1111-4111-8111-111111111111',
  displayName: 'Association account',
  role: AccountRole.associationAccount,
  accountIsActive: true,
  roleIsActive: true,
  isDemo: false,
  expiresAt: DateTime.now().add(const Duration(hours: 2)),
  association: const AssociationAccess(
    id: '33333333-3333-4333-8333-333333333333',
    name: 'Coastal Livelihood Association',
    linkIsActive: true,
    associationIsActive: true,
    associationIsArchived: false,
  ),
);

class _Auth implements AuthRepository {
  @override
  List<DemoAccountOption> get demoAccounts => [];
  @override
  String? get demoPasswordHint => null;
  @override
  bool get canAttemptSignIn => true;
  @override
  String? get signInBlocker => null;
  @override
  Future<AuthSession> signIn({
    required String email,
    required String password,
  }) async => _activeSession;
  @override
  Future<void> signOut() async {}
}

Map<String, dynamic> _dashboard() {
  final data = dashboard();
  data['association']['name'] = _activeSession.association!.name;
  data['association']['municipality'] = 'Cordova, Cebu';
  data['metrics'] = [
    {
      'key': 'members_total',
      'label': 'Official members',
      'value': 4,
      'note': 'All retained official records, including archived members.',
    },
    {
      'key': 'projects_total',
      'label': 'Registered projects',
      'value': 2,
      'note': 'All retained projects, including archives.',
    },
  ];
  data['applications'] = {'pending': 5, 'approved': 1, 'rejected': 0};
  data['training'] = {
    'records': 2,
    'completed': 1,
    'distinct_present_members': 4,
  };
  data['recent_projects'] = [
    {
      'title': 'Tilapia pond project',
      'status': 'planned',
      'program_year': 2026,
      'created_at': '2026-03-01T12:00:00+08:00',
    },
    {
      'title': 'Bangus livelihood project',
      'status': 'ongoing',
      'program_year': 2026,
      'created_at': '2026-02-01T12:00:00+08:00',
    },
  ];
  return data;
}

class _Dashboard implements DashboardRepository {
  _Dashboard({this.data});
  final Map<String, dynamic>? data;
  @override
  Future<AssociationDashboard> load() async =>
      AssociationDashboard.fromJson(data ?? _dashboard());
}

class _Reports implements AnalyticsRepository {
  Map<String, String>? filters;
  @override
  Future<AnalyticsPeriods> periods() async =>
      AnalyticsPeriods(years: [2026], programYears: [2026]);
  @override
  Future<AnalyticsReport> load(Map<String, String> filters) async {
    this.filters = filters;
    final data = report();
    data['title'] = 'Production performance';
    data['meta']['scope'] = [_activeSession.association!.name];
    data['meta']['scope_basis'] = 'Current active linked association only.';
    data['sections'] = [
      {
        'title': 'Recorded production',
        'notes': <String>[],
        'columns': [
          {'key': 'month', 'label': 'Month', 'type': 'text'},
          {'key': 'quantity', 'label': 'Quantity (kg)', 'type': 'decimal'},
        ],
        'rows': [
          {'month': 'Jul', 'quantity': '360.00'},
          {'month': 'Aug', 'quantity': '410.00'},
          {'month': 'Sep', 'quantity': '470.00'},
        ],
        'measures': [
          {'label': 'Recorded quantity (kg)', 'value': '1240.00'},
        ],
        'chart': {
          'label_key': 'month',
          'value_key': 'quantity',
          'title': 'Recorded production',
          'unit': 'kg',
        },
      },
    ];
    return AnalyticsReport.fromJson(data);
  }
}

class _Public implements ViewportPublicProjectsRepository {
  @override
  Future<List<PublicProject>> getPublicProjects() async => const [
    PublicProject(
      id: 'location-1',
      title: 'Published livelihood site',
      summary: 'Published association location.',
      location: GeoPoint(10.25, 123.95),
      locationType: 'project_point',
    ),
  ];
  @override
  Future<PublicGisResult> getMap({
    GisBounds? bounds,
    String query = '',
    String? locationType,
    String? siteTypeId,
  }) async => PublicGisResult(
    projects: await getPublicProjects(),
    bounds: GisBounds.fromJson([123.25, 9.6, 124.4, 11.4]),
  );
}

class _SnapshotTiles extends TestTileProvider {
  static final requested = <String>{};
  static final images = <String, ImageProvider>{};
  @override
  ImageProvider getImage(TileCoordinates coordinates, TileLayer options) {
    final name = '${coordinates.z}-${coordinates.x}-${coordinates.y}';
    requested.add(name);
    final file = File('../output/mobile-ui/tiles/$name.jpg');
    return images.putIfAbsent(
      name,
      () => file.existsSync()
          ? MemoryImage(file.readAsBytesSync())
          : super.getImage(coordinates, options),
    );
  }
}

final _capture = GlobalKey();
Future<void> _snapshot(WidgetTester tester, String name) async {
  if (!const bool.fromEnvironment('ASSOCMAP_CAPTURE_UI')) return;
  await tester.pumpAndSettle();
  debugPrint('Capturing $name');
  await tester.runAsync(() async {
    final boundary =
        _capture.currentContext!.findRenderObject()! as RenderRepaintBoundary;
    final image = await boundary.toImage(pixelRatio: 2);
    final bytes = (await image.toByteData(
      format: ui.ImageByteFormat.png,
    ))!.buffer.asUint8List();
    final out = Directory('../output/mobile-ui/screenshots')
      ..createSync(recursive: true);
    File('${out.path}/$name.png').writeAsBytesSync(bytes);
    image.dispose();
  });
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  WidgetController.hitTestWarningShouldBeFatal = true;
  setUpAll(() async {
    if (const bool.fromEnvironment('ASSOCMAP_CAPTURE_UI')) {
      final sdkRoot =
          Platform.environment['FLUTTER_ROOT'] ??
          File(
            Platform.resolvedExecutable,
          ).parent.parent.parent.parent.parent.parent.path;
      final loader = FontLoader('Roboto');
      for (final weight in ['regular', 'bold']) {
        final file = File(
          '$sdkRoot/bin/cache/artifacts/material_fonts/roboto-$weight.ttf',
        );
        loader.addFont(
          Future.value(ByteData.sublistView(file.readAsBytesSync())),
        );
      }
      await loader.load();
      final icons = FontLoader('MaterialIcons');
      icons.addFont(
        Future.value(
          ByteData.sublistView(
            File(
              '$sdkRoot/bin/cache/artifacts/material_fonts/materialicons-regular.otf',
            ).readAsBytesSync(),
          ),
        ),
      );
      await icons.load();
    }
  });

  Future<AuthController> controller() async {
    final auth = AuthController(repository: _Auth(), mode: DataMode.api);
    addTearDown(auth.dispose);
    return auth;
  }

  Widget framed(Widget child, {double scale = 1}) => MaterialApp(
    theme: AppTheme.light,
    builder: (context, child) => MediaQuery(
      data: MediaQuery.of(
        context,
      ).copyWith(textScaler: TextScaler.linear(scale)),
      child: child!,
    ),
    home: RepaintBoundary(key: _capture, child: child),
  );

  Future<_Reports> workspace(
    WidgetTester tester, {
    double scale = 1,
    DashboardRepository? repository,
  }) async {
    final auth = await controller();
    final reports = _Reports();
    await tester.pumpWidget(
      framed(
        AssociationHomeScreen(
          config: const AppConfig(mode: DataMode.api),
          controller: auth,
          session: _activeSession,
          dashboard: repository ?? _Dashboard(),
          analytics: reports,
          memberApplications: TestMemberApplicationsRepository(),
        ),
        scale: scale,
      ),
    );
    await tester.pumpAndSettle();
    return reports;
  }

  Future<void> tab(WidgetTester tester, int index) async {
    await tester.tap(find.byKey(ValueKey('workspace-tab-$index')));
    await tester.pumpAndSettle();
  }

  Future<void> quick(WidgetTester tester, String label) async {
    await tester.ensureVisible(find.text(label).first);
    await tester.tap(find.text(label).first);
    await tester.pumpAndSettle();
  }

  test(
    'unconfigured application entry selects live APIs without synthetic fallback',
    () {
      expect(const AppConfig.fromEnvironment().mode, DataMode.api);
      expect(const AppConfig.fromEnvironment().gisMode, GisDataMode.live);
      expect(AppTheme.blue, const Color(0xFF0B4C8C));
    },
  );

  test('authentication availability messages use operational language', () {
    for (final code in [
      AuthFailureCode.integrationUnavailable,
      AuthFailureCode.invalidConfiguration,
      AuthFailureCode.demoDisabled,
    ]) {
      expect(
        AuthFailure(code).message,
        isNot(
          matches(
            RegExp(
              r'demo|prototype|not connected|app build|production use',
              caseSensitive: false,
            ),
          ),
        ),
      );
    }
  });

  testWidgets(
    'ten reference screens retain hierarchy and honest unavailable states',
    (tester) async {
      tester.view.physicalSize = const Size(360, 800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      await tester.pumpWidget(
        framed(
          PublicMapScreen(
            config: const AppConfig(mode: DataMode.api),
            repository: _Public(),
            tileProvider: _SnapshotTiles(),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.byType(TileLayer), findsOneWidget);
      expect(find.text('Log in'), findsOneWidget);
      expect(find.byType(Drawer), findsNothing);
      for (final name in _SnapshotTiles.requested) {
        final tile = name.split('-').map(int.parse).toList();
        final westLongitude = tile[1] / (1 << tile[0]) * 360 - 180;
        expect(
          westLongitude,
          inInclusiveRange(90, 150),
          reason:
              'Initial tiles must follow the Cebu public viewport, not a library default location.',
        );
      }
      await tester.runAsync(() async {
        for (final image in _SnapshotTiles.images.values.toList()) {
          await precacheImage(
            image,
            tester.element(find.byType(PublicMapScreen)),
          );
        }
      });
      await tester.pumpAndSettle();
      await _snapshot(tester, '01-public-map');
      if (const bool.fromEnvironment('ASSOCMAP_CAPTURE_UI')) {
        File(
          '../output/mobile-ui/tile-requests.json',
        ).writeAsStringSync(jsonEncode(_SnapshotTiles.requested.toList()));
      }
      final auth = await controller();
      await tester.pumpWidget(
        framed(
          AssociationAccessScreen(
            config: const AppConfig(mode: DataMode.api),
            controller: auth,
          ),
        ),
      );
      await tester.pumpAndSettle();
      await _snapshot(tester, '02-login');
      final reports = await workspace(tester);
      await _snapshot(tester, '03-dashboard');
      await quick(tester, 'Association');
      expect(find.byType(PhotoPlaceholder), findsOneWidget);
      await _snapshot(tester, '04-association');
      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.text('Maayong adlaw!'), findsOneWidget);
      await tab(tester, 1);
      expect(find.text('Member roster unavailable'), findsOneWidget);
      await _snapshot(tester, '05-members');
      await tester.tap(find.text('Registrations').last);
      await tester.pumpAndSettle();
      await tester.tap(find.text('View my applications'));
      await tester.pumpAndSettle();
      expect(find.text('My member applications'), findsNWidgets(2));
      expect(find.text('Status: pending'), findsOneWidget);
      await _snapshot(tester, '05b-registrations');
      await tab(tester, 2);
      expect(find.text('Tilapia pond project'), findsOneWidget);
      await _snapshot(tester, '06-projects');
      await tester.enterText(
        find.byKey(const ValueKey('project-summary-search')),
        'Bangus',
      );
      await tester.pumpAndSettle();
      expect(find.text('Tilapia pond project'), findsNothing);
      expect(find.text('Bangus livelihood project'), findsOneWidget);
      await tester.tap(find.text('Trainings').first);
      await tester.pumpAndSettle();
      expect(find.text('Training calendar unavailable'), findsOneWidget);
      await _snapshot(tester, '07-trainings');
      await tester.ensureVisible(find.text('Production monitoring').last);
      await tester.tap(find.text('Production monitoring').last);
      await tester.pumpAndSettle();
      expect(reports.filters?['report_type'], 'production');
      expect(find.text('Report type'), findsNothing);
      await _snapshot(tester, '08-production');
      await tester.drag(find.byType(ListView).first, const Offset(0, -320));
      await tester.pumpAndSettle();
      await _snapshot(tester, '08b-production-detail');
      await tab(tester, 3);
      expect(
        tester
            .widget<FilledButton>(
              find.widgetWithText(FilledButton, 'Submit for Review'),
            )
            .onPressed,
        isNotNull,
      );
      await _snapshot(tester, '09-registration');
      await tester.enterText(find.byType(TextField).first, 'Local-only name');
      await tab(tester, 4);
      await _snapshot(tester, '10-account');
      await tab(tester, 3);
      expect(
        tester.widget<TextField>(find.byType(TextField).first).controller!.text,
        isEmpty,
      );
      await tab(tester, 4);
      expect(find.text('Association Account'), findsWidgets);
      expect(find.textContaining('BFAR'), findsNothing);
      expect(find.textContaining('prototype'), findsNothing);
      expect(find.textContaining('Sample data'), findsNothing);
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox());
    },
  );

  for (final size in [
    const Size(320, 640),
    const Size(360, 800),
    const Size(640, 360),
  ]) {
    testWidgets('all destinations fit $size with 200 percent text', (
      tester,
    ) async {
      tester.view.physicalSize = size;
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      await workspace(tester, scale: 2);
      await _snapshot(
        tester,
        'large-text-${size.width.toInt()}-${size.height.toInt()}-dashboard',
      );
      for (final index in [1, 2, 3, 4, 0]) {
        await tab(tester, index);
        expect(tester.takeException(), isNull, reason: 'Workspace tab $index');
      }
      await quick(tester, 'Association');
      expect(tester.takeException(), isNull);
      await tab(tester, 2);
      await tester.ensureVisible(find.text('Trainings').first);
      await tester.tap(find.text('Trainings').first);
      await tester.pumpAndSettle();
      expect(find.text('Training calendar unavailable'), findsOneWidget);
      expect(tester.takeException(), isNull);
      await tester.ensureVisible(find.text('Production monitoring').last);
      await tester.tap(find.text('Production monitoring').last);
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.text('Production performance'),
        200,
        scrollable: find
            .descendant(
              of: find.byType(ListView).first,
              matching: find.byType(Scrollable),
            )
            .first,
      );
      expect(find.text('Production performance'), findsOneWidget);
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(
        framed(
          PublicMapScreen(
            config: const AppConfig(mode: DataMode.api),
            repository: _Public(),
            tileProvider: TestTileProvider(),
          ),
          scale: 2,
        ),
      );
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      final auth = await controller();
      await _snapshot(
        tester,
        'large-text-${size.width.toInt()}-${size.height.toInt()}-map',
      );
      await tester.pumpWidget(
        framed(
          AssociationAccessScreen(
            config: const AppConfig(mode: DataMode.api),
            controller: auth,
          ),
          scale: 2,
        ),
      );
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox());
    });
  }

  testWidgets(
    'wrong association dashboard data never enters the presentation',
    (tester) async {
      final data = _dashboard();
      data['association']['id'] = 'other-association';
      await workspace(tester, repository: _Dashboard(data: data));
      expect(find.text('Dashboard unavailable'), findsOneWidget);
      expect(find.text('Tilapia pond project'), findsNothing);
      await tester.pumpWidget(const SizedBox());
    },
  );
}
