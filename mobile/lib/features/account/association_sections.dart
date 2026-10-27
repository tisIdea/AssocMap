import 'package:flutter/material.dart';
import '../../app/theme/app_theme.dart';
import '../../core/widgets/assocmap_widgets.dart';
import '../../data/models/association_dashboard.dart';
import '../../data/models/auth_session.dart';
import '../../data/repositories/dashboard_repository.dart';
import 'association_data_view.dart';
import 'association_dashboard_screen.dart';

class AssociationSections extends StatefulWidget {
  const AssociationSections({
    super.key,
    required this.section,
    required this.repository,
    required this.session,
    required this.onSessionExpired,
    required this.onOpenSection,
  });
  final String section;
  final DashboardRepository repository;
  final AuthSession session;
  final VoidCallback onSessionExpired;
  final ValueChanged<String> onOpenSection;
  @override
  State<AssociationSections> createState() => _AssociationSectionsState();
}

class _AssociationSectionsState extends State<AssociationSections> {
  String _query = '';
  String _status = 'All';
  bool _registrations = false;

  Widget _unavailable(String title, String message) => InformationCard(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Icon(Icons.info_outline, color: AppTheme.blue),
        const SizedBox(height: 10),
        Text(title, style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 8),
        Text(message),
      ],
    ),
  );

  Widget _tabs(
    List<String> labels,
    String selected,
    ValueChanged<String> onChanged,
  ) => Wrap(
    spacing: 8,
    runSpacing: 4,
    children: [
      for (final label in labels)
        ChoiceChip(
          label: Text(label),
          selected: label == selected,
          onSelected: (_) => onChanged(label),
        ),
    ],
  );

  @override
  Widget build(BuildContext context) => AssociationDataView(
    repository: widget.repository,
    session: widget.session,
    onSessionExpired: widget.onSessionExpired,
    builder: (data, refresh) => PageContent(
      children: [
        if (widget.section == 'association')
          ..._association(data)
        else ...[
          Text(data.name, style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 16),
          if (widget.section == 'members')
            ..._members(data)
          else
            ..._programs(data),
        ],
      ],
    ),
  );

  List<Widget> _association(AssociationDashboard data) => [
    const PhotoPlaceholder(height: 140, label: 'Association photo unavailable'),
    const SizedBox(height: 16),
    const Text(
      'ACTIVE ASSOCIATION',
      style: TextStyle(
        color: AppTheme.blue,
        fontSize: 11,
        fontWeight: FontWeight.w700,
      ),
    ),
    const SizedBox(height: 8),
    Text(data.name, style: Theme.of(context).textTheme.headlineSmall),
    const SizedBox(height: 6),
    Text(data.municipality, style: const TextStyle(color: AppTheme.muted)),
    const SizedBox(height: 20),
    MetricStrip(
      values: {
        'Members': dashboardCount(data, 'members_total'),
        'Projects': dashboardCount(data, 'projects_total'),
        'Trainings': data.training?.records,
      },
    ),
    const SizedBox(height: 20),
    const ReadOnlyNotice(message: 'Association records are view only.'),
    const SectionHeading('Association information'),
    InformationCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Municipality', style: Theme.of(context).textTheme.bodySmall),
          Text(data.municipality),
          const Divider(),
          Text('Status', style: Theme.of(context).textTheme.bodySmall),
          Text(data.status),
          const Divider(),
          const Text(
            'Additional profile information is currently unavailable.',
          ),
        ],
      ),
    ),
  ];

  List<Widget> _members(AssociationDashboard data) => [
    _tabs(
      ['Members', 'Registrations'],
      _registrations ? 'Registrations' : 'Members',
      (value) => setState(() => _registrations = value == 'Registrations'),
    ),
    const SizedBox(height: 16),
    if (_registrations) ...[
      InformationCard(
        child: MetricStrip(
          values: {
            'Pending': data.applications?['pending'],
            'Approved': data.applications?['approved'],
            'Rejected': data.applications?['rejected'],
          },
        ),
      ),
      const SizedBox(height: 16),
      _unavailable(
        'Registration records unavailable',
        'Registration totals are shown when available. Individual applications are currently unavailable on mobile.',
      ),
    ] else ...[
      const TextField(
        enabled: false,
        decoration: InputDecoration(
          hintText: 'Search members unavailable',
          prefixIcon: Icon(Icons.search),
        ),
      ),
      const SizedBox(height: 16),
      InformationCard(
        child: MetricStrip(
          values: {'Official members': dashboardCount(data, 'members_total')},
        ),
      ),
      const SizedBox(height: 16),
      _unavailable(
        'Member roster unavailable',
        'Individual member records are currently unavailable on mobile. Use your association web workspace to view the official roster.',
      ),
    ],
    const SizedBox(height: 16),
    const ReadOnlyNotice(
      message:
          'The official roster is view only. Registration approvals are handled by Field Officers only.',
    ),
  ];

  List<Widget> _programs(AssociationDashboard data) {
    final training = widget.section == 'trainings';
    final projects = data.projects
        ?.where(
          (project) =>
              project.title.toLowerCase().contains(_query.toLowerCase()) &&
              (_status == 'All' ||
                  project.status.toLowerCase() == _status.toLowerCase()),
        )
        .toList();
    return [
      _tabs(
        ['Projects', 'Trainings'],
        training ? 'Trainings' : 'Projects',
        (value) => widget.onOpenSection(value.toLowerCase()),
      ),
      const SizedBox(height: 20),
      if (training) ...[
        Text(
          'Training activities',
          style: Theme.of(context).textTheme.titleLarge,
        ),
        const SizedBox(height: 14),
        InformationCard(
          child: MetricStrip(
            values: {
              'Recorded': data.training?.records,
              'Completed': data.training?.completed,
              'Members trained': data.training?.distinctPresentMembers,
            },
          ),
        ),
        const SectionHeading('Activity calendar'),
        _unavailable(
          'Training calendar unavailable',
          'Dated training activities and attendance details are currently unavailable on mobile. These totals include retained training history.',
        ),
      ] else ...[
        TextField(
          key: const ValueKey('project-summary-search'),
          onChanged: (value) => setState(() => _query = value),
          decoration: const InputDecoration(
            hintText: 'Search recent projects',
            prefixIcon: Icon(Icons.search),
          ),
        ),
        const SizedBox(height: 10),
        _tabs(
          ['All', 'Ongoing', 'Completed'],
          _status,
          (value) => setState(() => _status = value),
        ),
        const SectionHeading('Recent project summaries'),
        const Text(
          'Up to six recent unarchived projects. This is not the full project directory.',
          style: TextStyle(fontSize: 12, color: AppTheme.muted),
        ),
        const SizedBox(height: 12),
        if (projects == null)
          _unavailable(
            'Projects unavailable',
            'Project summaries could not be loaded.',
          )
        else if (projects.isEmpty)
          _unavailable(
            'No matching project summaries',
            'No recent project summaries match the selected filters.',
          )
        else
          for (final project in projects)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: InformationCard(
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const SizedBox(
                      width: 52,
                      child: PhotoPlaceholder(height: 56),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            project.title,
                            style: const TextStyle(fontWeight: FontWeight.w700),
                          ),
                          const SizedBox(height: 5),
                          Text(
                            project.status,
                            style: const TextStyle(color: AppTheme.blue),
                          ),
                          Text(
                            'Program year ${project.programYear}',
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
      ],
      const SizedBox(height: 16),
      ReadOnlyNotice(
        message: training
            ? 'Attendance and training records are view only.'
            : 'Project records are view only. Contact your Field Officer for updates.',
      ),
      const SizedBox(height: 12),
      OutlinedButton.icon(
        onPressed: () => widget.onOpenSection('production'),
        icon: const Icon(Icons.bar_chart),
        label: const Text('Production monitoring'),
      ),
    ];
  }
}
