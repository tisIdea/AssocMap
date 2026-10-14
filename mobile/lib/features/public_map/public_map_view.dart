import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

import '../../data/models/geo_boundary.dart';
import '../../data/models/public_project.dart';

LatLng mapPoint(GeoPoint point) => LatLng(point.latitude, point.longitude);

Color projectColor(String category) => switch (category) {
  'Aquaculture' => const Color(0xFF2477A4),
  'Agriculture' => const Color(0xFF267247),
  'Processing' => const Color(0xFF8E5D29),
  _ => const Color(0xFF176B66),
};

class PublicMapView extends StatelessWidget {
  const PublicMapView({
    super.key,
    required this.projects,
    required this.controller,
    required this.showBoundaries,
    required this.onSelected,
    this.selectedId,
  });

  final List<PublicProject> projects;
  final MapController controller;
  final bool showBoundaries;
  final ValueChanged<PublicProject> onSelected;
  final String? selectedId;

  List<LatLng> get _allPoints => [
    for (final project in projects) ...[
      mapPoint(project.location),
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
      (camera.zoom + delta).clamp(8, 17).toDouble(),
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
              bounds: LatLngBounds.fromPoints(_allPoints),
              padding: const EdgeInsets.all(64),
              maxZoom: 14,
            ),
            minZoom: 8,
            maxZoom: 17,
            backgroundColor: const Color(0xFFE8EFEC),
            interactionOptions: const InteractionOptions(
              flags: InteractiveFlag.all & ~InteractiveFlag.rotate,
            ),
          ),
          children: [
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
              PolygonLayer(
                polygons: [
                  for (final project in projects)
                    for (final polygon
                        in project.boundary?.polygons ?? <GeoPolygon>[])
                      Polygon(
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
            MarkerLayer(
              markers: [
                for (final project in projects)
                  Marker(
                    point: mapPoint(project.location),
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
                            BoxShadow(color: Color(0x26000000), blurRadius: 8),
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
              child: const Padding(
                padding: EdgeInsets.all(10),
                child: Text(
                  'Offline demo grid · No street basemap',
                  style: TextStyle(fontSize: 12),
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
                  onPressed: _fit,
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
              child: const Padding(
                padding: EdgeInsets.all(10),
                child: Text(
                  'Synthetic markers and boundaries · Not authoritative geography',
                  style: TextStyle(fontSize: 11),
                ),
              ),
            ),
          ),
        ),
      ],
    ),
  );
}
