import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

import '../../app/configuration/app_config.dart';
import '../../app/configuration/satellite_basemap.dart';
import '../../core/networking/bounded_http_client.dart';
import '../../data/models/public_gis_feature.dart';
import '../../data/models/geo_boundary.dart';
import '../../data/models/public_project.dart';
import 'gis_site_symbols.dart';

LatLng mapPoint(GeoPoint point) => LatLng(point.latitude, point.longitude);

Color projectColor(String? category) => switch (category) {
  'Aquaculture' => const Color(0xFF2477A4),
  'Agriculture' => const Color(0xFF267247),
  'Processing' => const Color(0xFF8E5D29),
  _ => const Color(0xFF0B4C8C),
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
    this.tileProvider,
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
  final TileProvider? tileProvider;

  @override
  State<PublicMapView> createState() => _PublicMapViewState();
}

class _PublicMapViewState extends State<PublicMapView> {
  final _tileClient = BoundedHttpClient(
    timeout: const Duration(seconds: 15),
    maxBytes: 1024 * 1024,
  );
  late final TileProvider _tiles =
      widget.tileProvider ??
      NetworkTileProvider(
        httpClient: _tileClient,
        attemptDecodeOfHttpErrorResponses: false,
      );
  bool _imageryUnavailable = false;
  final LayerHitNotifier<Object> _polygonHits = ValueNotifier(null);
  List<PublicProject> get projects => widget.projects;
  MapController get controller => widget.controller;
  bool get showBoundaries => widget.showBoundaries;
  String? get selectedId => widget.selectedId;
  ValueChanged<PublicProject> get onSelected => widget.onSelected;
  bool get _mock => widget.mode == GisDataMode.mock;

  Color _markerColor(PublicProject project) => selectedId == project.id
      ? gisSelectionColor
      : _mock
      ? projectColor(project.category)
      : gisSiteColor(project.siteTypeCode);

  @override
  void dispose() {
    _polygonHits.dispose();
    _tileClient.close();
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
    for (final project in projects)
      ...(_mock ? project.geometryPoints : project.publicDisplayPoints).map(
        mapPoint,
      ),
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
            initialCenter: _initialBounds.center,
            initialZoom: 7,
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
            TileLayer(
              urlTemplate: SatelliteBasemap.urlTemplate,
              userAgentPackageName: 'com.assocmap.mobile',
              tileProvider: _tiles,
              errorTileCallback: (_, _, _) {
                if (_imageryUnavailable) return;
                WidgetsBinding.instance.addPostFrameCallback((_) {
                  if (mounted && !_imageryUnavailable) {
                    setState(() => _imageryUnavailable = true);
                  }
                });
              },
            ),
            if (_mock && showBoundaries)
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
                  if ((_mock ? project.location : project.publicMarker) != null)
                    Marker(
                      point: mapPoint(
                        (_mock ? project.location : project.publicMarker)!,
                      ),
                      width: 52,
                      height: 52,
                      child: Semantics(
                        label: _mock
                            ? 'Project marker: ${project.title}'
                            : '${project.filterLabel}${project.isBoundary ? ' representative marker' : ' marker'}: ${project.title}. Site classification: ${project.siteTypeLabel}',
                        button: true,
                        selected: selectedId == project.id,
                        child: DecoratedBox(
                          decoration: BoxDecoration(
                            shape: !_mock && project.isBoundary
                                ? BoxShape.rectangle
                                : BoxShape.circle,
                            borderRadius: !_mock && project.isBoundary
                                ? BorderRadius.circular(8)
                                : null,
                            color: Colors.white,
                            border: Border.all(
                              color: _markerColor(project),
                              width: selectedId == project.id ? 4 : 2,
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
                            tooltip: _mock
                                ? project.title
                                : '${project.title} · ${project.filterLabel} · ${project.siteTypeLabel}',
                            onPressed: () => onSelected(project),
                            color: _mock
                                ? _markerColor(project)
                                : gisSiteColor(project.siteTypeCode),
                            icon: Icon(
                              _mock
                                  ? Icons.location_on_outlined
                                  : gisSiteSymbol(project.siteTypeCode),
                            ),
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
                  _imageryUnavailable
                      ? 'Satellite imagery unavailable. Check your connection.'
                      : 'Satellite map',
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
                  tooltip: _mock
                      ? 'Fit visible projects'
                      : 'Fit visible locations',
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
                  SatelliteBasemap.attribution,
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
