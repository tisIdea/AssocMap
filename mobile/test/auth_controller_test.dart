import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/app/configuration/app_config.dart';
import 'package:mobile/core/authentication/auth_controller.dart';
import 'package:mobile/core/authentication/auth_failure.dart';
import 'package:mobile/data/mock/demo_auth_repository.dart';
import 'package:mobile/data/models/auth_session.dart';
import 'package:mobile/data/repositories/auth_repository.dart';
import 'package:mobile/data/repositories/repository_factory.dart';

class _DeferredAuth implements AuthRepository {
  final result = Completer<AuthSession>();
  int calls = 0;
  @override
  List<DemoAccountOption> get demoAccounts => const [];
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
  }) {
    calls++;
    return result.future;
  }

  @override
  Future<void> signOut() async {}
}

void main() {
  AuthController controller({
    AuthRepository? repository,
    DataMode mode = DataMode.mock,
    DateTime Function()? now,
  }) {
    final auth = AuthController(
      repository: repository ?? DemoAuthRepository(now: now),
      mode: mode,
      now: now,
    );
    addTearDown(auth.dispose);
    return auth;
  }

  Future<bool> login(
    AuthController auth,
    String email, {
    String password = DemoAuthRepository.samplePassword,
  }) => auth.signIn(email: email, password: password);

  test(
    'Valid synthetic account grants only its normalized association context',
    () async {
      final auth = controller();
      expect(await login(auth, '  ASSOCIATION@DEMO.INVALID  '), isTrue);
      expect(auth.session?.isDemo, isTrue);
      expect(auth.session?.association?.id, 'demo-association-01');
      expect(auth.canAccessAssociation, isTrue);
      await auth.signOut();
      expect(auth.session, isNull);
      expect(auth.canAccessAssociation, isFalse);
    },
  );

  test('Invalid credentials do not disclose role or linkage', () async {
    final auth = controller();
    for (final email in [
      'admin@demo.invalid',
      'unlinked@demo.invalid',
      'unknown@demo.invalid',
    ]) {
      expect(
        await login(auth, email, password: 'incorrect-synthetic-password'),
        isFalse,
      );
      expect(auth.failure?.code, AuthFailureCode.invalidCredentials);
      expect(auth.session, isNull);
      expect(auth.failure?.message, isNot(contains('linked')));
    }
    expect(
      await login(
        auth,
        'association@demo.invalid',
        password: ' DemoOnly!2026 ',
      ),
      isFalse,
    );
  });

  for (final email in ['admin@demo.invalid', 'officer@demo.invalid']) {
    test(
      'Verified $email cannot enter Association Account navigation',
      () async {
        final auth = controller();
        expect(await login(auth, email), isFalse);
        expect(auth.failure?.code, AuthFailureCode.wrongRole);
        expect(auth.session, isNull);
        expect(auth.canAccessAssociation, isFalse);
      },
    );
  }

  for (final email in [
    'unlinked@demo.invalid',
    'inactive-link@demo.invalid',
    'inactive-association@demo.invalid',
    'archived@demo.invalid',
  ]) {
    test(
      'Verified $email is denied without an active valid association link',
      () async {
        final auth = controller();
        expect(await login(auth, email), isFalse);
        expect(auth.failure?.code, AuthFailureCode.invalidAssociationLink);
        expect(auth.session, isNull);
      },
    );
  }

  for (final email in ['disabled@demo.invalid', 'disabled-role@demo.invalid']) {
    test(
      'Verified $email is rejected when account or role is disabled',
      () async {
        final auth = controller();
        expect(await login(auth, email), isFalse);
        expect(auth.failure?.code, AuthFailureCode.accountUnavailable);
        expect(auth.session, isNull);
      },
    );
  }

  test('Demo service failure shows a safe retriable error', () async {
    final auth = controller();
    expect(await login(auth, 'unavailable@demo.invalid'), isFalse);
    expect(auth.failure?.code, AuthFailureCode.connectionUnavailable);
    auth.clearFailure();
    expect(await login(auth, 'association@demo.invalid'), isTrue);
  });

  test(
    'Duplicate submission is prevented and late login cannot undo logout',
    () async {
      final repository = _DeferredAuth();
      final auth = controller(repository: repository);
      final first = login(auth, 'association@demo.invalid');
      expect(auth.busy, isTrue);
      expect(await login(auth, 'association@demo.invalid'), isFalse);
      expect(repository.calls, 1);
      await auth.signOut();
      repository.result.complete(
        await DemoAuthRepository().signIn(
          email: 'association@demo.invalid',
          password: DemoAuthRepository.samplePassword,
        ),
      );
      expect(await first, isFalse);
      expect(auth.session, isNull);
      expect(auth.busy, isFalse);
    },
  );

  test('Leaving an in-progress sign-in prevents late access', () async {
    final repository = _DeferredAuth();
    final auth = controller(repository: repository);
    final pending = login(auth, 'association@demo.invalid');
    auth.cancelSignIn();
    repository.result.complete(
      await DemoAuthRepository().signIn(
        email: 'association@demo.invalid',
        password: DemoAuthRepository.samplePassword,
      ),
    );
    expect(await pending, isFalse);
    expect(auth.canAccessAssociation, isFalse);
  });

  test('Disposal prevents late results from restoring a session', () async {
    final repository = _DeferredAuth();
    final auth = AuthController(repository: repository, mode: DataMode.mock);
    final pending = login(auth, 'association@demo.invalid');
    auth.dispose();
    repository.result.complete(
      await DemoAuthRepository().signIn(
        email: 'association@demo.invalid',
        password: DemoAuthRepository.samplePassword,
      ),
    );
    expect(await pending, isFalse);
    expect(auth.session, isNull);
  });

  test('Resume-time expiry removes access and reports expiry', () async {
    var instant = DateTime.utc(2026, 10, 14);
    final auth = controller(now: () => instant);
    await login(auth, 'association@demo.invalid');
    instant = instant.add(const Duration(minutes: 16));
    expect(auth.canAccessAssociation, isFalse);
    auth.checkSessionValidity();
    expect(auth.session, isNull);
    expect(auth.failure?.code, AuthFailureCode.sessionExpired);
  });

  testWidgets('Session timer expires without a backend request', (
    tester,
  ) async {
    final auth = controller();
    await login(auth, 'association@demo.invalid');
    await tester.pump(const Duration(minutes: 15));
    expect(auth.session, isNull);
    expect(auth.failure?.code, AuthFailureCode.sessionExpired);
  });

  test(
    'API and invalid modes never provide demo identities or fallback',
    () async {
      for (final mode in [DataMode.api, DataMode.invalid]) {
        final repository = RepositoryFactory.authentication(
          AppConfig(mode: mode),
        );
        final auth = controller(repository: repository, mode: mode);
        expect(repository.demoAccounts, isEmpty);
        expect(repository.demoPasswordHint, isNull);
        expect(repository.canAttemptSignIn, isFalse);
        expect(await login(auth, 'association@demo.invalid'), isFalse);
        expect(auth.session, isNull);
        expect(
          auth.failure?.code,
          mode == DataMode.api
              ? AuthFailureCode.integrationUnavailable
              : AuthFailureCode.invalidConfiguration,
        );
      }
    },
  );

  test(
    'Even an injected demo repository cannot authenticate API mode',
    () async {
      final auth = controller(mode: DataMode.api);
      expect(await login(auth, 'association@demo.invalid'), isFalse);
      expect(auth.failure?.code, AuthFailureCode.unexpectedResponse);
      expect(auth.session, isNull);
    },
  );

  test(
    'Release blocking hides demo credentials and rejects demo login',
    () async {
      final repository = DemoAuthRepository(blockDemo: true);
      expect(repository.canAttemptSignIn, isFalse);
      expect(repository.demoAccounts, isEmpty);
      expect(repository.demoPasswordHint, isNull);
      await expectLater(
        repository.signIn(
          email: 'association@demo.invalid',
          password: DemoAuthRepository.samplePassword,
        ),
        throwsA(
          isA<AuthFailure>().having(
            (failure) => failure.code,
            'code',
            AuthFailureCode.demoDisabled,
          ),
        ),
      );
    },
  );
}
