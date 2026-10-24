import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:supabase_flutter/supabase_flutter.dart';

import 'auth_failure.dart';

class SecureAuthStorage extends LocalStorage {
  SecureAuthStorage({required this.key, FlutterSecureStorage? storage})
    : _storage = storage ?? const FlutterSecureStorage();
  final String key;
  final FlutterSecureStorage _storage;
  Future<void> _pending = Future.value();
  bool _enabled = true;
  bool _healthy = true;

  Future<void> _serialize(Future<void> Function() action) {
    final result = _pending.then((_) async {
      try {
        await action();
      } catch (_) {
        throw const AuthFailure(AuthFailureCode.storageUnavailable);
      }
    });
    _pending = result.catchError((Object _) {
      _healthy = false;
    });
    return result;
  }

  @override
  Future<void> initialize() async {
    await _storage.containsKey(key: key);
  }

  @override
  Future<bool> hasAccessToken() async => await accessToken() != null;
  @override
  Future<String?> accessToken() async {
    await _pending;
    return _storage.read(key: key);
  }

  @override
  Future<void> persistSession(String persistSessionString) => _serialize(
    () async {
      if (_enabled) await _storage.write(key: key, value: persistSessionString);
    },
  );
  @override
  Future<void> removePersistedSession() =>
      _serialize(() => _storage.delete(key: key));

  void allowPersistence() {
    _enabled = true;
  }

  Future<void> clear() async {
    _enabled = false;
    await removePersistedSession();
    _healthy = true;
  }

  Future<void> checkPersistence() async {
    await _pending;
    if (!_healthy) throw const AuthFailure(AuthFailureCode.storageUnavailable);
  }
}
