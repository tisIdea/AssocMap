import 'package:flutter/material.dart';

import '../../app/configuration/app_config.dart';
import '../../core/widgets/public_gis_mode_banner.dart';
import '../../data/models/public_project.dart';

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
          PublicGisModeBanner(mode: mode),
          Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        'Public project summary',
                        style: Theme.of(context).textTheme.labelLarge,
                      ),
                    ),
                    IconButton(
                      tooltip: 'Close project details',
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
                if (project.associationName != null) Text(
                  project.associationName!,
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: 12),
                Wrap(
                  spacing: 8,
                  runSpacing: 4,
                  children: [
                    Chip(label: Text(project.filterLabel)),
                    if (project.areaLabel != null) Chip(label: Text(project.areaLabel!)),
                  ],
                ),
                const SizedBox(height: 12),
                Text(project.summary.isEmpty ? 'No public description provided.' : project.summary,
                  style: const TextStyle(height: 1.6)),
                const SizedBox(height: 16),
                Text(
                  project.boundary == null
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
