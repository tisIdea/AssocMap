import 'package:flutter/material.dart';

import '../../core/widgets/feedback_panel.dart';
import '../../data/models/association_dashboard.dart';
import '../../data/models/auth_session.dart';
import '../../data/repositories/dashboard_repository.dart';

class AssociationDashboardScreen extends StatefulWidget {
  const AssociationDashboardScreen({
    super.key,
    required this.repository,
    required this.session,
    required this.onSessionExpired,
    required this.onOpenReports,
    required this.onOpenMap,
  });
  final DashboardRepository repository;
  final AuthSession session;
  final VoidCallback onSessionExpired;
  final VoidCallback onOpenReports;
  final VoidCallback onOpenMap;

  @override
  State<AssociationDashboardScreen> createState() =>
      _AssociationDashboardScreenState();
}

class _AssociationDashboardScreenState
    extends State<AssociationDashboardScreen> {
  AssociationDashboard? _data;
  DashboardFailure? _failure;
  bool _loading = true;
  int _operation = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant AssociationDashboardScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.repository != widget.repository ||
        oldWidget.session != widget.session) {
      _load();
    }
  }

  Future<void> _load() async {
    final operation = ++_operation;
    setState(() {
      _loading = true;
      _data = null;
      _failure = null;
    });
    try {
      if (widget.session.isDemo) {
        throw const DashboardFailure(DashboardFailureKind.integration);
      }
      if (widget.session.association?.isValid != true) {
        throw const DashboardFailure(DashboardFailureKind.denied);
      }
      final data = await widget.repository.load();
      if (!mounted || operation != _operation) return;
      if (data.associationId != widget.session.association?.id) {
        throw const DashboardFailure(DashboardFailureKind.denied);
      }
      setState(() => _data = data);
    } on DashboardFailure catch (error) {
      if (!mounted || operation != _operation) return;
      setState(() => _failure = error);
      if (error.kind == DashboardFailureKind.expired) widget.onSessionExpired();
    } catch (_) {
      if (mounted && operation == _operation) {
        setState(
          () => _failure = const DashboardFailure(
            DashboardFailureKind.unavailable,
          ),
        );
      }
    } finally {
      if (mounted && operation == _operation) setState(() => _loading = false);
    }
  }

  Widget _panel(String title, List<Widget> children) => Card(
    child: Padding(
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(title, style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 12),
          ...children,
        ],
      ),
    ),
  );

  Widget _line(String label, Object? value) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 6),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(child: Text(label)),
        const SizedBox(width: 16),
        Flexible(
          child: Text(
            value?.toString() ?? 'Unavailable',
            textAlign: TextAlign.end,
            style: const TextStyle(fontWeight: FontWeight.w600),
          ),
        ),
      ],
    ),
  );

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            CircularProgressIndicator(),
            SizedBox(height: 16),
            Text('Loading association dashboard…'),
          ],
        ),
      );
    }
    final failure = _failure;
    if (failure != null) {
      return FeedbackPanel(
        icon: failure.kind == DashboardFailureKind.denied
            ? Icons.lock_outline
            : Icons.cloud_off_outlined,
        title: failure.kind == DashboardFailureKind.integration
            ? 'Dashboard connection unavailable'
            : 'Dashboard unavailable',
        message: failure.message,
        actionLabel: failure.kind == DashboardFailureKind.integration
            ? 'Explore public map'
            : 'Retry',
        onAction: failure.kind == DashboardFailureKind.integration
            ? widget.onOpenMap
            : _load,
      );
    }
    final data = _data;
    if (data == null) {
      return const FeedbackPanel(
        icon: Icons.info_outline,
        title: 'No dashboard records',
        message: 'Refresh after your association access has been confirmed.',
      );
    }
    final manila = data.generatedAt
        .toUtc()
        .add(const Duration(hours: 8))
        .toIso8601String()
        .substring(0, 16)
        .replaceFirst('T', ' ');
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(16),
        children: [
          Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 900),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _panel(data.name, [
                    Text('${data.municipality} · ${data.status}'),
                    const SizedBox(height: 8),
                    Text('Updated $manila · Asia/Manila'),
                    const SizedBox(height: 8),
                    const Text(
                      'Only your authorized association records. Pull down to refresh.',
                    ),
                  ]),
                  LayoutBuilder(
                    builder: (context, constraints) {
                      final columns = constraints.maxWidth >= 650
                          ? 3
                          : (constraints.maxWidth >= 440 ? 2 : 1);
                      final width =
                          (constraints.maxWidth - (columns - 1) * 12) / columns;
                      return Wrap(
                        spacing: 12,
                        runSpacing: 12,
                        children: [
                          for (final metric in data.metrics)
                            SizedBox(
                              width: width,
                              child: Card(
                                child: Padding(
                                  padding: const EdgeInsets.all(20),
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        metric.label,
                                        style: Theme.of(
                                          context,
                                        ).textTheme.titleSmall,
                                      ),
                                      const SizedBox(height: 12),
                                      Text(
                                        metric.value?.toString() ??
                                            'Unavailable',
                                        style: Theme.of(
                                          context,
                                        ).textTheme.headlineMedium,
                                      ),
                                      const SizedBox(height: 8),
                                      Text(metric.note),
                                    ],
                                  ),
                                ),
                              ),
                            ),
                        ],
                      );
                    },
                  ),
                  const SizedBox(height: 12),
                  _panel('Quick actions', [
                    Wrap(
                      spacing: 12,
                      runSpacing: 12,
                      children: [
                        FilledButton.icon(
                          onPressed: widget.onOpenReports,
                          icon: const Icon(Icons.analytics_outlined),
                          label: const Text('Reports & Analytics'),
                        ),
                        OutlinedButton.icon(
                          onPressed: widget.onOpenMap,
                          icon: const Icon(Icons.map_outlined),
                          label: const Text('Public GIS map'),
                        ),
                      ],
                    ),
                  ]),
                  _panel('Member application status', [
                    if (data.applications == null)
                      const Text(
                        'Application counts are temporarily unavailable.',
                      )
                    else ...[
                      for (final entry in data.applications!.entries)
                        _line(entry.key, entry.value),
                    ],
                    const SizedBox(height: 8),
                    const Text(
                      'Application review belongs to the Field Officer. These totals are separate from official membership.',
                    ),
                  ]),
                  for (final chart in data.charts)
                    _panel(chart?.title ?? 'Chart unavailable', [
                      if (chart == null)
                        const Text('This summary could not be read.')
                      else ...[
                        Text(chart.note),
                        if (chart.rows.isEmpty)
                          const Text('No matching records.'),
                        for (final row in chart.rows)
                          _line(row.label, row.value),
                      ],
                    ]),
                  _panel('Training history', [
                    if (data.training == null)
                      const Text(
                        'Training summaries are temporarily unavailable.',
                      )
                    else ...[
                      _line('Recorded activities', data.training!.records),
                      _line('Completed activities', data.training!.completed),
                      _line(
                        'Distinct members marked present',
                        data.training!.distinctPresentMembers,
                      ),
                    ],
                    const SizedBox(height: 8),
                    const Text(
                      'All dates, including archived training/member history. People are counted once across present attendance records.',
                    ),
                  ]),
                  _panel('Monitoring overview', [
                    if (data.monitoring == null)
                      const Text(
                        'Monitoring summaries are temporarily unavailable.',
                      )
                    else ...[
                      _line('Quarterly records', data.monitoring!.records),
                      _line('Year / quarter periods', data.monitoring!.periods),
                      _line('Visit reports', data.monitoring!.visits),
                      _line(
                        'Legacy imported visits',
                        data.monitoring!.legacyVisits,
                      ),
                    ],
                    const SizedBox(height: 8),
                    const Text(
                      'All years for unarchived projects. Missing records are not completion failures.',
                    ),
                  ]),
                  _panel('Recently registered projects', [
                    if (data.projects == null)
                      const Text('Project records are temporarily unavailable.')
                    else if (data.projects!.isEmpty)
                      const Text('No unarchived projects in this association.')
                    else ...[
                      for (final project in data.projects!)
                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          title: Text(project.title),
                          subtitle: Text(
                            'Program year ${project.programYear} · ${project.status}',
                          ),
                        ),
                    ],
                    const Text(
                      'Up to six unarchived projects ordered by registration timestamp.',
                    ),
                  ]),
                  _panel('Latest dated activities', [
                    if (data.activities == null)
                      const Text(
                        'Activity records are temporarily unavailable.',
                      )
                    else if (data.activities!.isEmpty)
                      const Text('No recorded activities.')
                    else ...[
                      for (final activity in data.activities!)
                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          title: Text(activity.title),
                          subtitle: Text(
                            '${activity.date} · ${activity.status}',
                          ),
                        ),
                    ],
                  ]),
                  _panel('Latest dated monitoring records', [
                    if (data.monitoring == null)
                      const Text(
                        'Monitoring records are temporarily unavailable.',
                      )
                    else if (data.monitoring!.recent.isEmpty)
                      const Text('No quarterly monitoring records.')
                    else ...[
                      for (final record in data.monitoring!.recent)
                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          title: Text(record.title),
                          subtitle: Text(
                            '${record.year} · Q${record.quarter} · ${record.date}\nHistorical status: ${record.status}',
                          ),
                        ),
                    ],
                  ]),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
