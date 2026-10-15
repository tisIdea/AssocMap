import 'auth_session.dart';

// Source-verified response data; parsing does not authenticate a session.
class LaravelMeProfile {
  const LaravelMeProfile({
    required this.id,
    required this.displayName,
    required this.role,
    required this.roleName,
    required this.isActive,
    this.mobile,
  });

  final String id;
  final String displayName;
  final AccountRole role;
  final String roleName;
  final bool isActive;
  final LaravelMobileContext? mobile;

  bool get canAccessMobile =>
      isActive &&
      role == AccountRole.associationAccount &&
      mobile?.eligible == true &&
      mobile?.association != null;

  factory LaravelMeProfile.fromJson(Map<String, dynamic> json) {
    final data = json['data'];
    if (json.containsKey('error') ||
        data is! Map<String, dynamic> ||
        data['role'] is! Map<String, dynamic> ||
        data['is_active'] is! bool) {
      throw const FormatException('Invalid profile response.');
    }
    String text(Map<String, dynamic> source, String key) {
      final value = source[key];
      if (value is! String || value.trim().isEmpty) {
        throw const FormatException('Invalid profile response.');
      }
      return value.trim();
    }

    final role = data['role'] as Map<String, dynamic>;
    final profileId = text(data, 'id');
    if (!_isUuid(profileId)) {
      throw const FormatException('Invalid profile identifier.');
    }
    final mobile = data['mobile'];
    if (mobile != null && mobile is! Map<String, dynamic>) {
      throw const FormatException('Invalid mobile eligibility response.');
    }
    return LaravelMeProfile(
      id: profileId,
      displayName: text(data, 'display_name'),
      role: AccountRole.fromCode(text(role, 'code')),
      roleName: text(role, 'name'),
      isActive: data['is_active'] as bool,
      mobile: mobile == null
          ? null
          : LaravelMobileContext.fromJson(mobile as Map<String, dynamic>),
    );
  }
}

class LaravelMobileContext {
  const LaravelMobileContext({required this.eligible, required this.reason, required this.association});

  final bool eligible;
  final String? reason;
  final LaravelAssociationSummary? association;

  factory LaravelMobileContext.fromJson(Map<String, dynamic> json) {
    if (json['eligible'] is! bool || !json.containsKey('reason') ||
        !json.containsKey('association')) {
      throw const FormatException('Invalid mobile eligibility response.');
    }
    final eligible = json['eligible'] as bool;
    final reason = json['reason'];
    final association = json['association'];
    if (eligible) {
      if (reason != null || association is! Map<String, dynamic>) {
        throw const FormatException('Eligible mobile context requires an association.');
      }
      return LaravelMobileContext(eligible: true, reason: null,
          association: LaravelAssociationSummary.fromJson(association));
    }
    if (reason is! String || reason.trim().isEmpty || association != null) {
      throw const FormatException('Invalid denied mobile context.');
    }
    return LaravelMobileContext(eligible: false, reason: reason, association: null);
  }
}

class LaravelAssociationSummary {
  const LaravelAssociationSummary({required this.id, required this.name});
  final String id;
  final String name;

  factory LaravelAssociationSummary.fromJson(Map<String, dynamic> json) {
    final id = json['id'];
    final name = json['name'];
    if (id is! String ||
        !_isUuid(id) ||
        name is! String || name.trim().isEmpty) {
      throw const FormatException('Invalid authorized association summary.');
    }
    return LaravelAssociationSummary(id: id, name: name.trim());
  }
}

bool _isUuid(String value) => RegExp(
  r'^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$',
).hasMatch(value);
