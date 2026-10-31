import 'dart:async';
import 'dart:convert';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:mobile/core/media/member_photo_picker.dart';
import 'package:mobile/core/networking/laravel_api_client.dart';
import 'package:mobile/data/models/member_application.dart';
import 'package:mobile/data/remote/api_member_applications_repository.dart';
import 'package:mobile/data/repositories/member_applications_repository.dart';
import 'mobile_m1_auth_test.dart' show config, json;
import 'support/member_application_fixtures.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  ApiMemberApplicationsRepository repository({
    Future<Map<String, dynamic>> Function(Uri)? read,
    Future<Map<String, dynamic>> Function(
      Map<String, String>, {
      MemberApplicationPhoto? photo,
    })?
    write,
  }) => ApiMemberApplicationsRepository(
    config: config,
    associationId: () => applicationAssociationId,
    read: read ?? (_) async => {'data': applicationOptions()},
    write:
        write ??
        (fields, {photo}) async => {
          'data': {
            'association_id': applicationAssociationId,
            'application': applicationSummary(),
          },
        },
  );

  test(
    'Options retain server IDs, optional classifications and occupied positions',
    () {
      final options = MemberApplicationOptions.fromJson(applicationOptions());
      expect(options.sexes.single.id, 17);
      expect(options.positions.last.occupied, isTrue);
      expect(options.positions[1].code, 'OTHER');
      expect(options.classifications.single.id, 43);
      expect(() => options.positions.clear(), throwsUnsupportedError);
    },
  );
  test('Missing, duplicate or malformed options are rejected', () {
    for (final data in [
      <String, dynamic>{},
      applicationOptions()..['positions'] = 'bad',
      applicationOptions()
        ..['sex_categories'] = [
          {'id': 0, 'name': 'Bad'},
        ],
      applicationOptions()
        ..['positions'] = [
          {'id': 21, 'name': 'Member', 'code': 'MEMBER', 'occupied': 'false'},
        ],
      applicationOptions()
        ..['classifications'] = [
          {'id': 1, 'name': 'A'},
          {'id': 1, 'name': 'B'},
        ],
    ]) {
      expect(
        () => MemberApplicationOptions.fromJson(data),
        throwsFormatException,
      );
    }
  });
  for (final status in ['pending', 'approved', 'rejected']) {
    test(
      'Application $status preserves review status without creating a member DTO',
      () {
        final application = MemberApplicationSummary.fromJson(
          applicationSummary(status: status),
        );
        expect(application.status, status);
        expect(application.reviewedAt == null, status == 'pending');
        expect(
          application.rejectionReason,
          status == 'rejected' ? 'Synthetic review reason' : null,
        );
      },
    );
  }
  test(
    'Malformed and duplicate paginated application records fail together',
    () {
      for (final data in [
        applicationPage()..['page'] = 0,
        applicationPage()..['total'] = -1,
        applicationPage()
          ..['applications'] = [applicationSummary()..['status'] = 'official'],
        applicationPage()
          ..['applications'] = [applicationSummary(), applicationSummary()],
        applicationPage()
          ..['applications'] = [applicationSummary()..['has_photo'] = 'true'],
      ]) {
        expect(
          () => MemberApplicationPage.fromJson(data),
          throwsFormatException,
        );
      }
    },
  );
  test(
    'Repository uses current public origin, page/status and server scope only',
    () async {
      final uris = <Uri>[];
      final repo = repository(
        read: (uri) async {
          uris.add(uri);
          return {
            'data': uri.path.endsWith('/options')
                ? applicationOptions()
                : applicationPage(),
          };
        },
      );
      await repo.options();
      await repo.list(page: 2, status: 'rejected');
      expect(
        uris.first.path,
        '/api/v1/association/member-applications/options',
      );
      expect(uris.last.queryParameters, {'page': '2', 'status': 'rejected'});
      expect(uris.last.queryParameters.containsKey('association_id'), isFalse);
    },
  );
  test(
    'Wrong association responses are denied and malformed responses are unavailable',
    () async {
      await expectLater(
        repository(
          read: (_) async => {
            'data': applicationOptions()
              ..['association'] = {'id': applicationId, 'name': 'Other'},
          },
        ).options(),
        throwsA(
          isA<MemberApplicationFailure>().having(
            (e) => e.kind,
            'kind',
            MemberApplicationFailureKind.denied,
          ),
        ),
      );
      await expectLater(
        repository(read: (_) async => {'data': 'bad'}).list(),
        throwsA(isA<MemberApplicationFailure>()),
      );
    },
  );
  test('Invalid filters fail before a request', () async {
    final repo = repository(
      read: (_) => throw StateError('No request expected'),
    );
    await expectLater(
      repo.list(page: 0),
      throwsA(isA<MemberApplicationFailure>()),
    );
    await expectLater(
      repo.list(status: 'secret'),
      throwsA(isA<MemberApplicationFailure>()),
    );
  });
  test(
    'Concurrent submission is blocked, and a receipt must be pending',
    () async {
      final pending = Completer<Map<String, dynamic>>();
      var calls = 0;
      final repo = repository(
        write: (fields, {photo}) {
          calls++;
          return pending.future;
        },
      );
      final first = repo.submit({'first_name': 'Synthetic'});
      await expectLater(
        repo.submit({}),
        throwsA(isA<MemberApplicationFailure>()),
      );
      expect(calls, 1);
      pending.complete({
        'data': {
          'association_id': applicationAssociationId,
          'application': applicationSummary(),
        },
      });
      expect((await first).status, 'pending');
      await expectLater(
        repository(
          write: (fields, {photo}) async => {
            'data': {
              'association_id': applicationAssociationId,
              'application': applicationSummary(status: 'approved'),
            },
          },
        ).submit({}),
        throwsA(isA<MemberApplicationFailure>()),
      );
    },
  );
  test(
    'Write errors are not retried and uncertain outcomes have no fabricated receipt',
    () async {
      for (final kind in [
        ApiFailureKind.offline,
        ApiFailureKind.unavailable,
        ApiFailureKind.invalidResponse,
      ]) {
        var calls = 0;
        await expectLater(
          repository(
            write: (fields, {photo}) async {
              calls++;
              throw ApiFailure(kind);
            },
          ).submit({}),
          throwsA(
            isA<MemberApplicationFailure>().having(
              (e) => e.kind,
              'kind',
              MemberApplicationFailureKind.uncertain,
            ),
          ),
        );
        expect(calls, 1);
      }
    },
  );
  test(
    'Multipart transport uses the fixed endpoint, bearer header and no redirects',
    () async {
      final client = LaravelApiClient(
        config: config,
        client: MockClient((request) async {
          expect(request.method, 'POST');
          expect(request.url.path, '/api/v1/association/member-applications');
          expect(request.headers['authorization'], 'Bearer synthetic-token');
          expect(request.followRedirects, isFalse);
          final body = String.fromCharCodes(request.bodyBytes);
          expect(body, contains('name="classification_ids[0]"'));
          expect(body, contains('filename="applicant.png"'));
          expect(body, isNot(contains('name="association_id"')));
          return json({
            'data': {
              'association_id': applicationAssociationId,
              'application': applicationSummary(),
            },
          }, 201);
        }),
      );
      await client.submitMemberApplication(
        'synthetic-token',
        {'first_name': 'Synthetic', 'classification_ids[0]': '43'},
        photo: Uint8List.fromList([137, 80, 78, 71]),
        photoName: 'applicant.png',
      );
      client.close();
    },
  );
  test('Only safe 422 field errors reach the form', () async {
    final client = LaravelApiClient(
      config: config,
      client: MockClient(
        (request) async => json({
          'errors': {
            'first_name': ['Required'],
            'classification_ids.0': ['Invalid choice'],
            'private_diagnostic': ['Withheld'],
          },
        }, 422),
      ),
    );
    await expectLater(
      client.submitMemberApplication('synthetic', {}),
      throwsA(
        isA<ApiFailure>()
            .having((e) => e.kind, 'kind', ApiFailureKind.validation)
            .having(
              (e) => e.errors.keys,
              'fields',
              unorderedEquals(['first_name', 'classification_ids.0']),
            ),
      ),
    );
    client.close();
  });
  for (final status in [302, 401, 403, 429, 503]) {
    test('HTTP $status is never followed or retried for a write', () async {
      var calls = 0;
      final client = LaravelApiClient(
        config: config,
        client: MockClient((request) async {
          calls++;
          return http.Response('Private gateway text', status);
        }),
      );
      await expectLater(
        client.submitMemberApplication('synthetic', {}),
        throwsA(isA<ApiFailure>()),
      );
      expect(calls, 1);
      client.close();
    });
  }
  test(
    'Malformed success and incomplete response JSON do not produce a receipt',
    () async {
      for (final body in ['{"data":', '<html>Access login</html>', '[]']) {
        final client = LaravelApiClient(
          config: config,
          client: MockClient(
            (_) async => http.Response(
              body,
              201,
              headers: {'content-type': 'application/json'},
            ),
          ),
        );
        await expectLater(
          client.submitMemberApplication('synthetic', {}),
          throwsA(isA<ApiFailure>()),
        );
        client.close();
      }
    },
  );
  test(
    'Photo validation accepts only bounded decodable JPEG/PNG bytes',
    () async {
      final png = base64Decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j6aQAAAAASUVORK5CYII=',
      );
      final photo = await validateMemberApplicationPhoto(png);
      expect(photo.name, 'applicant.png');
      expect(() => photo.bytes[0] = 0, throwsUnsupportedError);
      for (final bytes in [
        Uint8List(0),
        Uint8List(2 * 1024 * 1024 + 1),
        Uint8List.fromList([137, 80, 78, 71, 0, 0, 0, 0]),
      ]) {
        await expectLater(
          validateMemberApplicationPhoto(bytes),
          throwsA(anything),
        );
      }
    },
  );
  test(
    'Android picker cancellation is safe and selected bytes are validated',
    () async {
      const channel = MethodChannel('assocmap/member_photo');
      final messenger =
          TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger;
      messenger.setMockMethodCallHandler(channel, (_) async => null);
      expect(await pickMemberApplicationPhoto(), isNull);
      messenger.setMockMethodCallHandler(
        channel,
        (_) async => Uint8List.fromList([0, 1]),
      );
      await expectLater(pickMemberApplicationPhoto(), throwsFormatException);
      messenger.setMockMethodCallHandler(channel, null);
    },
  );
}
