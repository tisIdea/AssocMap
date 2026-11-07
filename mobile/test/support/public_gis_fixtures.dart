const pointId = '11111111-1111-4111-8111-111111111111';
const boundaryId = '22222222-2222-4222-8222-222222222222';

List<List<num>> outerRing() => [
  [124.9, 11.0],
  [125.0, 11.0],
  [125.0, 11.1],
  [124.9, 11.1],
  [124.9, 11.0],
];
List<List<num>> holeRing() => [
  [124.92, 11.02],
  [124.94, 11.02],
  [124.94, 11.04],
  [124.92, 11.04],
  [124.92, 11.02],
];

Map<String, dynamic> pointFeature() => {
  'type': 'Feature',
  'id': pointId,
  'geometry': {
    'type': 'Point',
    'coordinates': [124.95, 11.05],
  },
  'properties': <String, dynamic>{
    'location_type': 'project_point',
    'title': 'Synthetic public point',
    'address': 'Fictional public address',
    'description': null,
  },
};

Map<String, dynamic> boundaryFeature({bool multipart = false}) => {
  'type': 'Feature',
  'id': boundaryId,
  'geometry': multipart
      ? {
          'type': 'MultiPolygon',
          'coordinates': [
            [outerRing(), holeRing()],
            [
              [
                [125.1, 11.0],
                [125.2, 11.0],
                [125.2, 11.1],
                [125.1, 11.0],
              ],
            ],
          ],
        }
      : {
          'type': 'Polygon',
          'coordinates': [outerRing(), holeRing()],
        },
  'properties': <String, dynamic>{
    'location_type': 'project_boundary',
    'title': 'Synthetic public boundary',
    'address': null,
    'description': 'Fictional published description',
  },
};

Map<String, dynamic> overview({bool empty = false}) => {
  'type': 'FeatureCollection',
  'features': <Object>[],
  'meta': {
    'bounds': empty ? null : [124.9, 11.0, 125.2, 11.1],
    'empty_reason': empty ? 'no_records' : null,
  },
};

Map<String, dynamic> classifiedBoundary({bool multipart = false}) {
  final feature = boundaryFeature(multipart: multipart);
  (feature['properties'] as Map<String, dynamic>).addAll({
    'site_type_id': 87,
    'site_type_code': 'fish_cage',
    'site_type_name': 'Synthetic cage',
    'display_point': {
      'type': 'Point',
      'coordinates': [124.96, 11.06],
    },
  });
  return feature;
}

Map<String, dynamic> siteCatalog() => {
  'data': [
    {'id': 87, 'code': 'fish_cage', 'name': 'Synthetic cage'},
    {
      'id': 912,
      'code': 'future_site',
      'name': 'Synthetic site absent from map',
    },
  ],
};

Map<String, dynamic> mapResponse({
  List<Map<String, dynamic>>? features,
  bool truncated = false,
  String? emptyReason,
}) => {
  'type': 'FeatureCollection',
  'features': features ?? [pointFeature()],
  'meta': {'limit': 100, 'truncated': truncated, 'empty_reason': ?emptyReason},
};
