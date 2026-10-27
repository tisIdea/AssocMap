import 'package:flutter/material.dart';

import '../../core/widgets/feedback_panel.dart';
import '../../data/models/analytics_report.dart';
import '../../data/repositories/analytics_repository.dart';

class AssociationAnalyticsScreen extends StatefulWidget {
  const AssociationAnalyticsScreen({
    super.key,
    required this.repository,
    this.onSessionExpired,
    this.initialReportType = 'comprehensive',
    this.showReportTypeSelector = true,
  });
  final AnalyticsRepository repository;
  final VoidCallback? onSessionExpired;
  final String initialReportType;
  final bool showReportTypeSelector;

  @override
  State<AssociationAnalyticsScreen> createState() =>
      _AssociationAnalyticsScreenState();
}

class _AssociationAnalyticsScreenState extends State<AssociationAnalyticsScreen>
    with WidgetsBindingObserver {
  static const types = {
    'comprehensive': 'Comprehensive Report',
    'associations': 'Association Distribution',
    'members': 'Member Summary',
    'projects': 'Project Portfolio',
    'trainings': 'Training Participation',
    'production': 'Production Performance',
    'income': 'Gross Income',
    'activities': 'Activities and Monitoring',
  };
  AnalyticsReport? _report;
  AnalyticsPeriods? _periods;
  AnalyticsFailure? _failure;
  String _type = 'comprehensive';
  int? _year;
  int? _programYear;
  int? _quarter;
  int? _month;
  int _operation = 0;
  bool _loading = true;

  bool get _hasPeriod =>
      !['associations', 'members', 'projects'].contains(_type);

  @override
  void initState() {
    super.initState();
    _type = types.containsKey(widget.initialReportType)
        ? widget.initialReportType
        : 'comprehensive';
    WidgetsBinding.instance.addObserver(this);
    _load();
  }

  @override
  void dispose() {
    ++_operation;
    _report = null;
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _load();
    } else if (mounted) {
      ++_operation;
      setState(() {
        _report = null;
        _loading = true;
      });
    }
  }

  Future<void> _load() async {
    final operation = ++_operation;
    setState(() {
      _loading = true;
      _failure = null;
      _report = null;
    });
    try {
      final periods = await widget.repository.periods();
      if (!mounted || operation != _operation) return;
      final year = periods.years.contains(_year)
          ? _year
          : (periods.years.isEmpty ? null : periods.years.first);
      final filters = <String, String>{
        'report_type': _type,
        if (_hasPeriod && year != null) 'year': '$year',
        if (_hasPeriod && _quarter != null) 'quarter': '$_quarter',
        if (['production', 'income'].contains(_type) && _month != null)
          'month': '$_month',
        if (!['associations', 'members'].contains(_type) &&
            _programYear != null)
          'program_year': '$_programYear',
      };
      final report = await widget.repository.load(filters);
      if (!mounted || operation != _operation) return;
      setState(() {
        _periods = periods;
        _year = year;
        _report = report;
        _loading = false;
      });
    } on AnalyticsFailure catch (error) {
      if (!mounted || operation != _operation) return;
      setState(() {
        _failure = error;
        _loading = false;
      });
      if (error.kind == AnalyticsFailureKind.expired) {
        widget.onSessionExpired?.call();
      }
    } catch (_) {
      if (!mounted || operation != _operation) return;
      setState(() {
        _failure = const AnalyticsFailure(AnalyticsFailureKind.unavailable);
        _loading = false;
      });
    }
  }

  Widget _choice(
    String label,
    int? value,
    List<int> choices,
    ValueChanged<int?> change, {
    bool nullable = true,
  }) => SizedBox(
    width: MediaQuery.textScalerOf(context).scale(12) > 18
        ? double.infinity
        : ((MediaQuery.sizeOf(context).width - 52) / 2).clamp(130, 175),
    child: DropdownButtonFormField<int>(
      initialValue: choices.contains(value) ? value : null,
      key: ValueKey('$label:$value:${choices.join(',')}'),
      isExpanded: true,
      decoration: InputDecoration(labelText: label),
      items: [
        if (nullable)
          const DropdownMenuItem<int>(
            value: null,
            child: Text(
              'All recorded',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ),
        for (final choice in choices)
          DropdownMenuItem(value: choice, child: Text('$choice')),
      ],
      onChanged: _loading ? null : change,
    ),
  );

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Center(
        child: CircularProgressIndicator(
          semanticsLabel: 'Loading authorized reports',
        ),
      );
    }
    final failure = _failure;
    if (failure != null) {
      return FeedbackPanel(
        icon: failure.kind == AnalyticsFailureKind.offline
            ? Icons.wifi_off
            : Icons.info_outline,
        title: 'Reports unavailable',
        message: failure.message,
        actionLabel: 'Retry',
        onAction: _load,
      );
    }
    final report = _report!;
    final notes = ExpansionTile(
      title: const Text('Data quality and interpretation'),
      children: [for (final note in report.notes) ListTile(title: Text(note))],
    );
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(20),
        children: [
          Text(
            report.scope.join('; '),
            style: Theme.of(context).textTheme.titleLarge,
          ),
          const SizedBox(height: 12),
          if (widget.showReportTypeSelector)
            DropdownButtonFormField<String>(
              initialValue: _type,
              decoration: const InputDecoration(labelText: 'Report type'),
              isExpanded: true,
              items: types.entries
                  .map(
                    (entry) => DropdownMenuItem(
                      value: entry.key,
                      child: Text(entry.value),
                    ),
                  )
                  .toList(),
              onChanged: (value) {
                if (value != null) {
                  setState(() {
                    _type = value;
                    _quarter = null;
                    _month = null;
                    _programYear = null;
                  });
                  _load();
                }
              },
            ),
          const SizedBox(height: 16),
          Wrap(
            spacing: 12,
            runSpacing: 12,
            children: [
              if (_hasPeriod)
                _choice('Reporting year', _year, _periods!.years, (v) {
                  setState(() => _year = v);
                  _load();
                }, nullable: false),
              if (_hasPeriod)
                _choice('Quarter', _quarter, const [1, 2, 3, 4], (v) {
                  setState(() {
                    _quarter = v;
                    _month = null;
                  });
                  _load();
                }),
              if (['production', 'income'].contains(_type))
                _choice(
                  'Recorded month',
                  _month,
                  _quarter == null
                      ? List.generate(12, (i) => i + 1)
                      : List.generate(3, (i) => (_quarter! - 1) * 3 + i + 1),
                  (v) {
                    setState(() => _month = v);
                    _load();
                  },
                ),
              if (!['associations', 'members'].contains(_type))
                _choice('Program year', _programYear, _periods!.programYears, (
                  v,
                ) {
                  setState(() => _programYear = v);
                  _load();
                }),
            ],
          ),
          const SizedBox(height: 18),
          Text(report.title, style: Theme.of(context).textTheme.headlineSmall),
          Text(
            widget.showReportTypeSelector
                ? 'Generated ${report.generatedAt} · ${report.timezone}'
                : 'Updated ${report.generatedAt.split('T').first} · ${report.timezone}',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          Text(report.scopeBasis),
          if (report.empty)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 16),
              child: Text('No recorded results match these report filters.'),
            ),
          if (widget.showReportTypeSelector) notes,
          for (final section in report.sections)
            _ReportSection(
              key: ObjectKey(section),
              section: section,
              expanded: !widget.showReportTypeSelector,
            ),
          if (!widget.showReportTypeSelector) notes,
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 16),
            child: Text(
              'Reports require a current authorized connection. No offline report snapshot is stored.',
            ),
          ),
        ],
      ),
    );
  }
}

class _ReportSection extends StatefulWidget {
  const _ReportSection({
    super.key,
    required this.section,
    this.expanded = false,
  });
  final AnalyticsSection section;
  final bool expanded;

  @override
  State<_ReportSection> createState() => _ReportSectionState();
}

class _ReportSectionState extends State<_ReportSection> {
  int _page = 0;

  Widget _chart(BuildContext context) {
    final section = widget.section;
    if (section.chart.isEmpty || section.rows.isEmpty) {
      return const SizedBox.shrink();
    }
    final chart = section.chart;
    final rows = section.rows.take(20).toList();
    // Visual scaling only; authoritative totals and groupings come from Python.
    final values = rows
        .map((row) => num.parse(row[chart['value_key']].toString()).toDouble())
        .toList();
    final max = values.fold<double>(0, (a, b) => a > b ? a : b);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('${chart['title']} (${chart['unit']})'),
        if (widget.expanded)
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                for (var index = 0; index < rows.length; index++)
                  SizedBox(
                    width: 82,
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(
                          '${rows[index][chart['value_key']]}',
                          style: const TextStyle(fontSize: 11),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                        const SizedBox(height: 8),
                        SizedBox(
                          height: 120,
                          width: 48,
                          child: Align(
                            alignment: Alignment.bottomCenter,
                            child: FractionallySizedBox(
                              heightFactor: max > 0 ? values[index] / max : 0,
                              widthFactor: 1,
                              child: DecoratedBox(
                                decoration: BoxDecoration(
                                  color: Theme.of(context).colorScheme.primary,
                                  borderRadius: const BorderRadius.vertical(
                                    top: Radius.circular(5),
                                  ),
                                ),
                              ),
                            ),
                          ),
                        ),
                        const SizedBox(height: 8),
                        SizedBox(
                          height: MediaQuery.textScalerOf(context).scale(30),
                          child: Text(
                            '${rows[index][chart['label_key']]}',
                            textAlign: TextAlign.center,
                            style: const TextStyle(fontSize: 11),
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          ),
        if (!widget.expanded)
          for (var index = 0; index < rows.length; index++)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 6),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '${rows[index][chart['label_key']]}: ${rows[index][chart['value_key']]}',
                  ),
                  ExcludeSemantics(
                    child: LinearProgressIndicator(
                      value: max > 0 ? values[index] / max : 0,
                      minHeight: 8,
                    ),
                  ),
                ],
              ),
            ),
        const Text(
          'Up to 20 groups shown. The table contains all recorded values.',
        ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final section = widget.section;
    final start = _page * 25;
    final rows = section.rows.skip(start).take(25);
    return Card(
      margin: const EdgeInsets.symmetric(vertical: 10),
      child: ExpansionTile(
        initiallyExpanded: widget.expanded,
        title: Text(section.title),
        childrenPadding: const EdgeInsets.all(16),
        children: [
          Wrap(
            spacing: 12,
            runSpacing: 12,
            children: [
              for (final measure in section.measures)
                SizedBox(
                  width: 180,
                  child: Card(
                    child: Padding(
                      padding: const EdgeInsets.all(12),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(measure.label),
                          Text(
                            measure.value ?? 'Unobserved',
                            style: Theme.of(context).textTheme.titleLarge,
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
            ],
          ),
          for (final note in section.notes)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 6),
              child: Text(note),
            ),
          _chart(context),
          if (section.rows.isEmpty)
            const Text('No recorded rows for this section.')
          else ...[
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: DataTable(
                columns: section.columns
                    .map((c) => DataColumn(label: Text(c.label)))
                    .toList(),
                dataRowMinHeight: 48,
                dataRowMaxHeight: 180,
                rows: rows
                    .map(
                      (row) => DataRow(
                        cells: section.columns
                            .map(
                              (column) => DataCell(
                                SizedBox(
                                  width: column.type == 'text' ? 190 : 110,
                                  child: Text(
                                    row[column.key]?.toString() ??
                                        'Unobserved / N/A',
                                  ),
                                ),
                              ),
                            )
                            .toList(),
                      ),
                    )
                    .toList(),
              ),
            ),
            Wrap(
              spacing: 12,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                TextButton(
                  onPressed: _page == 0 ? null : () => setState(() => _page--),
                  child: const Text('Previous'),
                ),
                Text(
                  'Rows ${start + 1}–${(start + 25).clamp(0, section.rows.length)} of ${section.rows.length}',
                ),
                TextButton(
                  onPressed: start + 25 >= section.rows.length
                      ? null
                      : () => setState(() => _page++),
                  child: const Text('Next'),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}
