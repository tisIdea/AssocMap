import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';

import '../../app/configuration/app_config.dart';
import '../../app/routing/app_routes.dart';
import '../../core/errors/app_failure.dart';
import '../../core/widgets/feedback_panel.dart';
import '../../core/widgets/assocmap_widgets.dart';
import '../../data/models/public_gis_feature.dart';
import '../../data/models/public_project.dart';
import '../../data/models/gis_site_type.dart';
import '../../data/repositories/public_projects_repository.dart';
import 'public_map_view.dart';
import 'public_project_details.dart';
import 'gis_site_symbols.dart';

class PublicMapScreen extends StatefulWidget {
  const PublicMapScreen({
    super.key,
    required this.config,
    required this.repository,
    this.tileProvider,
  });
  final AppConfig config;
  final PublicProjectsRepository repository;
  final TileProvider? tileProvider;

  @override
  State<PublicMapScreen> createState() => _PublicMapScreenState();
}

class _PublicMapScreenState extends State<PublicMapScreen> {
  final _search = TextEditingController();
  final _filterScroll = ScrollController();
  final _mapController = MapController();
  late Future<PublicGisResult> _projects;
  GisBounds? _requestedBounds;
  bool _mapReady = false;
  int _loadGeneration = 0;
  bool get _live => widget.config.gisMode == GisDataMode.live;
  String? _category;
  String? _selectedId;
  bool _showBoundaries = true;
  String? _siteTypeId;
  List<GisSiteType> _siteTypes = const [];
  bool _catalogLoading = false;
  bool _catalogFailed = false;

  @override
  void initState() {
    super.initState();
    _projects = _loadProjects();
    if (_live) _loadSiteTypes();
  }

  @override
  void dispose() {
    _search.dispose();
    _filterScroll.dispose();
    _mapController.dispose();
    super.dispose();
  }

  Future<PublicGisResult> _loadProjects() {
    final future = Future<PublicGisResult>.sync(() async {
      final repository = widget.repository;
      if (repository is ViewportPublicProjectsRepository) {
        return repository.getMap(
          bounds: _requestedBounds,
          query: _search.text,
          locationType: _category,
          siteTypeId: _siteTypeId,
        );
      }
      return PublicGisResult(projects: await repository.getPublicProjects());
    });
    // Attach an error handler before FutureBuilder's next frame.
    future.ignore();
    return future;
  }

  Future<void> _loadSiteTypes() async {
    setState(() {
      _catalogLoading = true;
      _catalogFailed = false;
    });
    try {
      final repository = widget.repository;
      if (repository is! GisSiteTypesRepository) {
        throw const FormatException('Site catalog unavailable.');
      }
      final types = await repository.getSiteTypes();
      if (!mounted) return;
      setState(() {
        _siteTypes = types;
        _catalogLoading = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _catalogFailed = true;
        _catalogLoading = false;
      });
    }
  }

  void _reload({bool resetView = false}) => setState(() {
    if (resetView) {
      _requestedBounds = null;
    }
    _mapReady = false;
    _loadGeneration++;
    _selectedId = null;
    _projects = _loadProjects();
  });

  void _clearFilters() {
    setState(() {
      _search.clear();
      _category = null;
      _siteTypeId = null;
      _selectedId = null;
    });
    if (_live) {
      _reload(resetView: true);
    }
  }

  void _searchView() {
    final view = _mapController.camera.visibleBounds;
    final west = view.west.clamp(-180, 180).toDouble();
    final east = view.east.clamp(-180, 180).toDouble();
    final south = view.south.clamp(-90, 90).toDouble();
    final north = view.north.clamp(-90, 90).toDouble();
    if (west >= east || south >= north) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'This view crosses the supported map extent. Use Show all locations to reset.',
          ),
        ),
      );
      return;
    }
    _requestedBounds = GisBounds.fromJson([west, south, east, north]);
    _reload();
  }

  void _setCategory(String? category) {
    setState(() {
      _category = category;
      _selectedId = null;
    });
    if (_live) {
      _reload(resetView: true);
    }
  }

  void _select(PublicProject project) {
    setState(() {
      _selectedId = project.id;
    });
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      useSafeArea: true,
      constraints: BoxConstraints(
        maxWidth: 640,
        maxHeight: MediaQuery.sizeOf(context).height * .85,
      ),
      builder: (_) =>
          PublicProjectDetails(project: project, mode: widget.config.gisMode),
    );
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    bottomNavigationBar: NavigationBar(
      selectedIndex: 0,
      onDestinationSelected: (value) {
        if (value == 1) {
          Navigator.of(context).pushNamed(AppRoutes.associationAccess);
        } else {
          _reload(resetView: true);
        }
      },
      destinations: const [
        NavigationDestination(
          icon: Icon(Icons.map_outlined),
          label: 'Explore map',
        ),
        NavigationDestination(
          icon: Icon(Icons.login),
          label: 'Association access',
        ),
      ],
    ),
    appBar: AppBar(
      toolbarHeight: MediaQuery.textScalerOf(context).scale(56).clamp(56, 112),
      automaticallyImplyLeading: false,
      title: const AssocMapBrand(),
      actions: [
        TextButton(
          child: const Text('Log in'),
          onPressed: () =>
              Navigator.of(context).pushNamed(AppRoutes.associationAccess),
        ),
        IconButton(
          tooltip: 'Reload public locations',
          icon: const Icon(Icons.refresh),
          onPressed: () => _reload(),
        ),
      ],
    ),
    body: SafeArea(
      child: LayoutBuilder(
        builder: (context, bodyBox) => Column(
          children: [
            if (_live)
              ConstrainedBox(
                constraints: BoxConstraints(maxHeight: bodyBox.maxHeight * .4),
                child: Scrollbar(
                  controller: _filterScroll,
                  thumbVisibility: true,
                  child: SingleChildScrollView(
                    controller: _filterScroll,
                    child: _filters(const []),
                  ),
                ),
              ),
            Expanded(
              flex: _live ? 3 : 1,
              child: FutureBuilder<PublicGisResult>(
                future: _projects,
                builder: (context, snapshot) {
                  if (snapshot.connectionState != ConnectionState.done) {
                    return Center(
                      child: SingleChildScrollView(
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const CircularProgressIndicator(),
                            const SizedBox(height: 16),
                            Text(
                              _live
                                  ? 'Loading public locations…'
                                  : 'Loading public projects…',
                            ),
                          ],
                        ),
                      ),
                    );
                  }
                  if (snapshot.hasError) {
                    final error = snapshot.error;
                    return FeedbackPanel(
                      icon: Icons.cloud_off_outlined,
                      title: 'Public map data unavailable',
                      message: error is AppFailure
                          ? error.message
                          : 'The map data could not be loaded. Retry or check the selected data source.',
                      actionLabel: 'Retry',
                      onAction: _reload,
                    );
                  }
                  final result =
                      snapshot.data ?? PublicGisResult(projects: const []);
                  if (result.projects.isEmpty && result.bounds == null) {
                    return FeedbackPanel(
                      icon: Icons.map_outlined,
                      title: _live
                          ? 'No public locations found'
                          : 'No public projects yet',
                      message: _emptyMessage(result.emptyReason),
                      actionLabel: _live ? 'Clear filters' : 'Reload',
                      onAction: _live ? _clearFilters : () => _reload(),
                    );
                  }
                  return _loaded(result);
                },
              ),
            ),
          ],
        ),
      ),
    ),
  );

  String _emptyMessage(String? reason) => switch (reason) {
    'outside_view' =>
      'No published locations intersect this view. Pan or zoom, then search this view.',
    'filtered' => 'No published locations match these filters.',
    'geometry_unavailable' => 'No readable public geometry is available.',
    'display_limit' =>
      'The server display limit was reached. Zoom in and search this view.',
    _ =>
      'There are no public locations available from the selected data source.',
  };

  Widget _loaded(PublicGisResult result) => LayoutBuilder(
    builder: (context, box) => box.maxHeight < 120
        ? SingleChildScrollView(
            child: SizedBox(height: 420, child: _loadedContent(result)),
          )
        : _loadedContent(result),
  );

  Widget _loadedContent(PublicGisResult result) {
    final generation = _loadGeneration;
    final projects = result.projects;
    final categories = projects.map((p) => p.filterLabel).toSet().toList()
      ..sort();
    final visible = _live
        ? projects
        : projects
              .where(
                (p) =>
                    p.matches(_search.text) &&
                    (_category == null || p.filterLabel == _category),
              )
              .toList();
    return Column(
      children: [
        if (!_live) _filters(categories),
        if (result.truncated)
          const Padding(
            padding: EdgeInsets.all(8),
            child: Text(
              'Partial results · The server display limit was reached. Zoom in, then search this view.',
              key: ValueKey('gis-truncated'),
            ),
          ),
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 8),
          child: MediaQuery.textScalerOf(context).scale(12) > 18
              ? Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '${visible.length} published ${visible.length == 1 ? 'location' : 'locations'}',
                      style: Theme.of(context).textTheme.labelLarge,
                    ),
                    const SizedBox(height: 6),
                    const Row(
                      children: [
                        Icon(Icons.lock_open_outlined, size: 16),
                        SizedBox(width: 6),
                        Expanded(
                          child: Text(
                            'No login required',
                            style: TextStyle(fontSize: 12),
                          ),
                        ),
                      ],
                    ),
                  ],
                )
              : Row(
                  children: [
                    Expanded(
                      child: Text(
                        '${visible.length} published ${visible.length == 1 ? 'location' : 'locations'}',
                        style: Theme.of(context).textTheme.labelLarge,
                      ),
                    ),
                    const Icon(Icons.lock_open_outlined, size: 16),
                    const SizedBox(width: 6),
                    const Flexible(
                      child: Text(
                        'No login required',
                        style: TextStyle(fontSize: 12),
                      ),
                    ),
                  ],
                ),
        ),
        Expanded(
          child: !_live && visible.isEmpty
              ? FeedbackPanel(
                  icon: Icons.search_off,
                  title: 'No matching projects',
                  message: 'Try another search or clear your filters.',
                  actionLabel: 'Clear filters',
                  onAction: _clearFilters,
                )
              : Padding(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
                  child: LayoutBuilder(
                    builder: (context, constraints) {
                      final map = PublicMapView(
                        projects: visible,
                        controller: _mapController,
                        showBoundaries: _showBoundaries,
                        selectedId: _selectedId,
                        onSelected: _select,
                        mode: widget.config.gisMode,
                        bounds: result.bounds,
                        tileProvider: widget.tileProvider,
                        fitPadding: _requestedBounds == null ? 64 : 0,
                        onReady: () =>
                            WidgetsBinding.instance.addPostFrameCallback((_) {
                              if (mounted && generation == _loadGeneration) {
                                setState(() => _mapReady = true);
                              }
                            }),
                      );
                      final list = visible.isEmpty
                          ? FeedbackPanel(
                              icon: Icons.search_off,
                              title: 'No locations in this view',
                              message: _emptyMessage(result.emptyReason),
                            )
                          : _projectList(visible);
                      if (constraints.maxWidth >= 840) {
                        return Row(
                          children: [
                            Expanded(child: map),
                            const SizedBox(width: 16),
                            SizedBox(width: 330, child: list),
                          ],
                        );
                      }
                      if (constraints.maxHeight < 400) {
                        return SingleChildScrollView(
                          child: Column(
                            children: [
                              SizedBox(
                                height:
                                    260 *
                                    MediaQuery.textScalerOf(
                                      context,
                                    ).scale(1).clamp(1, 2),
                                child: map,
                              ),
                              const SizedBox(height: 12),
                              SizedBox(height: 220, child: list),
                            ],
                          ),
                        );
                      }
                      return Column(
                        children: [
                          Expanded(child: map),
                          const SizedBox(height: 12),
                          SizedBox(
                            height: (constraints.maxHeight * .3)
                                .clamp(150, 240)
                                .toDouble(),
                            child: list,
                          ),
                        ],
                      );
                    },
                  ),
                ),
        ),
      ],
    );
  }

  Widget _filters(List<String> categories) => Column(
    children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
        child: TextField(
          key: const ValueKey('map-search'),
          controller: _search,
          maxLength: _live ? 100 : null,
          onSubmitted: _live ? (_) => _reload(resetView: true) : null,
          onChanged: (_) => setState(() {
            _selectedId = null;
          }),
          decoration: InputDecoration(
            labelText: 'Search public locations',
            hintText: 'Location, address, or description',
            counterText: '',
            prefixIcon: const Icon(Icons.search),
            suffixIcon: _live
                ? IconButton(
                    tooltip: 'Submit public GIS search',
                    icon: const Icon(Icons.search),
                    onPressed: () => _reload(resetView: true),
                  )
                : _search.text.isEmpty
                ? null
                : IconButton(
                    tooltip: 'Clear search',
                    icon: const Icon(Icons.close),
                    onPressed: () => setState(() {
                      _search.clear();
                    }),
                  ),
          ),
        ),
      ),
      Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        child: Row(
          children: [
            Expanded(
              child: SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: Row(
                  children: [
                    FilterChip(
                      label: Text(
                        _live ? 'All geometry types' : 'All projects',
                      ),
                      selected: _category == null,
                      onSelected: (_) => _setCategory(null),
                    ),
                    for (final category
                        in _live
                            ? ['project_point', 'project_boundary']
                            : categories) ...[
                      const SizedBox(width: 8),
                      FilterChip(
                        label: Text(switch (category) {
                          'project_point' => 'Project Point',
                          'project_boundary' => 'Project Boundary',
                          _ => category,
                        }),
                        selected: _category == category,
                        onSelected: (value) =>
                            _setCategory(value ? category : null),
                      ),
                    ],
                  ],
                ),
              ),
            ),
            const SizedBox(width: 8),
            if (!_live)
              FilterChip(
                label: const Text('Boundaries'),
                avatar: const Icon(Icons.layers_outlined, size: 18),
                selected: _showBoundaries,
                onSelected: (value) => setState(() {
                  _showBoundaries = value;
                }),
              ),
          ],
        ),
      ),
      if (_live) _siteFilters(),
      if (_live)
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: Wrap(
            spacing: 8,
            children: [
              TextButton.icon(
                key: const ValueKey('search-map-view'),
                onPressed: _mapReady ? _searchView : null,
                icon: const Icon(Icons.search),
                label: const Text('Search this view'),
              ),
              TextButton(
                onPressed: () => _reload(resetView: true),
                child: const Text('Show all locations'),
              ),
              if (_search.text.isNotEmpty ||
                  _category != null ||
                  _siteTypeId != null)
                TextButton(
                  onPressed: _clearFilters,
                  child: const Text('Clear filters'),
                ),
            ],
          ),
        ),
    ],
  );

  Widget _siteFilters() => Padding(
    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        DropdownButtonFormField<String>(
          key: ValueKey('site-type-filter-${_siteTypeId ?? 'all'}'),
          initialValue: _siteTypeId ?? 'all',
          isExpanded: true,
          decoration: const InputDecoration(labelText: 'Site classification'),
          items: [
            const DropdownMenuItem(
              value: 'all',
              child: Text('All site classifications'),
            ),
            const DropdownMenuItem(
              value: 'unclassified',
              child: Text('Unclassified'),
            ),
            for (final type in _siteTypes)
              DropdownMenuItem(
                value: type.id.toString(),
                child: Text(
                  type.name,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
          ],
          onChanged: (value) {
            setState(() => _siteTypeId = value == 'all' ? null : value);
            _reload(resetView: true);
          },
        ),
        if (_catalogLoading) const Text('Loading site classifications…'),
        if (_catalogFailed) ...[
          const Text(
            'Site classifications unavailable. Location search and Unclassified remain available.',
          ),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton(
              onPressed: _loadSiteTypes,
              child: const Text('Retry site classifications'),
            ),
          ),
        ] else if (!_catalogLoading && _siteTypes.isEmpty)
          const Text(
            'No site classifications configured. Unclassified remains available.',
          ),
        ExpansionTile(
          tilePadding: EdgeInsets.zero,
          title: const Text('Map legend'),
          subtitle: const Text(
            'Circle: Project Point · Square: Project Boundary',
          ),
          children: [
            const ListTile(
              dense: true,
              leading: Icon(Icons.circle_outlined, color: gisPointColor),
              title: Text('Project Point'),
            ),
            const ListTile(
              dense: true,
              leading: Icon(Icons.crop_square, color: gisBoundaryColor),
              title: Text('Project Boundary representative marker'),
            ),
            const Text(
              'Project Boundaries use a representative marker within the actual project site, not an administrative area. Green outline: selected location.',
            ),
            for (final type in _siteTypes)
              ListTile(
                dense: true,
                leading: Icon(
                  gisSiteSymbol(type.code),
                  color: gisSiteColor(type.code),
                ),
                title: Text(type.name),
              ),
            const ListTile(
              dense: true,
              leading: Icon(
                Icons.location_on_outlined,
                color: gisUnclassifiedColor,
              ),
              title: Text('Unclassified / unknown symbol'),
            ),
          ],
        ),
      ],
    ),
  );

  Widget _projectList(List<PublicProject> projects) => ListView.separated(
    key: const ValueKey('public-project-list'),
    itemCount: projects.length,
    separatorBuilder: (_, _) => const SizedBox(height: 6),
    itemBuilder: (context, index) {
      final project = projects[index];
      final color = _live
          ? gisSiteColor(project.siteTypeCode)
          : projectColor(project.category);
      return Card(
        margin: EdgeInsets.zero,
        color: Colors.white,
        child: ListTile(
          key: ValueKey('project-${project.id}'),
          contentPadding: const EdgeInsets.symmetric(
            horizontal: 16,
            vertical: 6,
          ),
          leading: CircleAvatar(
            backgroundColor: color.withValues(alpha: .12),
            child: Icon(
              _live
                  ? gisSiteSymbol(project.siteTypeCode)
                  : project.location == null
                  ? Icons.crop_free
                  : Icons.location_on_outlined,
              color: color,
            ),
          ),
          title: Text(
            project.title,
            style: const TextStyle(fontWeight: FontWeight.w600),
          ),
          subtitle: Text(
            [
              if (project.associationName != null) project.associationName!,
              project.filterLabel,
              if (_live) 'Site classification: ${project.siteTypeLabel}',
              if (_live && project.isBoundary && project.displayPoint == null)
                'Representative marker unavailable',
              if (project.areaLabel != null) project.areaLabel!,
            ].join('\n'),
          ),
          trailing: const Icon(Icons.chevron_right),
          selected: _selectedId == project.id,
          selectedTileColor: gisSelectionColor.withValues(alpha: .08),
          isThreeLine: true,
          onTap: () {
            final points =
                (_live ? project.publicDisplayPoints : project.geometryPoints)
                    .map(mapPoint)
                    .toList();
            if (points.length == 1) {
              _mapController.move(points.single, 13);
            } else if (points.isNotEmpty) {
              _mapController.fitCamera(
                CameraFit.bounds(
                  bounds: LatLngBounds.fromPoints(points),
                  padding: const EdgeInsets.all(48),
                  maxZoom: 14,
                ),
              );
            }
            _select(project);
          },
        ),
      );
    },
  );
}
