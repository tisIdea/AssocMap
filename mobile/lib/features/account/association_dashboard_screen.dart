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
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
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
                    color: AppTheme.green,
                    borderRadius: BorderRadius.circular(16),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      const Text(
                        'YOUR ASSOCIATION',
                        style: TextStyle(fontSize: 11, color: Colors.white),
                      ),
                      const SizedBox(height: 8),
                      Text(
                        data.name,
                        style: const TextStyle(
                          fontSize: 20,
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
                const SectionHeading('Quick access'),
                LayoutBuilder(
                  builder: (context, box) {
                    final columns =
                        MediaQuery.textScalerOf(context).scale(14) > 21
                        ? 1
                        : box.maxWidth >= 600
                        ? 3
                        : 2;
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
                                      Icon(item.$3, color: AppTheme.green),
                                      const SizedBox(height: 8),
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
                const SizedBox(height: 16),
                Card(
                  child: ExpansionTile(
                    title: const Text('Association summary'),
                    subtitle: const Text('Count definitions and last update'),
                    childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
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
                                  color: AppTheme.green,
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
                        'Updated ${displayDate(context, data.generatedAt.toLocal())}',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                    ],
                  ),
                ),
                ..._statistics(context, data),
                ..._monitoring(context, data.monitoring),
                const SectionHeading('Recent program activities'),
                if (data.activities == null)
                  const Text('Program activities are currently unavailable.')
                else if (data.activities!.isEmpty)
                  const Text('No program activities recorded.')
                else
                  for (final activity in data.activities!)
                    _recordCard(
                      context,
                      title: activity.title,
                      detail: activity.date,
                      status: activity.status,
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

  List<Widget> _statistics(BuildContext context, AssociationDashboard data) => [
    for (final chart in data.charts.whereType<DashboardChart>()) ...[
      SectionHeading(chart.title),
      InformationCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(chart.note, style: Theme.of(context).textTheme.bodySmall),
            if (chart.rows.isEmpty) const Text('No projects recorded.'),
            for (final row in chart.rows)
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: Row(
                  children: [
                    Expanded(child: Text(row.label)),
                    const SizedBox(width: 12),
                    Text(
                      '${row.value}',
                      style: const TextStyle(
                        color: AppTheme.green,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ],
                ),
              ),
          ],
        ),
      ),
    ],
  ];

  List<Widget> _monitoring(
    BuildContext context,
    DashboardMonitoring? monitoring,
  ) => [
    const SectionHeading('Monitoring overview'),
    if (monitoring == null)
      const Text('Monitoring information is currently unavailable.')
    else ...[
      InformationCard(
        child: MetricStrip(
          values: {
            'Records': monitoring.records,
            'Periods': monitoring.periods,
            'Visits': monitoring.visits,
          },
        ),
      ),
      const SectionHeading('Recent quarterly monitoring'),
      if (monitoring.recent.isEmpty)
        const Text('No quarterly monitoring records available.'),
      for (final record in monitoring.recent)
        _recordCard(
          context,
          title: record.title,
          detail: '${record.year} · Q${record.quarter} · ${record.date}',
          status: 'Recorded status: ${record.status}',
        ),
    ],
  ];

  Widget _recordCard(
    BuildContext context, {
    required String title,
    required String detail,
    required String status,
  }) => Padding(
    padding: const EdgeInsets.only(bottom: 12),
    child: InformationCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(title, style: const TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: 6),
          Text(detail, style: Theme.of(context).textTheme.bodySmall),
          const SizedBox(height: 8),
          StatusBadge(
            status: status.replaceFirst('Recorded status: ', ''),
            label: status,
          ),
        ],
      ),
    ),
  );
}
