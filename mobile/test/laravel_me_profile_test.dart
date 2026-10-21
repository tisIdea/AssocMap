import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/data/models/auth_session.dart';
import 'package:mobile/data/models/laravel_me_profile.dart';

void main() {
  Map<String, dynamic> response({String role = 'association_account'}) => {
    'data': <String, dynamic>{
      'id': '00000000-0000-0000-0000-000000000001',
      'display_name': 'Synthetic profile',
      'role': {'code': role, 'name': 'Synthetic role name'},
      'is_active': true,
    },
  };

  test('Legacy profile-only response never grants mobile access', () {
    final profile = LaravelMeProfile.fromJson(response());
    expect(profile.displayName, 'Synthetic profile');
    expect(profile.role, AccountRole.associationAccount);
    expect(profile.isActive, isTrue);
    expect(profile.canAccessMobile, isFalse);
  });

  test('Unknown role codes remain unknown', () {
    expect(
      LaravelMeProfile.fromJson(response(role: 'unapproved_role')).role,
      AccountRole.unknown,
    );
  });

  Map<String, dynamic> eligibleResponse() {
    final json = response();
    (json['data'] as Map<String, dynamic>)['mobile'] = {
      'eligible': true,
      'reason': null,
      'association': {
        'id': '00000000-0000-0000-0000-000000000002',
        'name': 'Synthetic linked association',
      },
    };
    return json;
  }

  test(
    'Current eligible response preserves the server-provided association',
    () {
      final profile = LaravelMeProfile.fromJson(eligibleResponse());
      expect(profile.canAccessMobile, isTrue);
      expect(
        profile.mobile?.association?.id,
        '00000000-0000-0000-0000-000000000002',
      );
      expect(profile.mobile?.association?.name, 'Synthetic linked association');
    },
  );

  test(
    'HTTP-success payloads can deny mobile access without exposing association details',
    () {
      for (final reason in [
        'role_not_supported',
        'association_link_unavailable',
        'association_unavailable',
        'future_unrecognized_reason',
      ]) {
        final json = response();
        (json['data'] as Map<String, dynamic>)['mobile'] = {
          'eligible': false,
          'reason': reason,
          'association': null,
        };
        final profile = LaravelMeProfile.fromJson(json);
        expect(profile.canAccessMobile, isFalse);
        expect(profile.mobile?.association, isNull);
        expect(profile.mobile?.reason, reason);
      }
    },
  );

  test(
    'Staff roles, unknown roles, or an inactive profile never grant mobile access',
    () {
      for (final role in ['system_admin', 'field_officer', 'unapproved_role']) {
        final json = eligibleResponse();
        ((json['data'] as Map<String, dynamic>)['role']
                as Map<String, dynamic>)['code'] =
            role;
        expect(LaravelMeProfile.fromJson(json).canAccessMobile, isFalse);
      }
      final json = eligibleResponse();
      (json['data'] as Map<String, dynamic>)['is_active'] = false;
      expect(LaravelMeProfile.fromJson(json).canAccessMobile, isFalse);
    },
  );

  test(
    'Null mobile context never upgrades an identity-only response to eligible',
    () {
      final json = response();
      (json['data'] as Map<String, dynamic>)['mobile'] = null;
      expect(LaravelMeProfile.fromJson(json).canAccessMobile, isFalse);
    },
  );

  test('Malformed or contradictory eligibility responses are rejected', () {
    final wrongId = eligibleResponse();
    (wrongId['data'] as Map<String, dynamic>)['id'] = 'invalid-profile-id';
    expect(() => LaravelMeProfile.fromJson(wrongId), throwsFormatException);
    final errorEnvelope = eligibleResponse()
      ..['error'] = {'code': 'account_unavailable'};
    expect(
      () => LaravelMeProfile.fromJson(errorEnvelope),
      throwsFormatException,
    );
    for (final context in <Object>[
      'invalid',
      {'eligible': 'true', 'reason': null, 'association': null},
      {'eligible': true, 'reason': null, 'association': null},
      {
        'eligible': true,
        'association': {
          'id': '00000000-0000-0000-0000-000000000002',
          'name': 'Synthetic',
        },
      },
      {'eligible': false, 'reason': null, 'association': null},
      {
        'eligible': false,
        'reason': 'role_not_supported',
        'association': {
          'id': '00000000-0000-0000-0000-000000000002',
          'name': 'Withheld synthetic details',
        },
      },
      {
        'eligible': true,
        'reason': 'role_not_supported',
        'association': {
          'id': '00000000-0000-0000-0000-000000000002',
          'name': 'Synthetic',
        },
      },
      {
        'eligible': true,
        'reason': null,
        'association': {'id': 'invalid', 'name': 'Synthetic'},
      },
      {
        'eligible': true,
        'reason': null,
        'association': {
          'id': '00000000-0000-0000-0000-000000000002',
          'name': '',
        },
      },
    ]) {
      final json = response();
      (json['data'] as Map<String, dynamic>)['mobile'] = context;
      expect(() => LaravelMeProfile.fromJson(json), throwsFormatException);
    }
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
