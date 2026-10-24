import '../models/auth_session.dart';
import '../../core/authentication/auth_failure.dart';

abstract interface class AuthRepository {
  List<DemoAccountOption> get demoAccounts;
  String? get demoPasswordHint;
  bool get canAttemptSignIn;
  String? get signInBlocker;

  Future<AuthSession> signIn({required String email, required String password});
  Future<void> signOut();
}

abstract interface class RestorableAuthRepository implements AuthRepository {
  Future<AuthSession?> restoreSession();
  Stream<AuthFailure> get invalidations;
}
