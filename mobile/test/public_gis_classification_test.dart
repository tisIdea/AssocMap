import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:mobile/core/errors/public_gis_failure.dart';
import 'package:mobile/core/networking/public_gis_client.dart';
import 'package:mobile/data/models/geo_boundary.dart';
import 'package:mobile/data/models/gis_site_type.dart';
import 'package:mobile/data/models/public_gis_feature.dart';
import 'package:mobile/data/remote/api_public_projects_repository.dart';
import 'package:mobile/features/public_map/gis_site_symbols.dart';

import 'support/public_gis_fixtures.dart';

http.Response json(Object value) => http.Response(
  jsonEncode(value),
  200,
  headers: {'content-type': 'application/json'},
);

ApiPublicProjectsRepository repository(
  Future<http.Response> Function(http.Request) respond,
) => ApiPublicProjectsRepository(
  PublicGisClient(
    baseUri: () => Uri.parse('https://backend.example.invalid/assocmap/'),
    clientFactory: () => MockClient(respond),
  ),
);

void main() {
  for (final multipart in [false, true]) {
    test(
      'Representative point is separate from original boundary (multipart=$multipart)',
      () {
        final feature = PublicGisFeature.fromJson(
          classifiedBoundary(multipart: multipart),
        );
        final project = feature.toPresentation();
        expect(feature.point, isNull);
        expect(project.location, isNull);
        expect(project.displayPoint, const GeoPoint(11.06, 124.96));
        expect(project.publicMarker, project.displayPoint);
        expect(project.publicDisplayPoints, [project.displayPoint]);
        expect(project.boundary!.polygons, hasLength(multipart ? 2 : 1));
        expect(project.boundary!.polygons.first.holes.single, hasLength(5));
        expect(project.geometryPoints, contains(const GeoPoint(11.0, 124.9)));
        expect(project.siteTypeId, 87);
        expect(project.siteTypeCode, 'fish_cage');
        expect(project.siteTypeLabel, 'Synthetic cage');
        expect(project.filterLabel, 'Project Boundary');
        expect(project.geometryType, multipart ? 'MultiPolygon' : 'Polygon');
        expect(project.category, isNull);
      },
    );
  }

  test('Point uses original point and never infers a site from its name', () {
    final data = pointFeature();
    (data['properties'] as Map<String, dynamic>).addAll({
      'title': 'Fish Cage project',
      'display_point': {
        'type': 'Point',
        'coordinates': [125, 12],
      },
      'site_type_id': null,
      'site_type_code': null,
      'site_type_name': null,
    });
    final project = PublicGisFeature.fromJson(data).toPresentation();
    expect(project.publicMarker, const GeoPoint(11.05, 124.95));
    expect(project.siteTypeLabel, 'Unclassified');
    expect(project.filterLabel, 'Project Point');
    expect(project.geometryType, 'Point');
  });

  test(
    'Single-part MultiPolygon retains its original geometry discriminator',
    () {
      final data = boundaryFeature();
      data['geometry'] = {
        'type': 'MultiPolygon',
        'coordinates': [(data['geometry'] as Map)['coordinates']],
      };
      final project = PublicGisFeature.fromJson(data).toPresentation();
      expect(project.geometryType, 'MultiPolygon');
      expect(project.boundary!.polygons, hasLength(1));
      expect(project.boundary!.polygons.single.holes, hasLength(1));
    },
  );

  test(
    'Older responses stay readable without synthetic representative coordinates',
    () {
      final point = PublicGisFeature.fromJson(pointFeature()).toPresentation();
      final boundary = PublicGisFeature.fromJson(
        boundaryFeature(),
      ).toPresentation();
      expect(point.siteTypeLabel, 'Unclassified');
      expect(boundary.publicMarker, isNull);
      expect(boundary.publicDisplayPoints, isEmpty);
      expect(boundary.geometryPoints, isNotEmpty);
    },
  );

  test(
    'Unknown codes keep server names and partial classifications have a safe label',
    () {
      final data = pointFeature();
      (data['properties'] as Map<String, dynamic>).addAll({
        'site_type_id': 912,
        'site_type_code': 'future_site',
        'site_type_name': 'Future server label',
      });
      expect(
        PublicGisFeature.fromJson(data).toPresentation().siteTypeLabel,
        'Future server label',
      );
      (data['properties'] as Map).remove('site_type_name');
      expect(
        PublicGisFeature.fromJson(data).toPresentation().siteTypeLabel,
        'Unknown classification',
      );
      expect(gisSiteSymbol('future_site'), Icons.location_on_outlined);
    },
  );

  test(
    'Malformed optional classification values fail without guessed labels',
    () {
      for (final pair in [
        ['site_type_id', '87'],
        ['site_type_id', 0],
        ['site_type_id', -1],
        ['site_type_code', 87],
        ['site_type_code', '../bad'],
        ['site_type_name', 87],
        ['site_type_name', ' '],
        ['site_type_name', List.filled(101, 'x').join()],
      ]) {
        final data = pointFeature();
        (data['properties'] as Map)[pair[0]] = pair[1];
        expect(() => PublicGisFeature.fromJson(data), throwsFormatException);
      }
    },
  );

  test('Malformed representative coordinates fail atomically', () {
    for (final display in [
      [],
      {
        'type': 'Polygon',
        'coordinates': [outerRing()],
      },
      {
        'type': 'Point',
        'coordinates': [181, 11],
      },
      {
        'type': 'Point',
        'coordinates': [125, double.infinity],
      },
      {
        'type': 'Point',
        'coordinates': [125, 11, 3],
      },
      {
        'type': 'Point',
        'coordinates': [125],
      },
    ]) {
      final data = classifiedBoundary();
      (data['properties'] as Map)['display_point'] = display;
      expect(() => PublicGisFeature.fromJson(data), throwsFormatException);
    }
  });

  test(
    'Catalog accepts empty and unknown codes with immutable complete choices',
    () {
      expect(GisSiteType.catalogFromJson({'data': []}), isEmpty);
      final types = GisSiteType.catalogFromJson(siteCatalog());
      expect(types.map((type) => type.id), [87, 912]);
      expect(types.last.name, 'Synthetic site absent from map');
      expect(() => types.clear(), throwsUnsupportedError);
    },
  );

  test('Malformed and duplicate catalogs are rejected as a whole', () {
    for (final payload in [
      {},
      {'data': null},
      {'data': {}},
      {
        'data': [null],
      },
      {
        'data': [
          {'id': 1, 'code': 'fish_cage', 'name': ' '},
        ],
      },
      {
        'data': [
          {'id': '87', 'code': 'fish_cage', 'name': 'Cage'},
        ],
      },
      {
        'data': [
          {'id': 1, 'code': 'Bad', 'name': 'Cage'},
        ],
      },
      {
        'data': [
          ...siteCatalog()['data'],
          (siteCatalog()['data'] as List).first,
        ],
      },
      {
        'data': [
          ...siteCatalog()['data'],
          {'id': 9, 'code': 'fish_cage', 'name': 'Other'},
        ],
      },
    ]) {
      expect(
        () => GisSiteType.catalogFromJson(Map<String, dynamic>.from(payload)),
        throwsFormatException,
      );
    }
  });

  test(
    'Catalog uses public prefixed endpoint without authentication or map requests',
    () async {
      var calls = 0;
      final types = await repository((request) async {
        calls++;
        expect(request.url.path, '/assocmap/map/site-types');
        expect(request.url.queryParameters, isEmpty);
        expect(request.headers.containsKey('authorization'), isFalse);
        expect(request.headers.containsKey('cookie'), isFalse);
        expect(request.followRedirects, isFalse);
        return json(siteCatalog());
      }).getSiteTypes();
      expect(calls, 1);
      expect(types, hasLength(2));
    },
  );

  test(
    'Unavailable, malformed and incomplete catalogs return safe failures',
    () async {
      for (final response in [
        http.Response('Private diagnostics', 404),
        http.Response('private', 503),
        json({'data': 'incomplete'}),
        http.Response(
          '{"data":[',
          200,
          headers: {'content-type': 'application/json'},
        ),
      ]) {
        await expectLater(
          repository((_) async => response).getSiteTypes(),
          throwsA(isA<PublicGisFailure>()),
        );
      }
    },
  );

  for (final id in ['87', 'unclassified']) {
    test(
      'Site filter $id reaches overview and map independently of geometry',
      () async {
        final requests = <http.Request>[];
        await repository((request) async {
          requests.add(request);
          return json(
            request.url.queryParameters['mode'] == 'overview'
                ? overview()
                : mapResponse(),
          );
        }).getMap(
          query: 'public',
          locationType: 'project_boundary',
          siteTypeId: id,
        );
        expect(requests, hasLength(2));
        for (final request in requests) {
          expect(request.url.queryParameters['site_type_id'], id);
          expect(
            request.url.queryParameters['location_type'],
            'project_boundary',
          );
          expect(request.url.queryParameters['q'], 'public');
        }
      },
    );
  }

  test('Invalid site filters fail before any transport', () async {
    for (final value in [
      'fish_cage',
      '0',
      '-1',
      '',
      '1.0',
      '01',
      '1 OR 1=1',
      '1234567890123456789',
    ]) {
      await expectLater(
        repository(
          (_) async => throw StateError('No transport expected'),
        ).getMap(siteTypeId: value),
        throwsA(
          isA<PublicGisFailure>().having(
            (error) => error.code,
            'code',
            GisFailureCode.invalidQuery,
          ),
        ),
      );
    }
  });

  test(
    'All seven classifications have unique symbols and colors matching web',
    () {
      expect(gisSiteSymbol('fish_cage'), Icons.grid_on);
      expect(gisSiteSymbol('fish_pen'), Icons.fence);
      expect(gisSiteSymbol('culture_pond'), Icons.water);
      expect(gisSiteSymbol('seaweed_cultivation_site'), Icons.grass);
      expect(gisSiteSymbol('hatchery_nursery'), Icons.egg_outlined);
      expect(gisSiteSymbol('payao_installation'), Icons.anchor);
      expect(gisSiteSymbol('post_harvest_facility'), Icons.warehouse_outlined);
      expect(gisSiteSymbol('fishing_gear_site'), Icons.phishing);
      const codes = [
        'fish_cage',
        'fish_pen',
        'culture_pond',
        'seaweed_cultivation_site',
        'hatchery_nursery',
        'payao_installation',
        'post_harvest_facility',
      ];
      expect(codes.map(gisSiteSymbol).toSet(), hasLength(7));
      expect(codes.map(gisSiteColor).toSet(), hasLength(7));
      expect(gisSiteColor(null), const Color(0xFF64748B));
      for (final code in [null, 'unknown_site']) {
        expect(gisSiteSymbol(code), Icons.location_on_outlined);
        expect(gisSiteColor(code), gisUnclassifiedColor);
      }
      for (final code in [...codes, null]) {
        final color = gisSiteColor(code);
        expect(
          1.05 / (color.computeLuminance() + .05),
          greaterThanOrEqualTo(4.5),
        );
      }
    },
  );
}
