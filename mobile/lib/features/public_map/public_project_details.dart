import 'package:flutter/material.dart';

import '../../app/configuration/app_config.dart';
import '../../core/widgets/assocmap_widgets.dart';
import '../../data/models/public_project.dart';
import 'gis_site_symbols.dart';

class PublicProjectDetails extends StatelessWidget {
  const PublicProjectDetails({
    super.key,
    required this.project,
    required this.mode,
  });
  final PublicProject project;
  final GisDataMode mode;

  @override
  Widget build(BuildContext context) => SafeArea(
    child: SingleChildScrollView(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const PhotoPlaceholder(
            height: 120,
            label: 'Location photo unavailable',
          ),
          Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        mode == GisDataMode.live
                            ? 'Public location details'
                            : 'Public project summary',
                        style: Theme.of(context).textTheme.labelLarge,
                      ),
                    ),
                    IconButton(
                      tooltip: mode == GisDataMode.live
                          ? 'Close location details'
                          : 'Close project details',
                      onPressed: () => Navigator.pop(context),
                      icon: const Icon(Icons.close),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                Text(
                  project.title,
                  style: Theme.of(context).textTheme.headlineSmall,
                ),
                const SizedBox(height: 12),
                if (project.associationName != null)
                  Text(
                    project.associationName!,
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                const SizedBox(height: 12),
                if (mode == GisDataMode.live) ...[
                  Text('Geometry type: ${project.filterLabel}'),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Icon(
                        gisSiteSymbol(project.siteTypeCode),
                        color: gisSiteColor(project.siteTypeCode),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          'Site classification: ${project.siteTypeLabel}',
                        ),
                      ),
                    ],
                  ),
                ],
                Wrap(
                  spacing: 8,
                  runSpacing: 4,
                  children: [
                    if (mode != GisDataMode.live)
                      Chip(label: Text(project.filterLabel)),
                    if (project.areaLabel != null)
                      Chip(label: Text(project.areaLabel!)),
                  ],
                ),
                const SizedBox(height: 12),
                Text(
                  project.summary.isEmpty
                      ? 'No public description provided.'
                      : project.summary,
                  style: const TextStyle(height: 1.6),
                ),
                const SizedBox(height: 16),
                Text(
                  mode == GisDataMode.live && project.isBoundary
                      ? project.displayPoint == null
                            ? 'Representative marker unavailable from this server. The original project boundary is retained.'
                            : 'A representative marker within the project site is shown. The original boundary, including all parts and holes, is retained. This is not an administrative boundary.'
                      : project.boundary == null
                      ? 'Location marker only · No boundary provided'
                      : 'Boundary available · ${project.boundary!.polygons.length} area(s)',
                ),
                const SizedBox(height: 24),
              ],
            ),
          ),
        ],
      ),
    ),
  );
}
