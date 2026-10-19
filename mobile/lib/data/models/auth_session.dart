enum AccountRole {
  associationAccount('association_account'),
  administrator('system_admin'),
  fieldOfficer('field_officer'),
  unknown('');

  const AccountRole(this.code);
  final String code;

  static AccountRole fromCode(String value) => switch (value) {
    'association_account' => AccountRole.associationAccount,
    'system_admin' => AccountRole.administrator,
    'field_officer' => AccountRole.fieldOfficer,
    _ => AccountRole.unknown,
  };
}

class AssociationAccess {
  const AssociationAccess({
    required this.id,
    required this.name,
    required this.linkIsActive,
    required this.associationIsActive,
    required this.associationIsArchived,
  });

  final String id;
  final String name;
  final bool linkIsActive;
  final bool associationIsActive;
  final bool associationIsArchived;

  bool get isValid =>
      id.trim().isNotEmpty &&
      name.trim().isNotEmpty &&
      linkIsActive &&
      associationIsActive &&
      !associationIsArchived;
}

// Normalized repository output; this is not the current /api/v1/me schema.
class AuthSession {
  const AuthSession({
    required this.userId,
    required this.displayName,
    required this.role,
    required this.accountIsActive,
    required this.roleIsActive,
    required this.isDemo,
    required this.expiresAt,
    this.association,
  });

  final String userId;
  final String displayName;
  final AccountRole role;
  final bool accountIsActive;
  final bool roleIsActive;
  final bool isDemo;
  final DateTime expiresAt;
  final AssociationAccess? association;
}

class DemoAccountOption {
  const DemoAccountOption(this.label, this.email);
  final String label;
  final String email;
}
