import 'package:flutter/material.dart';
import '../../app/theme/app_theme.dart';
import '../../core/widgets/assocmap_widgets.dart';
import '../../data/models/auth_session.dart';
import '../../data/models/association_dashboard.dart';
import '../../data/repositories/dashboard_repository.dart';
import 'association_data_view.dart';

int? dashboardCount(AssociationDashboard data, String key) {
  for (final metric in data.metrics) {
    if (metric.key == key) return metric.value;
  }
  return null;
}

class AssociationDashboardScreen extends StatelessWidget {
  const AssociationDashboardScreen({
    super.key,
    required this.repository,
    required this.session,
    required this.onSessionExpired,
    required this.onOpenReports,
    required this.onOpenMap,
    this.onOpenSection,
  });
  final DashboardRepository repository;
  final AuthSession session;
  final VoidCallback onSessionExpired;
  final VoidCallback onOpenReports;
  final VoidCallback onOpenMap;
  final ValueChanged<String>? onOpenSection;
  @override
  Widget build(BuildContext context) => AssociationDataView(
    repository: repository,
    session: session,
    onSessionExpired: onSessionExpired,
    builder: (data, refresh) => RefreshIndicator(
      onRefresh: refresh,
      child: SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(16),
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 760),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  'Maayong adlaw!',
                  style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 4),
                const Text(
                  'Your association at a glance',
                  style: TextStyle(color: AppTheme.muted),
                ),
                const SizedBox(height: 18),
                Container(
                  padding: const EdgeInsets.all(20),
                  decoration: BoxDecoration(
                    color: AppTheme.blue,
                    borderRadius: BorderRadius.circular(20),
                    boxShadow: [
                      BoxShadow(
                        color: AppTheme.blue.withValues(alpha: .15),
                        blurRadius: 14,
                        offset: const Offset(0, 6),
                      ),
                    ],
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      const Text(
                        'YOUR ASSOCIATION',
                        style: TextStyle(fontSize: 11, color: Colors.white),
                      ),
                      const SizedBox(height: 12),
                      Text(
                        data.name,
                        style: const TextStyle(
                          fontSize: 22,
                          fontWeight: FontWeight.w700,
                          color: Colors.white,
                        ),
                      ),
                      const SizedBox(height: 8),
                      Text(
                        data.municipality,
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 13,
                        ),
                      ),
                      const Divider(color: Colors.white24),
                      MetricStrip(
                        inverse: true,
                        values: {
                          'Members': dashboardCount(data, 'members_total'),
                          'Pending': data.applications?['pending'],
                          'Projects': dashboardCount(data, 'projects_total'),
                          'Trainings': data.training?.records,
                        },
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 14),
                const ReadOnlyNotice(
                  message:
                      'Member records are read-only. Registrations are subject to Field Officer review.',
                  icon: Icons.info_outline,
                ),
                const SectionHeading('Quick access'),
                LayoutBuilder(
                  builder: (context, box) {
                    final columns = box.maxWidth >= 600 ? 3 : 2;
                    final entries = <(String, String, IconData, VoidCallback)>[
                      (
                        'Association',
                        'Official profile',
                        Icons.apartment,
                        () => onOpenSection?.call('association'),
                      ),
                      (
                        'Members',
                        'Members & registrations',
                        Icons.groups_outlined,
                        () => onOpenSection?.call('members'),
                      ),
                      (
                        'Programs',
                        'Project summaries',
                        Icons.map_outlined,
                        () => onOpenSection?.call('projects'),
                      ),
                      (
                        'Trainings',
                        'Learning activities',
                        Icons.school_outlined,
                        () => onOpenSection?.call('trainings'),
                      ),
                      (
                        'Production',
                        'Read-only monitoring',
                        Icons.bar_chart,
                        () => onOpenSection?.call('production'),
                      ),
                      (
                        'Reports',
                        'Reports & Analytics',
                        Icons.analytics_outlined,
                        onOpenReports,
                      ),
                    ];
                    return Wrap(
                      spacing: 12,
                      runSpacing: 12,
                      children: [
                        for (final item in entries)
                          SizedBox(
                            width:
                                (box.maxWidth - 12 * (columns - 1)) / columns,
                            child: Card(
                              child: InkWell(
                                borderRadius: BorderRadius.circular(16),
                                onTap: item.$4,
                                child: Padding(
                                  padding: const EdgeInsets.all(16),
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Icon(item.$3, color: AppTheme.blue),
                                      const SizedBox(height: 12),
                                      Text(
                                        item.$1,
                                        style: const TextStyle(
                                          fontWeight: FontWeight.w700,
                                        ),
                                      ),
                                      const SizedBox(height: 3),
                                      Text(
                                        item.$2,
                                        style: Theme.of(
                                          context,
                                        ).textTheme.bodySmall,
                                      ),
                                    ],
                                  ),
                                ),
                              ),
                            ),
                          ),
                      ],
                    );
                  },
                ),
                const SectionHeading('Association summary'),
                InformationCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      for (final metric in data.metrics)
                        Padding(
                          padding: const EdgeInsets.symmetric(vertical: 8),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                metric.label,
                                style: const TextStyle(
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                              Text(
                                metric.value?.toString() ?? 'Unavailable',
                                style: const TextStyle(
                                  color: AppTheme.blue,
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                              Text(
                                metric.note,
                                style: Theme.of(context).textTheme.bodySmall,
                              ),
                            ],
                          ),
                        ),
                      Text(
                        'Updated ${data.generatedAt.toIso8601String()}',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 12),
                OutlinedButton.icon(
                  onPressed: onOpenMap,
                  icon: const Icon(Icons.public),
                  label: const Text('Open public map'),
                ),
              ],
            ),
          ),
        ),
      ),
    ),
  );
}
