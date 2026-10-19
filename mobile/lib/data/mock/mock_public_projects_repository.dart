import 'dart:convert';

import 'package:flutter/foundation.dart' show FlutterError;
import 'package:flutter/services.dart';

import '../../core/errors/app_failure.dart';
import '../models/public_project.dart';
import '../repositories/public_projects_repository.dart';

class MockPublicProjectsRepository implements PublicProjectsRepository {
  MockPublicProjectsRepository({AssetBundle? bundle})
    : _bundle = bundle ?? rootBundle;

  final AssetBundle _bundle;

  @override
  Future<List<PublicProject>> getPublicProjects() async {
    try {
      final source = await _bundle.loadString(
        'assets/mock/public_projects.json',
      );
      final document = jsonDecode(source);
      if (document is! Map<String, dynamic> || document['projects'] is! List) {
        throw const FormatException('Invalid synthetic fixture document.');
      }
      final projects = <PublicProject>[];
      final ids = <String>{};
      for (final record in document['projects'] as List) {
        if (record is! Map<String, dynamic>) {
          throw const FormatException('Invalid synthetic fixture record.');
        }
        // Visibility is a mock scenario; Laravel owns production publication rules.
        if (record['demoVisibility'] != 'published') continue;
        final project = PublicProject.fromFixture(record);
        if (!ids.add(project.id)) {
          throw const FormatException(
            'Duplicate synthetic project identifier.',
          );
        }
        projects.add(project);
      }
      return List.unmodifiable(projects);
    } on FormatException {
      throw const AppFailure(
        FailureKind.invalidData,
        'The synthetic map data could not be read. Check the demo fixtures and retry.',
      );
    } on FlutterError {
      throw const AppFailure(
        FailureKind.invalidData,
        'The demo map asset is unavailable. Check the application assets and retry.',
      );
    }
  }
}
