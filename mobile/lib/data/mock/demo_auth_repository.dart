import 'package:flutter/foundation.dart';

import '../../core/authentication/auth_failure.dart';
import '../models/auth_session.dart';
import '../repositories/auth_repository.dart';

class DemoAuthRepository implements AuthRepository {
  DemoAuthRepository({DateTime Function()? now, this.blockDemo = false})
    : _now = now ?? DateTime.now;

  final DateTime Function() _now;
  final bool blockDemo;
  static const samplePassword = 'DemoOnly!2026';
  static const sessionDuration = Duration(minutes: 15);
  static const _accounts = [
    DemoAccountOption('Valid Association Account', 'association@demo.invalid'),
    DemoAccountOption('Administrator — denied', 'admin@demo.invalid'),
    DemoAccountOption('Field Officer — denied', 'officer@demo.invalid'),
    DemoAccountOption('Missing association link', 'unlinked@demo.invalid'),
    DemoAccountOption(
      'Inactive association link',
      'inactive-link@demo.invalid',
    ),
    DemoAccountOption(
      'Inactive association',
      'inactive-association@demo.invalid',
    ),
    DemoAccountOption('Archived association', 'archived@demo.invalid'),
    DemoAccountOption('Disabled account', 'disabled@demo.invalid'),
    DemoAccountOption('Disabled role', 'disabled-role@demo.invalid'),
    DemoAccountOption(
      'Unavailable authentication service',
      'unavailable@demo.invalid',
    ),
  ];

  @override
  bool get canAttemptSignIn => !kReleaseMode && !blockDemo;

  @override
  String? get signInBlocker => canAttemptSignIn
      ? null
      : const AuthFailure(AuthFailureCode.demoDisabled).message;

  @override
  List<DemoAccountOption> get demoAccounts =>
      canAttemptSignIn ? _accounts : const [];

  @override
  String? get demoPasswordHint => canAttemptSignIn ? samplePassword : null;

  @override
  Future<AuthSession> signIn({
    required String email,
    required String password,
  }) async {
    if (!canAttemptSignIn) {
      throw const AuthFailure(AuthFailureCode.demoDisabled);
    }
    final identity = email.trim().toLowerCase();
    if (password != samplePassword ||
        !_accounts.any((account) => account.email == identity)) {
      throw const AuthFailure(AuthFailureCode.invalidCredentials);
    }
    if (identity == 'unavailable@demo.invalid') {
      throw const AuthFailure(AuthFailureCode.connectionUnavailable);
    }
    final role = switch (identity) {
      'admin@demo.invalid' => AccountRole.administrator,
      'officer@demo.invalid' => AccountRole.fieldOfficer,
      _ => AccountRole.associationAccount,
    };
    return AuthSession(
      userId:
          'demo-user-${_accounts.indexWhere((account) => account.email == identity)}',
      displayName: 'Demo association representative',
      role: role,
      accountIsActive: identity != 'disabled@demo.invalid',
      roleIsActive: identity != 'disabled-role@demo.invalid',
      isDemo: true,
      expiresAt: _now().add(sessionDuration),
      association:
          role != AccountRole.associationAccount ||
              identity == 'unlinked@demo.invalid'
          ? null
          : AssociationAccess(
              id: 'demo-association-01',
              name: 'Demo Harbor Livelihood Association',
              linkIsActive: identity != 'inactive-link@demo.invalid',
              associationIsActive:
                  identity != 'inactive-association@demo.invalid',
              associationIsArchived: identity == 'archived@demo.invalid',
            ),
    );
  }

  @override
  Future<void> signOut() async {}
}
