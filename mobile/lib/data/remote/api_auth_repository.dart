import '../../core/authentication/auth_failure.dart';
import '../models/auth_session.dart';
import '../repositories/auth_repository.dart';

class ApiAuthRepository implements AuthRepository {
  const ApiAuthRepository({this.invalidConfiguration = false});
  final bool invalidConfiguration;

  AuthFailure get _failure => AuthFailure(
    invalidConfiguration
        ? AuthFailureCode.invalidConfiguration
        : AuthFailureCode.integrationUnavailable,
  );

  @override
  List<DemoAccountOption> get demoAccounts => const [];
  @override
  String? get demoPasswordHint => null;
  @override
  bool get canAttemptSignIn => false;
  @override
  String get signInBlocker => _failure.message;

  @override
  Future<AuthSession> signIn({
    required String email,
    required String password,
  }) async {
    throw _failure;
  }

  @override
  Future<void> signOut() async {}
}
