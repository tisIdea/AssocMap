import 'dart:async';

import 'package:flutter/foundation.dart';

import '../../app/configuration/app_config.dart';
import '../../data/models/auth_session.dart';
import '../../data/repositories/auth_repository.dart';
import 'auth_failure.dart';

class AuthController extends ChangeNotifier {
  AuthController({
    required this.repository,
    required this.mode,
    DateTime Function()? now,
  }) : _now = now ?? DateTime.now {
    final restorable = repository;
    if (restorable is RestorableAuthRepository) {
      _invalidations = restorable.invalidations.listen((failure) {
        if (_disposed) return;
        ++_operation;
        _expiryTimer?.cancel();
        _session = null;
        _failure = failure;
        _busy = false;
        notifyListeners();
      });
    }
  }

  final AuthRepository repository;
  final DataMode mode;
  final DateTime Function() _now;
  AuthSession? _session;
  AuthFailure? _failure;
  Timer? _expiryTimer;
  StreamSubscription<AuthFailure>? _invalidations;
  bool _busy = false;
  bool _disposed = false;
  int _operation = 0;

  AuthSession? get session => canAccessAssociation ? _session : null;
  AuthFailure? get failure => _failure;
  bool get busy => _busy;

  bool get canAccessAssociation {
    final current = _session;
    return !_disposed && current != null && _accessFailure(current) == null;
  }

  AuthFailure? _accessFailure(AuthSession current) {
    if (mode == DataMode.invalid || current.isDemo != (mode == DataMode.mock)) {
      return const AuthFailure(AuthFailureCode.unexpectedResponse);
    }
    if (!current.expiresAt.isAfter(_now())) {
      return const AuthFailure(AuthFailureCode.sessionExpired);
    }
    if (current.userId.trim().isEmpty || current.displayName.trim().isEmpty) {
      return const AuthFailure(AuthFailureCode.unexpectedResponse);
    }
    if (!current.accountIsActive || !current.roleIsActive) {
      return const AuthFailure(AuthFailureCode.accountUnavailable);
    }
    if (current.role != AccountRole.associationAccount) {
      return const AuthFailure(AuthFailureCode.wrongRole);
    }
    if (current.association?.isValid != true) {
      return const AuthFailure(AuthFailureCode.invalidAssociationLink);
    }
    return null;
  }

  Future<bool> signIn({required String email, required String password}) async {
    if (_disposed || _busy) return false;
    final operation = ++_operation;
    _expiryTimer?.cancel();
    _session = null;
    _failure = null;
    _busy = true;
    notifyListeners();
    try {
      final candidate = await repository.signIn(
        email: email.trim(),
        password: password,
      );
      if (_disposed || operation != _operation) return false;
      final denied = _accessFailure(candidate);
      if (denied != null) {
        _failure = denied;
        return false;
      }
      _session = candidate;
      _scheduleExpiry(candidate);
      return true;
    } on AuthFailure catch (error) {
      if (!_disposed && operation == _operation) _failure = error;
      return false;
    } catch (_) {
      if (!_disposed && operation == _operation) {
        _failure = const AuthFailure(AuthFailureCode.connectionUnavailable);
      }
      return false;
    } finally {
      if (!_disposed && operation == _operation) {
        _busy = false;
        notifyListeners();
      }
    }
  }

  Future<void> signOut() async {
    if (_disposed) return;
    final operation = ++_operation;
    _expiryTimer?.cancel();
    _session = null;
    _failure = null;
    _busy = true;
    notifyListeners();
    try {
      await repository.signOut();
    } catch (error) {
      if (!_disposed && operation == _operation) {
        _failure = error is AuthFailure
            ? error
            : const AuthFailure(AuthFailureCode.connectionUnavailable);
        notifyListeners();
      }
    } finally {
      if (!_disposed && operation == _operation) {
        _busy = false;
        notifyListeners();
      }
    }
  }

  void _scheduleExpiry(AuthSession candidate) {
    _expiryTimer?.cancel();
    var duration = candidate.expiresAt.difference(_now());
    if (!candidate.isDemo) duration -= const Duration(seconds: 30);
    if (duration < const Duration(seconds: 1)) {
      duration = const Duration(seconds: 1);
    }
    _expiryTimer = Timer(
      duration,
      candidate.isDemo ? expireSession : restoreSession,
    );
  }

  Future<void> restoreSession() async {
    final restorable = repository;
    if (_disposed || _busy || restorable is! RestorableAuthRepository) return;
    final operation = ++_operation;
    _expiryTimer?.cancel();
    _session = null;
    _failure = null;
    _busy = true;
    notifyListeners();
    try {
      final candidate = await restorable.restoreSession();
      if (_disposed || operation != _operation) return;
      if (candidate != null) {
        final denied = _accessFailure(candidate);
        if (denied != null) throw denied;
        _session = candidate;
        _scheduleExpiry(candidate);
      }
    } on AuthFailure catch (error) {
      if (!_disposed && operation == _operation) _failure = error;
    } catch (_) {
      if (!_disposed && operation == _operation) {
        _failure = const AuthFailure(AuthFailureCode.connectionUnavailable);
      }
    } finally {
      if (!_disposed && operation == _operation) {
        _busy = false;
        notifyListeners();
      }
    }
  }

  void clearFailure() {
    if (_disposed || _busy) return;
    _failure = null;
    notifyListeners();
  }

  void cancelSignIn() {
    if (_disposed || !_busy) return;
    ++_operation;
    _busy = false;
    _session = null;
    _failure = null;
    notifyListeners();
    unawaited(repository.signOut().catchError((Object _) {}));
  }

  void checkSessionValidity() {
    if (repository is RestorableAuthRepository) {
      unawaited(restoreSession());
      return;
    }
    if (_session != null && _accessFailure(_session!) != null) expireSession();
  }

  void expireSession() {
    if (_disposed || _session == null) return;
    ++_operation;
    _expiryTimer?.cancel();
    _session = null;
    _failure = const AuthFailure(AuthFailureCode.sessionExpired);
    _busy = false;
    notifyListeners();
  }

  @override
  void dispose() {
    _disposed = true;
    ++_operation;
    _expiryTimer?.cancel();
    unawaited(_invalidations?.cancel());
    _session = null;
    _failure = null;
    super.dispose();
  }
}
