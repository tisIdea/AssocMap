import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';

import '../../app/configuration/app_config.dart';
import '../../app/routing/app_routes.dart';
import '../../core/errors/app_failure.dart';
import '../../core/widgets/data_mode_banner.dart';
import '../../core/widgets/feedback_panel.dart';
import '../../data/models/public_project.dart';
import '../../data/repositories/public_projects_repository.dart';
import 'public_map_view.dart';
import 'public_project_details.dart';

class PublicMapScreen extends StatefulWidget {
  const PublicMapScreen({
    super.key,
    required this.config,
    required this.repository,
  });
  final AppConfig config;
  final PublicProjectsRepository repository;

  @override
  State<PublicMapScreen> createState() => _PublicMapScreenState();
}

class _PublicMapScreenState extends State<PublicMapScreen> {
  final _search = TextEditingController();
  final _mapController = MapController();
  late Future<List<PublicProject>> _projects;
  String? _category;
  String? _selectedId;
  bool _showBoundaries = true;

  @override
  void initState() {
    super.initState();
    _projects = _loadProjects();
  }

  @override
  void dispose() {
    _search.dispose();
    _mapController.dispose();
    super.dispose();
  }

  Future<List<PublicProject>> _loadProjects() {
    final future = Future<List<PublicProject>>.sync(
      widget.repository.getPublicProjects,
    );
    // Handle immediate errors before the next frame attaches FutureBuilder.
    future.ignore();
    return future;
  }

  void _reload() => setState(() {
    _projects = _loadProjects();
  });

  void _clearFilters() => setState(() {
    _search.clear();
    _category = null;
    _selectedId = null;
  });

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
          PublicProjectDetails(project: project, mode: widget.config.mode),
    );
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: const Text('Public GIS map'),
      actions: [
        IconButton(
          tooltip: 'Association access',
          icon: const Icon(Icons.person_outline),
          onPressed: () =>
              Navigator.of(context).pushNamed(AppRoutes.associationAccess),
        ),
        IconButton(
          tooltip: 'Reload public projects',
          icon: const Icon(Icons.refresh),
          onPressed: _reload,
        ),
      ],
    ),
    body: SafeArea(
      child: Column(
        children: [
          DataModeBanner(mode: widget.config.mode),
          Expanded(
            child: FutureBuilder<List<PublicProject>>(
              future: _projects,
              builder: (context, snapshot) {
                if (snapshot.connectionState != ConnectionState.done) {
                  return const Center(
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        CircularProgressIndicator(),
                        SizedBox(height: 16),
                        Text('Loading public projects…'),
                      ],
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
                final projects = snapshot.data ?? const <PublicProject>[];
                if (projects.isEmpty) {
                  return FeedbackPanel(
                    icon: Icons.map_outlined,
                    title: 'No public projects yet',
                    message:
                        'There are no public locations available from the selected data source.',
                    actionLabel: 'Reload',
                    onAction: _reload,
                  );
                }
                return _loaded(projects);
              },
            ),
          ),
        ],
      ),
    ),
  );

  Widget _loaded(List<PublicProject> projects) {
    final categories =
        projects.map((project) => project.category).toSet().toList()..sort();
    final visible = projects
        .where(
          (project) =>
              project.matches(_search.text) &&
              (_category == null || project.category == _category),
        )
        .toList();
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
          child: TextField(
            key: const ValueKey('map-search'),
            controller: _search,
            onChanged: (_) => setState(() {
              _selectedId = null;
            }),
            decoration: InputDecoration(
              labelText: 'Search public projects',
              hintText: 'Project, association, or sample area',
              prefixIcon: const Icon(Icons.search),
              suffixIcon: _search.text.isEmpty
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
                        label: const Text('All projects'),
                        selected: _category == null,
                        onSelected: (_) => setState(() {
                          _category = null;
                          _selectedId = null;
                        }),
                      ),
                      for (final category in categories) ...[
                        const SizedBox(width: 8),
                        FilterChip(
                          label: Text(category),
                          selected: _category == category,
                          onSelected: (value) => setState(() {
                            _category = value ? category : null;
                            _selectedId = null;
                          }),
                        ),
                      ],
                    ],
                  ),
                ),
              ),
              const SizedBox(width: 8),
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
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 8),
          child: Row(
            children: [
              Expanded(
                child: Text(
                  '${visible.length} public location(s)',
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
          child: visible.isEmpty
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
                      );
                      final list = _projectList(visible);
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
                              SizedBox(height: 260, child: map),
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

  Widget _projectList(List<PublicProject> projects) => ListView.separated(
    key: const ValueKey('public-project-list'),
    itemCount: projects.length,
    separatorBuilder: (_, _) => const SizedBox(height: 6),
    itemBuilder: (context, index) {
      final project = projects[index];
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
            backgroundColor: projectColor(
              project.category,
            ).withValues(alpha: .12),
            child: Icon(
              Icons.location_on_outlined,
              color: projectColor(project.category),
            ),
          ),
          title: Text(
            project.title,
            style: const TextStyle(fontWeight: FontWeight.w600),
          ),
          subtitle: Text(
            '${project.associationName}\n${project.category} · ${project.areaLabel}',
          ),
          trailing: const Icon(Icons.chevron_right),
          isThreeLine: true,
          onTap: () {
            _mapController.move(mapPoint(project.location), 13);
            _select(project);
          },
        ),
      );
    },
  );
}
