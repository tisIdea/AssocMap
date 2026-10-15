import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

import '../../app/configuration/app_config.dart';
import '../../data/models/public_gis_feature.dart';
import '../../data/models/geo_boundary.dart';
import '../../data/models/public_project.dart';

LatLng mapPoint(GeoPoint point) => LatLng(point.latitude, point.longitude);

Color projectColor(String? category) => switch (category) {
  'Aquaculture' => const Color(0xFF2477A4),
  'Agriculture' => const Color(0xFF267247),
  'Processing' => const Color(0xFF8E5D29),
  _ => const Color(0xFF176B66),
};

class PublicMapView extends StatefulWidget {
  const PublicMapView({
    super.key,
    required this.projects,
    required this.controller,
    required this.showBoundaries,
    required this.onSelected,
    this.selectedId,
    this.mode = GisDataMode.mock,
    this.bounds,
    this.fitPadding = 64,
    this.onReady,
  });

  final List<PublicProject> projects;
  final MapController controller;
  final bool showBoundaries;
  final ValueChanged<PublicProject> onSelected;
  final String? selectedId;

  final GisDataMode mode;
  final GisBounds? bounds;
  final double fitPadding;
  final VoidCallback? onReady;

  @override
  State<PublicMapView> createState() => _PublicMapViewState();
}

class _PublicMapViewState extends State<PublicMapView> {
  final LayerHitNotifier<Object> _polygonHits = ValueNotifier(null);
  List<PublicProject> get projects => widget.projects;
  MapController get controller => widget.controller;
  bool get showBoundaries => widget.showBoundaries;
  String? get selectedId => widget.selectedId;
  ValueChanged<PublicProject> get onSelected => widget.onSelected;
  bool get _mock => widget.mode == GisDataMode.mock;

  @override
  void dispose() {
    _polygonHits.dispose();
    super.dispose();
  }

  LatLngBounds get _initialBounds {
    final bounds = widget.bounds;
    return bounds == null
        ? LatLngBounds.fromPoints(_allPoints)
        : LatLngBounds(
            LatLng(bounds.south, bounds.west),
            LatLng(bounds.north, bounds.east),
          );
  }

  List<LatLng> get _allPoints => [
    for (final project in projects) ...[
      if (project.location != null) mapPoint(project.location!),
      for (final polygon in project.boundary?.polygons ?? <GeoPolygon>[])
        ...polygon.exterior.map(mapPoint),
    ],
  ];

  void _fit() => controller.fitCamera(
    CameraFit.bounds(
      bounds: LatLngBounds.fromPoints(_allPoints),
      padding: const EdgeInsets.all(64),
      maxZoom: 14,
    ),
  );

  void _zoom(double delta) {
    final camera = controller.camera;
    controller.move(
      camera.center,
      (camera.zoom + delta).clamp(_mock ? 8 : 0, 17).toDouble(),
    );
  }

  @override
  Widget build(BuildContext context) => ClipRRect(
    borderRadius: BorderRadius.circular(20),
    child: Stack(
      children: [
        FlutterMap(
          mapController: controller,
          options: MapOptions(
            initialCameraFit: CameraFit.bounds(
              bounds: _initialBounds,
              padding: EdgeInsets.all(widget.fitPadding),
              maxZoom: widget.fitPadding == 0 ? 17 : 14,
            ),
            minZoom: _mock ? 8 : 0,
            onMapReady: widget.onReady,
            maxZoom: 17,
            backgroundColor: const Color(0xFFE8EFEC),
            interactionOptions: const InteractionOptions(
              flags: InteractiveFlag.all & ~InteractiveFlag.rotate,
            ),
          ),
          children: [
            if (_mock)
              PolylineLayer(
                polylines: [
                  for (var i = 0; i <= 30; i++) ...[
                    Polyline(
                      points: [
                        LatLng(10.95 + i * .01, 124.8),
                        LatLng(10.95 + i * .01, 125.1),
                      ],
                      color: const Color(0xFFC7D8D1),
                      strokeWidth: 1,
                    ),
                    Polyline(
                      points: [
                        LatLng(10.95, 124.8 + i * .01),
                        LatLng(11.25, 124.8 + i * .01),
                      ],
                      color: const Color(0xFFC7D8D1),
                      strokeWidth: 1,
                    ),
                  ],
                ],
              ),
            if (showBoundaries)
              GestureDetector(
                onTap: () {
                  final hits = _polygonHits.value?.hitValues;
                  if (hits == null || hits.isEmpty) {
                    return;
                  }
                  for (final project in projects) {
                    if (project.id == hits.first) {
                      onSelected(project);
                      break;
                    }
                  }
                },
                child: PolygonLayer(
                  hitNotifier: _polygonHits,
                  polygons: [
                    for (final project in projects)
                      for (final polygon
                          in project.boundary?.polygons ?? <GeoPolygon>[])
                        Polygon(
                          hitValue: project.id,
                          points: polygon.exterior.map(mapPoint).toList(),
                          holePointsList: polygon.holes
                              .map((ring) => ring.map(mapPoint).toList())
                              .toList(),
                          color: projectColor(
                            project.category,
                          ).withValues(alpha: .2),
                          borderColor: projectColor(project.category),
                          borderStrokeWidth: 2,
                        ),
                  ],
                ),
              ),
            MarkerLayer(
              markers: [
                for (final project in projects)
                  if (project.location != null)
                    Marker(
                      point: mapPoint(project.location!),
                      width: 52,
                      height: 52,
                      child: Semantics(
                        label: 'Project marker: ${project.title}',
                        button: true,
                        child: DecoratedBox(
                          decoration: BoxDecoration(
                            shape: BoxShape.circle,
                            color: selectedId == project.id
                                ? projectColor(project.category)
                                : Colors.white,
                            border: Border.all(
                              color: projectColor(project.category),
                              width: 2,
                            ),
                            boxShadow: const [
                              BoxShadow(
                                color: Color(0x26000000),
                                blurRadius: 8,
                              ),
                            ],
                          ),
                          child: IconButton(
                            key: ValueKey('marker-${project.id}'),
                            tooltip: project.title,
                            onPressed: () => onSelected(project),
                            color: selectedId == project.id
                                ? Colors.white
                                : projectColor(project.category),
                            icon: const Icon(Icons.location_on_outlined),
                          ),
                        ),
                      ),
                    ),
              ],
            ),
          ],
        ),
        Positioned(
          top: 12,
          left: 12,
          right: 76,
          child: IgnorePointer(
            child: DecoratedBox(
              decoration: BoxDecoration(
                color: Colors.white.withValues(alpha: .95),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Padding(
                padding: const EdgeInsets.all(10),
                child: Text(
                  _mock
                      ? 'Offline demo grid · No street basemap'
                      : 'Public GIS geometry · No street basemap configured',
                  style: const TextStyle(fontSize: 12),
                ),
              ),
            ),
          ),
        ),
        Positioned(
          top: 12,
          right: 12,
          child: Material(
            color: Colors.white,
            borderRadius: BorderRadius.circular(12),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                IconButton(
                  tooltip: 'Zoom in',
                  onPressed: () => _zoom(1),
                  icon: const Icon(Icons.add),
                ),
                IconButton(
                  tooltip: 'Zoom out',
                  onPressed: () => _zoom(-1),
                  icon: const Icon(Icons.remove),
                ),
                IconButton(
                  tooltip: 'Fit visible projects',
                  onPressed: _allPoints.isEmpty ? null : _fit,
                  icon: const Icon(Icons.center_focus_strong),
                ),
              ],
            ),
          ),
        ),
        Positioned(
          bottom: 12,
          left: 12,
          right: 12,
          child: IgnorePointer(
            child: DecoratedBox(
              decoration: BoxDecoration(
                color: Colors.white.withValues(alpha: .95),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Padding(
                padding: const EdgeInsets.all(10),
                child: Text(
                  _mock
                      ? 'Synthetic markers and boundaries · Not authoritative geography'
                      : 'Published public locations · Select a marker, boundary, or list item',
                  style: const TextStyle(fontSize: 11),
                ),
              ),
            ),
          ),
        ),
      ],
    ),
  );
}
