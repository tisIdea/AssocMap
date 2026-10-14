import 'auth_session.dart';

// Parser for the source-verified profile response; it cannot grant association access.
class LaravelMeProfile {
  const LaravelMeProfile({
    required this.id,
    required this.displayName,
    required this.role,
    required this.roleName,
    required this.isActive,
  });

  final String id;
  final String displayName;
  final AccountRole role;
  final String roleName;
  final bool isActive;

  factory LaravelMeProfile.fromJson(Map<String, dynamic> json) {
    final data = json['data'];
    if (data is! Map<String, dynamic> ||
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
    return LaravelMeProfile(
      id: text(data, 'id'),
      displayName: text(data, 'display_name'),
      role: AccountRole.fromCode(text(role, 'code')),
      roleName: text(role, 'name'),
      isActive: data['is_active'] as bool,
    );
  }
}
