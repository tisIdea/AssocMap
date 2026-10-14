import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/data/models/auth_session.dart';
import 'package:mobile/data/models/laravel_me_profile.dart';

void main() {
  Map<String, dynamic> response({String role = 'association_account'}) => {
    'data': {
      'id': '00000000-0000-0000-0000-000000000001',
      'display_name': 'Synthetic profile',
      'role': {'code': role, 'name': 'Synthetic role name'},
      'is_active': true,
    },
  };

  test(
    'Parses the source-verified me fields without inventing association eligibility',
    () {
      final profile = LaravelMeProfile.fromJson(response());
      expect(profile.displayName, 'Synthetic profile');
      expect(profile.role, AccountRole.associationAccount);
      expect(profile.isActive, isTrue);
    },
  );

  test('Unknown role codes remain unknown', () {
    expect(
      LaravelMeProfile.fromJson(response(role: 'unapproved_role')).role,
      AccountRole.unknown,
    );
  });

  test(
    'Malformed me responses fail rather than synthesizing missing fields',
    () {
      for (final json in <Map<String, dynamic>>[
        {},
        {'data': []},
        {
          'data': {
            'id': 1,
            'display_name': 'Synthetic',
            'role': {'code': 'association_account', 'name': 'Role'},
            'is_active': true,
          },
        },
        {
          'data': {
            'id': 'synthetic-id',
            'display_name': 'Synthetic',
            'role': {'code': 'association_account', 'name': 'Role'},
            'is_active': 'true',
          },
        },
      ]) {
        expect(() => LaravelMeProfile.fromJson(json), throwsFormatException);
      }
    },
  );
}
