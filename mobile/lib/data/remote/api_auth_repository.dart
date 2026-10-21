import 'dart:async';
import 'dart:convert';

import 'package:supabase_flutter/supabase_flutter.dart';

import '../../app/configuration/app_config.dart';
import '../../core/authentication/auth_failure.dart';
import '../../core/authentication/secure_auth_storage.dart';
import '../../core/networking/laravel_api_client.dart';
import '../models/auth_session.dart';
import '../models/laravel_me_profile.dart';
import '../repositories/auth_repository.dart';

class ApiAuthRepository implements RestorableAuthRepository {
  ApiAuthRepository({
    required this.config,
    this.provider,
    this.storage,
    this.startupFailure,
    LaravelApiClient? api,
  }) : _api = api ?? LaravelApiClient(config: config) {
    _subscription = provider?.onAuthStateChange.listen(
      (state) {
        if (state.event == AuthChangeEvent.signedOut && _approvedUser != null) {
          _approvedUser = null;
          ++_generation;
          _providerCleanup = storage?.clear().catchError((Object _) {
            if (!_invalidations.isClosed) {
              _invalidations.add(
                const AuthFailure(AuthFailureCode.storageUnavailable),
              );
            }
          });
          _invalidations.add(const AuthFailure(AuthFailureCode.sessionExpired));
        }
      },
      onError: (Object _) {
        /* Requests recheck identity before releasing data. */
      },
    );
  }

  final AppConfig config;
  final GoTrueClient? provider;
  final SecureAuthStorage? storage;
  final AuthFailure? startupFailure;
  final LaravelApiClient _api;
  final _invalidations = StreamController<AuthFailure>.broadcast();
  StreamSubscription<AuthState>? _subscription;
  Future<Session>? _refresh;
  Future<void>? _providerCleanup;
  String? _approvedUser;
  bool _signingIn = false;
  int _generation = 0;

  @override
  Stream<AuthFailure> get invalidations => _invalidations.stream;
  @override
  List<DemoAccountOption> get demoAccounts => const [];
  @override
  String? get demoPasswordHint => null;
  @override
  bool get canAttemptSignIn =>
      startupFailure == null &&
      config.authenticationConfigured &&
      provider != null;
  @override
  String? get signInBlocker => canAttemptSignIn
      ? null
      : (startupFailure ??
                const AuthFailure(AuthFailureCode.invalidConfiguration))
            .message;

  AuthFailure _failure(Object error) {
    if (error is AuthFailure) return error;
    if (error is ApiFailure) {
      return AuthFailure(switch (error.kind) {
        ApiFailureKind.unauthenticated => AuthFailureCode.sessionExpired,
        ApiFailureKind.forbidden => AuthFailureCode.accountUnavailable,
        ApiFailureKind.rateLimited => AuthFailureCode.rateLimited,
        ApiFailureKind.invalidResponse => AuthFailureCode.unexpectedResponse,
        ApiFailureKind.configuration => AuthFailureCode.invalidConfiguration,
        _ => AuthFailureCode.connectionUnavailable,
      });
    }
    if (error is AuthException) {
      if (error.statusCode == '429') {
        return const AuthFailure(AuthFailureCode.rateLimited);
      }
      if ([
        'invalid_credentials',
        'email_not_confirmed',
        'user_banned',
      ].contains(error.code)) {
        return const AuthFailure(AuthFailureCode.invalidCredentials);
      }
      if ([
        'bad_jwt',
        'refresh_token_not_found',
        'refresh_token_already_used',
        'session_not_found',
        'session_expired',
        'user_not_found',
      ].contains(error.code)) {
        return const AuthFailure(AuthFailureCode.sessionExpired);
      }
    }
    if (error is FormatException) {
      return const AuthFailure(AuthFailureCode.unexpectedResponse);
    }
    return const AuthFailure(AuthFailureCode.connectionUnavailable);
  }

  Future<Session> _session({bool forceRefresh = false}) async {
    final current = provider?.currentSession;
    if (current == null) {
      throw const AuthFailure(AuthFailureCode.sessionExpired);
    }
    final expiry = current.expiresAt;
    if (!forceRefresh &&
        expiry != null &&
        expiry > DateTime.now().millisecondsSinceEpoch ~/ 1000 + 30) {
      return current;
    }
    final pending = _refresh ??= (() async {
      final response = await provider!.refreshSession();
      if (response.session == null) {
        throw const AuthFailure(AuthFailureCode.sessionExpired);
      }
      return response.session!;
    })();
    try {
      return await pending;
    } finally {
      if (identical(_refresh, pending)) _refresh = null;
    }
  }

  void _checkGeneration(int generation) {
    if (generation != _generation) {
      throw const AuthFailure(AuthFailureCode.sessionExpired);
    }
  }

  Future<Map<String, dynamic>> _read(Uri uri, int generation) async {
    var session = await _session();
    _checkGeneration(generation);
    Map<String, dynamic> result;
    try {
      result = await _api.get(uri, session.accessToken);
    } on ApiFailure catch (error) {
      if (error.kind != ApiFailureKind.unauthenticated) rethrow;
      session = await _session(forceRefresh: true);
      _checkGeneration(generation);
      result = await _api.get(uri, session.accessToken);
    }
    _checkGeneration(generation);
    if (provider?.currentSession?.user.id != session.user.id) {
      throw const AuthFailure(AuthFailureCode.sessionExpired);
    }
    return result;
  }

  Future<AuthSession> _authorize(int generation) async {
    final profile = LaravelMeProfile.fromJson(
      await _read(config.identityUri!, generation),
    );
    final session = await _session();
    _checkGeneration(generation);
    if (profile.id != session.user.id || session.user.isAnonymous) {
      throw const AuthFailure(AuthFailureCode.unexpectedResponse);
    }
    if (!profile.isActive) {
      throw const AuthFailure(AuthFailureCode.accountUnavailable);
    }
    if (profile.role != AccountRole.associationAccount) {
      throw const AuthFailure(AuthFailureCode.wrongRole);
    }
    if (!profile.canAccessMobile) {
      throw const AuthFailure(AuthFailureCode.invalidAssociationLink);
    }
    final association = profile.mobile!.association!;
    if (session.expiresAt == null) {
      throw const AuthFailure(AuthFailureCode.unexpectedResponse);
    }
    await storage?.persistSession(jsonEncode(session.toJson()));
    await storage?.checkPersistence();
    _checkGeneration(generation);
    _approvedUser = profile.id;
    return AuthSession(
      userId: profile.id,
      displayName: profile.displayName,
      role: profile.role,
      accountIsActive: true,
      roleIsActive: true,
      isDemo: false,
      expiresAt: DateTime.fromMillisecondsSinceEpoch(session.expiresAt! * 1000),
      association: AssociationAccess(
        id: association.id,
        name: association.name,
        linkIsActive: true,
        associationIsActive: true,
        associationIsArchived: false,
      ),
    );
  }

  @override
  Future<AuthSession> signIn({
    required String email,
    required String password,
  }) async {
    if (!canAttemptSignIn) {
      throw startupFailure ??
          const AuthFailure(AuthFailureCode.invalidConfiguration);
    }
    if (_signingIn) {
      throw const AuthFailure(AuthFailureCode.connectionUnavailable);
    }
    _signingIn = true;
    final generation = ++_generation;
    _approvedUser = null;
    storage?.allowPersistence();
    try {
      final cleanup = _providerCleanup;
      if (cleanup != null) await cleanup;
      _checkGeneration(generation);
      await provider!.signInWithPassword(
        email: email.trim(),
        password: password,
      );
      _checkGeneration(generation);
      return await _authorize(generation);
    } catch (error) {
      await signOut();
      throw _failure(error);
    } finally {
      _signingIn = false;
    }
  }

  @override
  Future<AuthSession?> restoreSession() async {
    if (!canAttemptSignIn || provider?.currentSession == null) return null;
    final generation = _generation;
    try {
      return await _authorize(generation);
    } catch (error) {
      final failure = _failure(error);
      if (failure.code != AuthFailureCode.connectionUnavailable &&
          failure.code != AuthFailureCode.rateLimited) {
        await signOut();
      }
      throw failure;
    }
  }

  Future<Map<String, dynamic>> read(Uri uri) async {
    if (_approvedUser == null) {
      throw const ApiFailure(ApiFailureKind.unauthenticated);
    }
    final generation = _generation;
    try {
      return await _read(uri, generation);
    } catch (error) {
      if (generation != _generation) {
        final cleanup = _providerCleanup;
        if (cleanup != null) await cleanup;
        throw const ApiFailure(ApiFailureKind.unauthenticated);
      }
      final failure = _failure(error);
      if ([
        AuthFailureCode.sessionExpired,
        AuthFailureCode.accountUnavailable,
      ].contains(failure.code)) {
        _invalidations.add(failure);
        await signOut();
      }
      if (error is ApiFailure) rethrow;
      throw ApiFailure(switch (failure.code) {
        AuthFailureCode.sessionExpired => ApiFailureKind.unauthenticated,
        AuthFailureCode.accountUnavailable => ApiFailureKind.forbidden,
        _ => ApiFailureKind.offline,
      });
    }
  }

  @override
  Future<void> signOut() async {
    ++_generation;
    _approvedUser = null;
    // Disable persistence before any late refresh/sign-in event can write again.
    AuthFailure? cleanupFailure;
    try {
      await storage?.clear();
    } catch (_) {
      cleanupFailure = const AuthFailure(AuthFailureCode.storageUnavailable);
    }
    try {
      await provider?.signOut(scope: SignOutScope.local);
    } catch (_) {
      // The SDK removes its local session before attempting remote logout.
    }
    if (cleanupFailure != null) throw cleanupFailure;
  }

  Future<void> dispose() async {
    ++_generation;
    _approvedUser = null;
    await _subscription?.cancel();
    await _invalidations.close();
    _api.close();
  }
}
