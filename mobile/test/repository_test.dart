import 'dart:convert';

import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/app/configuration/app_config.dart';
import 'package:mobile/core/errors/app_failure.dart';
import 'package:mobile/data/mock/mock_public_projects_repository.dart';
import 'package:mobile/data/remote/api_public_projects_repository.dart';
import 'package:mobile/data/repositories/repository_factory.dart';

class _Bundle extends CachingAssetBundle {
  _Bundle(this.source);
  final String source;

  @override
  Future<ByteData> load(String key) async =>
      ByteData.sublistView(Uint8List.fromList(utf8.encode(source)));
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test(
    'Real demo fixture returns only public samples and does not mutate them',
    () async {
      final projects = await MockPublicProjectsRepository().getPublicProjects();
      expect(projects, hasLength(3));
      expect(
        projects.map((project) => project.id),
        isNot(contains('demo-hidden-draft')),
      );
      expect(
        projects.map((project) => project.id),
        isNot(contains('demo-hidden-archived')),
      );
      expect(() => projects.clear(), throwsUnsupportedError);
      expect(projects.first.matches('  HARBOR  '), isTrue);
    },
  );

  test(
    'An empty fixture produces an empty result without synthetic fallback',
    () async {
      final repository = MockPublicProjectsRepository(
        bundle: _Bundle('{"projects":[]}'),
      );
      expect(await repository.getPublicProjects(), isEmpty);
    },
  );

  test('Malformed public fixture produces a safe typed error', () async {
    for (final source in [
      'not-json',
      '{"projects":{}}',
      '{"projects":[{"demoVisibility":"published"}]}',
    ]) {
      final repository = MockPublicProjectsRepository(bundle: _Bundle(source));
      await expectLater(
        repository.getPublicProjects(),
        throwsA(
          isA<AppFailure>().having(
            (error) => error.kind,
            'kind',
            FailureKind.invalidData,
          ),
        ),
      );
    }
  });

  test('Duplicate public identifiers are rejected', () async {
    final record = {
      'id': 'same',
      'demoVisibility': 'published',
      'title': 'Synthetic',
      'associationName': 'Demo',
      'category': 'Sample',
      'areaLabel': 'Demo zone',
      'summary': 'Fictional',
      'location': [120, 10],
    };
    final repository = MockPublicProjectsRepository(
      bundle: _Bundle(
        jsonEncode({
          'projects': [record, record],
        }),
      ),
    );
    await expectLater(
      repository.getPublicProjects(),
      throwsA(isA<AppFailure>()),
    );
  });

  test(
    'Central configuration selects repositories and rejects unknown modes',
    () async {
      expect(AppConfig.parseMode('mock'), DataMode.mock);
      expect(AppConfig.parseMode('api'), DataMode.api);
      expect(AppConfig.parseMode('production'), DataMode.invalid);
      expect(
        RepositoryFactory.publicProjects(const AppConfig()),
        isA<MockPublicProjectsRepository>(),
      );
      final api = RepositoryFactory.publicProjects(
        const AppConfig(mode: DataMode.api),
      );
      expect(api, isA<ApiPublicProjectsRepository>());
      await expectLater(
        api.getPublicProjects(),
        throwsA(
          isA<AppFailure>().having(
            (error) => error.kind,
            'kind',
            FailureKind.integrationUnavailable,
          ),
        ),
      );
      await expectLater(
        RepositoryFactory.publicProjects(
          const AppConfig(mode: DataMode.invalid),
        ).getPublicProjects(),
        throwsA(
          isA<AppFailure>().having(
            (error) => error.kind,
            'kind',
            FailureKind.invalidConfiguration,
          ),
        ),
      );
    },
  );
}
