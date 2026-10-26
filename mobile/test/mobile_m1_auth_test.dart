import 'dart:async';
import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:supabase_flutter/supabase_flutter.dart';
import 'package:mobile/app/configuration/app_config.dart';
import 'package:mobile/core/authentication/auth_controller.dart';
import 'package:mobile/core/authentication/auth_failure.dart';
import 'package:mobile/core/authentication/secure_auth_storage.dart';
import 'package:mobile/core/networking/bounded_http_client.dart';
import 'package:mobile/core/networking/laravel_api_client.dart';
import 'package:mobile/data/remote/api_auth_repository.dart';
import 'package:mobile/data/remote/api_analytics_repository.dart';
import 'package:mobile/data/remote/api_dashboard_repository.dart';
import 'package:mobile/data/repositories/dashboard_repository.dart';
import 'package:mobile/data/repositories/analytics_repository.dart';

import 'analytics_report_test.dart' show report;
import 'association_dashboard_test.dart' show dashboard;

class _UnavailableSecureStorage extends FlutterSecureStorage {
  @override
  Future<void> write({
    required String key,
    required String? value,
    AppleOptions? iOptions,
    AndroidOptions? aOptions,
    LinuxOptions? lOptions,
    WebOptions? webOptions,
    AppleOptions? mOptions,
    WindowsOptions? wOptions,
  }) async {
    throw Exception('Synthetic secure storage failure');
  }
}

const userId = '11111111-1111-4111-8111-111111111111';
const associationId = '33333333-3333-4333-8333-333333333333';
const config = AppConfig(
  mode: DataMode.api,
  backendBaseUrl: 'https://backend.example.test',
  supabaseUrl: 'https://auth.example.test',
  supabasePublishableKey: 'sb_publishable_test_only',
);

String token({int seconds = 3600, String suffix = 'signature'}) {
  String part(Object value) =>
      base64Url.encode(utf8.encode(jsonEncode(value))).replaceAll('=', '');
  return '${part({'alg': 'HS256'})}.${part({'sub': userId, 'aud': 'authenticated', 'exp': DateTime.now().millisecondsSinceEpoch ~/ 1000 + seconds})}.$suffix';
}

Map<String, dynamic> providerSession({
  int seconds = 3600,
  String suffix = 'signature',
}) => {
  'access_token': token(seconds: seconds, suffix: suffix),
  'refresh_token': 'synthetic-refresh',
  'token_type': 'bearer',
  'expires_in': seconds,
  'user': {
    'id': userId,
    'email': 'synthetic@example.test',
    'app_metadata': <String, dynamic>{},
    'user_metadata': <String, dynamic>{},
    'aud': 'authenticated',
    'created_at': '2026-01-01T00:00:00Z',
  },
};

Map<String, dynamic> me({
  String role = 'association_account',
  bool eligible = true,
}) => {
  'data': {
    'id': userId,
    'display_name': 'Synthetic Account',
    'role': {'code': role, 'name': 'Synthetic role'},
    'is_active': true,
    'mobile': {
      'eligible': eligible,
      'reason': eligible ? null : 'association_unavailable',
      'association': eligible
          ? {'id': associationId, 'name': 'Synthetic association'}
          : null,
    },
  },
};

http.Response json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {
    'content-type': 'application/json',
    'x-supabase-api-version': '2024-01-01',
  },
);

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  late GoTrueClient provider;
  late ApiAuthRepository repository;
  late SecureAuthStorage storage;
  late List<http.Request> authRequests;
  late List<http.Request> apiRequests;
  late FutureOr<http.Response> Function(http.Request) authReply;
  late FutureOr<http.Response> Function(http.Request) apiReply;

  setUp(() {
    FlutterSecureStorage.setMockInitialValues({});
    authRequests = [];
    apiRequests = [];
    authReply = (request) => request.url.path.endsWith('/logout')
        ? http.Response('', 204)
        : json(providerSession());
    apiReply = (_) => json(me());
    provider = GoTrueClient(
      url: '${config.supabaseUrl}/auth/v1',
      autoRefreshToken: false,
      headers: {'apikey': config.supabasePublishableKey},
      httpClient: MockClient((request) async {
        authRequests.add(request);
        return authReply(request);
      }),
    );
    storage = SecureAuthStorage(key: 'synthetic-session');
    repository = ApiAuthRepository(
      config: config,
      provider: provider,
      storage: storage,
      api: LaravelApiClient(
        config: config,
        client: BoundedHttpClient(
          clientFactory: () => MockClient((request) async {
            apiRequests.add(request);
            return apiReply(request);
          }),
        ),
      ),
    );
  });
  tearDown(() async {
    await repository.dispose();
    provider.dispose();
  });

  Future<void> login() => repository.signIn(
    email: 'synthetic@example.test',
    password: 'test-only-password',
  );
  Matcher failure(AuthFailureCode code) =>
      isA<AuthFailure>().having((e) => e.code, 'code', code);
  final dashboardUri = Uri.parse(
    'https://backend.example.test/api/v1/association/dashboard',
  );

  test(
    'SDK password login requires Laravel approval; tokens never enter AuthSession',
    () async {
      final session = await repository.signIn(
        email: ' synthetic@example.test ',
        password: ' test-only-password ',
      );
      expect(repository.canAttemptSignIn, isTrue);
      expect(session.isDemo, isFalse);
      expect(session.association?.id, associationId);
      expect(authRequests.single.url.queryParameters['grant_type'], 'password');
      expect(
        jsonDecode(authRequests.single.body)['password'],
        ' test-only-password ',
      );
      expect(apiRequests.single.url, config.identityUri);
      expect(
        apiRequests.single.headers['Authorization'],
        startsWith('Bearer '),
      );
      expect(apiRequests.single.url.queryParameters, isEmpty);
      expect(await storage.hasAccessToken(), isTrue);
    },
  );

  test('incorrect credentials are generic and never reach Laravel', () async {
    authReply = (_) => json({
      'code': 'invalid_credentials',
      'msg': 'provider private detail',
    }, 400);
    await expectLater(
      login(),
      throwsA(failure(AuthFailureCode.invalidCredentials)),
    );
    expect(apiRequests, isEmpty);
    expect(await storage.hasAccessToken(), isFalse);
  });

  for (final role in ['system_admin', 'field_officer']) {
    test('verified $role cannot enter association navigation', () async {
      apiReply = (_) => json(me(role: role, eligible: false));
      await expectLater(login(), throwsA(failure(AuthFailureCode.wrongRole)));
      expect(provider.currentSession, isNull);
      expect(await storage.hasAccessToken(), isFalse);
    });
  }

  test(
    'missing link and archived association eligibility fail closed',
    () async {
      apiReply = (_) => json(me(eligible: false));
      await expectLater(
        login(),
        throwsA(failure(AuthFailureCode.invalidAssociationLink)),
      );
      expect(await storage.hasAccessToken(), isFalse);
    },
  );

  test(
    'server forbidden account is denied without exposing its response body',
    () async {
      apiReply = (_) => json({
        'error': {'code': 'private detail'},
      }, 403);
      await expectLater(
        login(),
        throwsA(failure(AuthFailureCode.accountUnavailable)),
      );
      expect(provider.currentSession, isNull);
    },
  );

  test('identity mismatch never grants a session', () async {
    apiReply = (_) {
      final value = me();
      (value['data'] as Map)['id'] = associationId;
      return json(value);
    };
    await expectLater(
      login(),
      throwsA(failure(AuthFailureCode.unexpectedResponse)),
    );
  });

  test(
    'restoration rechecks eligibility; revoked access clears saved credentials',
    () async {
      await login();
      expect(
        (await repository.restoreSession())?.association?.id,
        associationId,
      );
      expect(apiRequests.length, 2);
      apiReply = (_) => json(me(eligible: false));
      await expectLater(
        repository.restoreSession(),
        throwsA(failure(AuthFailureCode.invalidAssociationLink)),
      );
      expect(provider.currentSession, isNull);
      expect(await storage.hasAccessToken(), isFalse);
    },
  );

  test(
    'SDK restores encrypted persisted session before Laravel eligibility recheck',
    () async {
      await login();
      final saved = await storage.accessToken();
      await provider.signOut();
      await provider.recoverSession(saved!);
      expect((await repository.restoreSession())?.userId, userId);
    },
  );

  test(
    'logout clears device credentials even when provider is offline',
    () async {
      await login();
      authReply = (_) =>
          throw http.ClientException('synthetic network failure');
      await repository.signOut();
      expect(provider.currentSession, isNull);
      expect(await storage.hasAccessToken(), isFalse);
      await storage.persistSession('late SDK event');
      expect(await storage.hasAccessToken(), isFalse);
    },
  );

  test(
    'network failure is recoverable and does not become an empty dashboard',
    () async {
      await login();
      apiReply = (_) => throw http.ClientException('synthetic offline');
      final dashboard = ApiDashboardRepository(
        config: config,
        read: repository.read,
      );
      await expectLater(
        dashboard.load(),
        throwsA(
          isA<DashboardFailure>().having(
            (e) => e.kind,
            'kind',
            DashboardFailureKind.offline,
          ),
        ),
      );
      expect(provider.currentSession, isNotNull);
    },
  );

  test(
    '401 retries one safe GET after SDK refresh and invalidates on repeated rejection',
    () async {
      await login();
      var refreshes = 0;
      authReply = (request) {
        if (request.url.queryParameters['grant_type'] == 'refresh_token') {
          refreshes++;
        }
        return request.url.path.endsWith('/logout')
            ? http.Response('', 204)
            : json(providerSession(suffix: 'renewed'));
      };
      apiReply = (_) => json({
        'error': {'code': 'unauthenticated'},
      }, 401);
      final event = repository.invalidations.first;
      await expectLater(
        repository.read(dashboardUri),
        throwsA(isA<ApiFailure>()),
      );
      expect((await event).code, AuthFailureCode.sessionExpired);
      expect(refreshes, 1);
      expect(apiRequests.length, 3);
      expect(await storage.hasAccessToken(), isFalse);
    },
  );

  test('expired saved token refreshes once for concurrent API reads', () async {
    await login();
    provider.currentSession!.expiresAt =
        DateTime.now().millisecondsSinceEpoch ~/ 1000 - 1;
    final refreshed = Completer<http.Response>();
    var refreshes = 0;
    authReply = (_) {
      refreshes++;
      return refreshed.future;
    };
    final first = repository.read(dashboardUri);
    final second = repository.read(dashboardUri);
    await Future<void>.delayed(Duration.zero);
    refreshed.complete(json(providerSession(suffix: 'renewed')));
    await Future.wait([first, second]);
    expect(refreshes, 1);
  });

  test('403 invalidates protected navigation without refresh', () async {
    await login();
    final controller = AuthController(
      repository: repository,
      mode: DataMode.api,
    );
    addTearDown(controller.dispose);
    await controller.restoreSession();
    expect(controller.canAccessAssociation, isTrue);
    apiReply = (_) => json({
      'error': {'code': 'forbidden'},
    }, 403);
    await expectLater(
      repository.read(dashboardUri),
      throwsA(isA<ApiFailure>()),
    );
    await Future<void>.delayed(Duration.zero);
    expect(controller.canAccessAssociation, isFalse);
    expect(controller.failure?.code, AuthFailureCode.accountUnavailable);
  });

  test(
    'logout during login prevents late authorization and storage writes',
    () async {
      final pending = Completer<http.Response>();
      authReply = (request) => request.url.path.endsWith('/logout')
          ? http.Response('', 204)
          : pending.future;
      final signingIn = login();
      final result = expectLater(
        signingIn,
        throwsA(failure(AuthFailureCode.sessionExpired)),
      );
      await repository.signOut();
      pending.complete(json(providerSession()));
      await result;
      expect(provider.currentSession, isNull);
      expect(apiRequests, isEmpty);
      expect(await storage.hasAccessToken(), isFalse);
    },
  );

  test(
    'real adapters parse dashboard and analytics contracts without scope parameters',
    () async {
      await login();
      apiReply = (request) => json(switch (request.url.path) {
        '/api/v1/association/dashboard' => {'data': dashboard()},
        '/api/v1/association/reports/options' => {
          'data': {
            'years': [2026],
            'program_years': [2026],
          },
        },
        _ => {'data': report()},
      });
      expect(
        (await ApiDashboardRepository(
          config: config,
          read: repository.read,
        ).load()).associationId,
        associationId,
      );
      final analytics = ApiAnalyticsRepository(
        config: config,
        read: repository.read,
      );
      expect((await analytics.periods()).years, [2026]);
      expect(
        (await analytics.load({
          'report_type': 'income',
          'year': '2026',
        })).sections.first.measures.first.value,
        '0.00',
      );
      expect(
        apiRequests.every(
          (r) => !r.url.queryParameters.containsKey('association_id'),
        ),
        isTrue,
      );
      await expectLater(
        analytics.load({'association_id': associationId}),
        throwsFormatException,
      );
    },
  );

  test(
    'gateway HTML, redirects, rate limits and malformed JSON never grant access',
    () async {
      for (final response in [
        http.Response('<html>gateway</html>', 200),
        http.Response('', 302),
        json({'error': 'rate limit'}, 429),
        http.Response('{', 200, headers: {'content-type': 'application/json'}),
      ]) {
        apiReply = (_) => response;
        await expectLater(login(), throwsA(isA<AuthFailure>()));
        expect(provider.currentSession, isNull);
      }
    },
  );

  test(
    'bearer token cannot be sent to another origin or administrative endpoint',
    () async {
      await login();
      for (final uri in [
        'https://other.example.test/api/v1/me',
        'https://backend.example.test/admin/users',
      ]) {
        await expectLater(
          repository.read(Uri.parse(uri)),
          throwsA(isA<ApiFailure>()),
        );
      }
      expect(apiRequests.length, 1);
    },
  );

  test(
    'secure storage failure blocks login and clears the SDK session',
    () async {
      await repository.dispose();
      repository = ApiAuthRepository(
        config: config,
        provider: provider,
        storage: SecureAuthStorage(
          key: 'synthetic-session',
          storage: _UnavailableSecureStorage(),
        ),
        api: LaravelApiClient(
          config: config,
          client: MockClient((_) async => json(me())),
        ),
      );
      await expectLater(
        login(),
        throwsA(failure(AuthFailureCode.storageUnavailable)),
      );
      expect(provider.currentSession, isNull);
    },
  );

  test(
    'provider network failure never reaches Laravel or saves a session',
    () async {
      authReply = (_) => throw http.ClientException('synthetic offline');
      await expectLater(
        login(),
        throwsA(failure(AuthFailureCode.connectionUnavailable)),
      );
      expect(apiRequests, isEmpty);
      expect(await storage.hasAccessToken(), isFalse);
    },
  );

  test(
    'expired refresh credentials remove access instead of retaining the old token',
    () async {
      await login();
      provider.currentSession!.expiresAt =
          DateTime.now().millisecondsSinceEpoch ~/ 1000 - 1;
      authReply = (request) => request.url.path.endsWith('/logout')
          ? http.Response('', 204)
          : json({
              'code': 'refresh_token_not_found',
              'msg': 'Synthetic invalid refresh',
            }, 400);
      await expectLater(
        repository.read(dashboardUri),
        throwsA(isA<ApiFailure>()),
      );
      expect(provider.currentSession, isNull);
      expect(await storage.hasAccessToken(), isFalse);
    },
  );

  test(
    'bounded transport preserves TLS defaults and rejects redirects, oversize and stalled streams',
    () async {
      var followed = true;
      final transport = BoundedHttpClient(
        maxBytes: 4,
        timeout: const Duration(milliseconds: 20),
        clientFactory: () => MockClient((request) async {
          followed = request.followRedirects;
          return http.Response('large', 200);
        }),
      );
      await expectLater(
        transport.get(Uri.parse('https://example.test')),
        throwsFormatException,
      );
      expect(followed, isFalse);
      transport.close();
      final stalled = BoundedHttpClient(
        timeout: const Duration(milliseconds: 20),
        clientFactory: () =>
            MockClient((_) => Completer<http.Response>().future),
      );
      await expectLater(
        stalled.get(Uri.parse('https://example.test')),
        throwsA(isA<TimeoutException>()),
      );
      stalled.close();
    },
  );

  test(
    'report 401 refreshes once and replays only the safe report GET',
    () async {
      await login();
      authReply = (_) => json(providerSession(suffix: 'renewed'));
      var reports = 0;
      apiReply = (_) => ++reports == 1
          ? json({'error': 'expired'}, 401)
          : json({'data': report()});
      final analytics = ApiAnalyticsRepository(
        config: config,
        read: repository.read,
      );
      expect((await analytics.load({'report_type': 'income'})).empty, isFalse);
      expect(reports, 2);
      expect(
        apiRequests.skip(1).every((request) => request.method == 'GET'),
        isTrue,
      );
      expect(
        authRequests.where(
          (request) =>
              request.url.queryParameters['grant_type'] == 'refresh_token',
        ),
        hasLength(1),
      );
      expect(
        authRequests.where(
          (request) => request.url.queryParameters['grant_type'] == 'password',
        ),
        hasLength(1),
      );
      expect(
        apiRequests[1].headers['Authorization'],
        isNot(apiRequests[2].headers['Authorization']),
      );
    },
  );

  for (final status in [401, 403, 429, 503]) {
    test(
      'report $status preserves session policy and bounded retry behavior',
      () async {
        await login();
        authReply = (request) => request.url.path.endsWith('/logout')
            ? http.Response('', 204)
            : json(providerSession(suffix: 'renewed'));
        apiReply = (_) => json({'error': 'synthetic'}, status);
        final analytics = ApiAnalyticsRepository(
          config: config,
          read: repository.read,
        );
        final expected = switch (status) {
          401 => AnalyticsFailureKind.expired,
          403 => AnalyticsFailureKind.denied,
          429 => AnalyticsFailureKind.rateLimited,
          _ => AnalyticsFailureKind.unavailable,
        };
        await expectLater(
          analytics.load({'report_type': 'income'}),
          throwsA(
            isA<AnalyticsFailure>().having((e) => e.kind, 'kind', expected),
          ),
        );
        expect(apiRequests.length, status == 401 ? 3 : 2);
        expect(
          authRequests.where(
            (request) =>
                request.url.queryParameters['grant_type'] == 'refresh_token',
          ),
          hasLength(status == 401 ? 1 : 0),
        );
        expect(provider.currentSession == null, status == 401 || status == 403);
        expect(await storage.hasAccessToken(), status == 429 || status == 503);
      },
    );
  }

  test(
    'logout while report headers stall cancels immediately and rejects late data',
    () async {
      await login();
      final pending = Completer<http.Response>();
      apiReply = (_) => pending.future;
      final analytics = ApiAnalyticsRepository(
        config: config,
        read: repository.read,
      );
      final check = expectLater(
        analytics.load({'report_type': 'income'}),
        throwsA(
          isA<AnalyticsFailure>().having(
            (e) => e.kind,
            'kind',
            AnalyticsFailureKind.expired,
          ),
        ),
      );
      await Future<void>.delayed(Duration.zero);
      await repository.signOut();
      await check;
      expect(await storage.hasAccessToken(), isFalse);
      pending.complete(json({'data': report()}));
      await Future<void>.delayed(Duration.zero);
      expect(provider.currentSession, isNull);
      apiReply = (_) => json(me());
      await login();
      expect(await storage.hasAccessToken(), isTrue);
    },
  );

  test(
    'provider sign-out cancels an active report without a refresh',
    () async {
      await login();
      final pending = Completer<http.Response>();
      apiReply = (_) => pending.future;
      final check = expectLater(
        repository.read(
          Uri.parse('https://backend.example.test/api/v1/association/reports'),
        ),
        throwsA(
          isA<ApiFailure>().having(
            (e) => e.kind,
            'kind',
            ApiFailureKind.unauthenticated,
          ),
        ),
      );
      await Future<void>.delayed(Duration.zero);
      await provider.signOut(scope: SignOutScope.local);
      await check;
      pending.complete(json({'data': report()}));
      expect(
        authRequests.where(
          (request) =>
              request.url.queryParameters['grant_type'] == 'refresh_token',
        ),
        isEmpty,
      );
      expect(await storage.hasAccessToken(), isFalse);
    },
  );
}
