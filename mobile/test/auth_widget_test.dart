import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/app/app.dart';
import 'package:mobile/app/configuration/app_config.dart';
import 'package:mobile/app/routing/app_routes.dart';
import 'package:mobile/core/authentication/association_guard.dart';
import 'package:mobile/core/authentication/auth_failure.dart';
import 'package:mobile/data/mock/demo_auth_repository.dart';
import 'package:mobile/data/mock/mock_public_projects_repository.dart';
import 'package:mobile/data/models/auth_session.dart';
import 'package:mobile/data/models/public_project.dart';
import 'package:mobile/data/repositories/auth_repository.dart';
import 'package:mobile/data/repositories/public_projects_repository.dart';
import 'package:mobile/features/account/association_home_screen.dart';

class _PublicRepository implements PublicProjectsRepository {
  _PublicRepository(this.projects);
  final List<PublicProject> projects;
  @override
  Future<List<PublicProject>> getPublicProjects() async => projects;
}

class _HoldingAuth implements AuthRepository {
  final result = Completer<AuthSession>();
  int calls = 0;
  @override
  List<DemoAccountOption> get demoAccounts => const [
    DemoAccountOption('Valid Association Account', 'association@demo.invalid'),
  ];
  @override
  String? get demoPasswordHint => DemoAuthRepository.samplePassword;
  @override
  bool get canAttemptSignIn => true;
  @override
  String? get signInBlocker => null;
  @override
  Future<AuthSession> signIn({
    required String email,
    required String password,
  }) {
    calls++;
    return result.future;
  }

  @override
  Future<void> signOut() async {}
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  WidgetController.hitTestWarningShouldBeFatal = true;
  late List<PublicProject> publicProjects;

  setUpAll(() async {
    publicProjects = await MockPublicProjectsRepository().getPublicProjects();
  });

  Future<void> openLogin(
    WidgetTester tester, {
    DataMode mode = DataMode.mock,
    AuthRepository? authentication,
    bool directProtectedRoute = false,
  }) async {
    await tester.pumpWidget(
      AssocMapApp(
        config: AppConfig(mode: mode),
        publicProjects: _PublicRepository(publicProjects),
        authentication: authentication,
      ),
    );
    final navigator = Navigator.of(tester.element(find.text('Explore map')));
    navigator.pushNamed(
      directProtectedRoute
          ? AppRoutes.associationHome
          : AppRoutes.associationAccess,
    );
    await tester.pumpAndSettle();
  }

  Future<void> tapVisible(WidgetTester tester, Finder finder) async {
    await tester.ensureVisible(finder);
    await tester.pumpAndSettle();
    await tester.tap(finder);
    await tester.pumpAndSettle();
  }

  Future<void> fill(
    WidgetTester tester, {
    String email = 'association@demo.invalid',
    String password = DemoAuthRepository.samplePassword,
  }) async {
    await tester.enterText(find.byKey(const ValueKey('login-email')), email);
    await tester.enterText(
      find.byKey(const ValueKey('login-password')),
      password,
    );
    tester.testTextInput.hide();
    await tester.ensureVisible(find.byKey(const ValueKey('sign-in-button')));
    await tester.pumpAndSettle();
  }

  testWidgets(
    'Valid demo sign-in opens a minimal protected association shell',
    (tester) async {
      await openLogin(tester);
      await tapVisible(tester, find.text('Fill demo credentials'));
      await tapVisible(tester, find.byKey(const ValueKey('sign-in-button')));
      expect(find.byType(AssociationHomeScreen), findsOneWidget);
      expect(find.text('Demo Harbor Livelihood Association'), findsOneWidget);
      expect(find.text('Association modules pending'), findsOneWidget);
      await tester.tap(find.text('Account').last);
      await tester.pumpAndSettle();
      expect(find.text('DEMO session'), findsOneWidget);
    },
  );

  testWidgets(
    'Direct protected route requires sign-in without exposing association content',
    (tester) async {
      await openLogin(tester, directProtectedRoute: true);
      expect(find.byKey(const ValueKey('login-email')), findsOneWidget);
      expect(find.byType(AssociationHomeScreen), findsNothing);
      expect(find.text('Demo Harbor Livelihood Association'), findsNothing);
    },
  );

  testWidgets(
    'Validation and password visibility work without provider requests',
    (tester) async {
      await openLogin(tester);
      await tapVisible(tester, find.byKey(const ValueKey('sign-in-button')));
      expect(find.text('Enter your email address.'), findsOneWidget);
      expect(find.text('Enter your password.'), findsOneWidget);
      await tester.enterText(
        find.byKey(const ValueKey('login-email')),
        'not-an-email',
      );
      await tapVisible(tester, find.byKey(const ValueKey('sign-in-button')));
      expect(find.text('Enter a valid email address.'), findsOneWidget);
      await tapVisible(tester, find.byTooltip('Show password'));
      final editable = tester.widget<EditableText>(
        find.descendant(
          of: find.byKey(const ValueKey('login-password')),
          matching: find.byType(EditableText),
        ),
      );
      expect(editable.obscureText, isFalse);
      expect(find.byTooltip('Hide password'), findsOneWidget);
    },
  );

  testWidgets('Failed credentials use a generic error and clear the password', (
    tester,
  ) async {
    await openLogin(tester);
    await fill(
      tester,
      email: 'admin@demo.invalid',
      password: 'wrong-demo-password',
    );
    await tapVisible(tester, find.byKey(const ValueKey('sign-in-button')));
    expect(
      find.text(const AuthFailure(AuthFailureCode.invalidCredentials).message),
      findsOneWidget,
    );
    expect(
      find.text(const AuthFailure(AuthFailureCode.wrongRole).message),
      findsNothing,
    );
    final password = tester.widget<EditableText>(
      find.descendant(
        of: find.byKey(const ValueKey('login-password')),
        matching: find.byType(EditableText),
      ),
    );
    expect(password.controller.text, isEmpty);
  });

  for (final email in ['admin@demo.invalid', 'officer@demo.invalid']) {
    testWidgets(
      'Verified $email sees role denial and can return to public GIS',
      (tester) async {
        await openLogin(tester);
        await fill(tester, email: email);
        await tapVisible(tester, find.byKey(const ValueKey('sign-in-button')));
        expect(
          find.text(const AuthFailure(AuthFailureCode.wrongRole).message),
          findsOneWidget,
        );
        expect(find.byType(AssociationHomeScreen), findsNothing);
        await tapVisible(tester, find.text('Open public map'));
        expect(find.byType(FlutterMap), findsOneWidget);
      },
    );
  }

  for (final email in ['unlinked@demo.invalid', 'inactive-link@demo.invalid']) {
    testWidgets('Verified $email sees the required active-link message', (
      tester,
    ) async {
      await openLogin(tester);
      await fill(tester, email: email);
      await tapVisible(tester, find.byKey(const ValueKey('sign-in-button')));
      expect(
        find.text(
          const AuthFailure(AuthFailureCode.invalidAssociationLink).message,
        ),
        findsOneWidget,
      );
      await tapVisible(tester, find.text('Back to sign-in'));
      expect(find.byKey(const ValueKey('login-email')), findsOneWidget);
      expect(find.byType(AssociationHomeScreen), findsNothing);
    });
  }

  testWidgets(
    'An unavailable service offers retry without retaining a password',
    (tester) async {
      await openLogin(tester);
      await fill(tester, email: 'unavailable@demo.invalid');
      await tapVisible(tester, find.byKey(const ValueKey('sign-in-button')));
      expect(
        find.text(
          const AuthFailure(AuthFailureCode.connectionUnavailable).message,
        ),
        findsOneWidget,
      );
      await tapVisible(tester, find.text('Retry'));
      expect(
        find.text(
          const AuthFailure(AuthFailureCode.connectionUnavailable).message,
        ),
        findsNothing,
      );
    },
  );

  testWidgets('Processing disables repeated form submission', (tester) async {
    final repository = _HoldingAuth();
    await openLogin(tester, authentication: repository);
    await fill(tester);
    await tester.tap(find.byKey(const ValueKey('sign-in-button')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
    expect(find.text('Signing in…'), findsOneWidget);
    expect(
      tester
          .widget<FilledButton>(find.byKey(const ValueKey('sign-in-button')))
          .onPressed,
      isNull,
    );
    expect(repository.calls, 1);
    repository.result.complete(
      await DemoAuthRepository().signIn(
        email: 'association@demo.invalid',
        password: DemoAuthRepository.samplePassword,
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byType(AssociationHomeScreen), findsOneWidget);
  });

  testWidgets(
    'Logout clears access, returns to public GIS, and guards later routes',
    (tester) async {
      await openLogin(tester);
      await fill(tester);
      await tapVisible(tester, find.byKey(const ValueKey('sign-in-button')));
      final controller = tester
          .widget<AssociationGuard>(find.byType(AssociationGuard))
          .controller;
      await tester.tap(find.byTooltip('Sign out'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'Sign out'));
      await tester.pumpAndSettle();
      expect(controller.session, isNull);
      expect(find.byType(FlutterMap), findsOneWidget);
      Navigator.of(
        tester.element(find.byType(FlutterMap)),
      ).pushNamed(AppRoutes.associationHome);
      await tester.pumpAndSettle();
      expect(find.byType(AssociationHomeScreen), findsNothing);
      expect(find.byKey(const ValueKey('login-email')), findsOneWidget);
    },
  );

  testWidgets('Expiry removes protected content even under a public page', (
    tester,
  ) async {
    await openLogin(tester);
    await fill(tester);
    await tapVisible(tester, find.byKey(const ValueKey('sign-in-button')));
    final controller = tester
        .widget<AssociationGuard>(find.byType(AssociationGuard))
        .controller;
    await tester.tap(find.byTooltip('Open public map'));
    await tester.pumpAndSettle();
    controller.expireSession();
    await tester.pumpAndSettle();
    expect(find.byType(FlutterMap), findsOneWidget);
    await tester.pageBack();
    await tester.pumpAndSettle();
    expect(find.byType(AssociationHomeScreen), findsNothing);
    expect(
      find.text(const AuthFailure(AuthFailureCode.sessionExpired).message),
      findsOneWidget,
    );
  });

  testWidgets(
    'API sign-in is blocked and contains no demo credential controls',
    (tester) async {
      await openLogin(tester, mode: DataMode.api);
      expect(
        find.text(
          const AuthFailure(AuthFailureCode.integrationUnavailable).message,
        ),
        findsOneWidget,
      );
      expect(find.text('Fill demo credentials'), findsNothing);
      expect(
        find.textContaining(DemoAuthRepository.samplePassword),
        findsNothing,
      );
      expect(
        tester
            .widget<FilledButton>(find.byKey(const ValueKey('sign-in-button')))
            .onPressed,
        isNull,
      );
      expect(find.byType(AssociationHomeScreen), findsNothing);
    },
  );

  for (final size in [
    const Size(360, 800),
    const Size(640, 360),
    const Size(1200, 900),
  ]) {
    testWidgets(
      'Login fits a scrollable viewport ${size.width} x ${size.height}',
      (tester) async {
        tester.view.devicePixelRatio = 1;
        tester.view.physicalSize = size;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        await openLogin(tester);
        await tapVisible(tester, find.text('Fill demo credentials'));
        await tapVisible(tester, find.byKey(const ValueKey('sign-in-button')));
        expect(find.byType(AssociationHomeScreen), findsOneWidget);
        expect(tester.takeException(), isNull);
      },
    );
  }
}
