import 'support/test_tile_provider.dart';
import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:supabase_flutter/supabase_flutter.dart';
import 'package:mobile/app/app.dart';
import 'package:mobile/app/routing/app_routes.dart';
import 'package:mobile/core/networking/laravel_api_client.dart';
import 'package:mobile/data/remote/api_auth_repository.dart';
import 'package:mobile/data/models/public_project.dart';
import 'package:mobile/data/repositories/public_projects_repository.dart';
import 'package:mobile/features/account/association_home_screen.dart';

import 'mobile_m1_auth_test.dart' show config, me, json, providerSession;
import 'association_dashboard_test.dart' show dashboard;

class _EmptyPublicRepository implements PublicProjectsRepository {
  @override
  Future<List<PublicProject>> getPublicProjects() async => [];
}

void main() {
  late GoTrueClient provider;
  late ApiAuthRepository repository;
  late FutureOr<http.Response> Function(http.Request) apiReply;
  setUp(() {
    provider = GoTrueClient(
      url: '${config.supabaseUrl}/auth/v1',
      autoRefreshToken: false,
      httpClient: MockClient(
        (request) async => request.url.path.endsWith('/logout')
            ? http.Response('', 204)
            : json(providerSession()),
      ),
    );
    apiReply = (request) => json({'data': dashboard()});
    repository = ApiAuthRepository(
      config: config,
      provider: provider,
      api: LaravelApiClient(
        config: config,
        client: MockClient(
          (request) async =>
              request.url.path.endsWith('/me') ? json(me()) : apiReply(request),
        ),
      ),
    );
  });
  tearDown(() {
    provider.dispose();
  });

  Future<void> open(WidgetTester tester) async {
    await tester.pumpWidget(
      AssocMapApp(
        tileProviderFactory: TestTileProvider.new,
        config: config,
        publicProjects: _EmptyPublicRepository(),
        authentication: repository,
      ),
    );
    await tester.pumpAndSettle();
    Navigator.of(
      tester.element(find.text('Explore map')),
    ).pushNamed(AppRoutes.associationHome);
    await tester.pumpAndSettle();
  }

  Future<void> signIn(WidgetTester tester) async {
    await tester.enterText(
      find.byKey(const ValueKey('login-email')),
      'synthetic@example.test',
    );
    await tester.enterText(
      find.byKey(const ValueKey('login-password')),
      'test-only-password',
    );
    tester.testTextInput.hide();
    final button = find.byKey(const ValueKey('sign-in-button'));
    await tester.ensureVisible(button);
    await tester.tap(button);
    await tester.pumpAndSettle();
  }

  testWidgets(
    'live app wiring displays authorized zero and empty data, then guards logout',
    (tester) async {
      await open(tester);
      expect(find.text('Fill demo credentials'), findsNothing);
      await signIn(tester);
      expect(find.byType(AssociationHomeScreen), findsOneWidget);
      expect(find.text('Official members'), findsOneWidget);
      expect(find.text('Synthetic association'), findsWidgets);
      await tester.tap(find.byKey(const ValueKey('workspace-tab-4')));
      await tester.pumpAndSettle();
      await tester.ensureVisible(
        find.widgetWithText(OutlinedButton, 'Sign out'),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(OutlinedButton, 'Sign out'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'Sign out').last);
      await tester.pumpAndSettle();
      expect(find.byType(AssociationHomeScreen), findsNothing);
      expect(provider.currentSession, isNull);
      await tester.pumpWidget(const SizedBox.shrink());
    },
  );

  testWidgets('server outage has retry and never substitutes demo data', (
    tester,
  ) async {
    apiReply = (_) => json({
      'error': {'code': 'service_unavailable'},
    }, 503);
    await open(tester);
    await signIn(tester);
    expect(find.text('Dashboard unavailable'), findsOneWidget);
    expect(find.text('Retry'), findsOneWidget);
    expect(find.text('Demo Harbor Livelihood Association'), findsNothing);
    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets(
    'revoked API access removes the protected screen and displays safe denial',
    (tester) async {
      apiReply = (_) => json({
        'error': {'code': 'account_unavailable'},
      }, 403);
      await open(tester);
      await signIn(tester);
      expect(find.byType(AssociationHomeScreen), findsNothing);
      expect(find.byKey(const ValueKey('login-email')), findsOneWidget);
      expect(provider.currentSession, isNull);
      await tester.pumpWidget(const SizedBox.shrink());
    },
  );

  testWidgets(
    'existing SDK session is reauthorized on startup without entering a password',
    (tester) async {
      await repository.signIn(
        email: 'synthetic@example.test',
        password: 'test-only-password',
      );
      await open(tester);
      expect(find.byType(AssociationHomeScreen), findsOneWidget);
      expect(find.byKey(const ValueKey('login-password')), findsNothing);
      await tester.pumpWidget(const SizedBox.shrink());
    },
  );
}
