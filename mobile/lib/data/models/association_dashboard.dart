class DashboardMetric {
  const DashboardMetric(this.key, this.label, this.value, this.note);
  final String key;
  final String label;
  final int? value;
  final String note;

  factory DashboardMetric.fromJson(Map<String, dynamic> json) => DashboardMetric(
    _text(json['key']), _text(json['label']),
    json['value'] == null ? null : _count(json['value']), _text(json['note']),
  );
}

class DashboardProject {
  const DashboardProject(this.title, this.status, this.programYear, this.createdAt);
  final String title;
  final String status;
  final int programYear;
  final DateTime createdAt;

  factory DashboardProject.fromJson(Map<String, dynamic> json) => DashboardProject(
    _text(json['title']), _text(json['status']), _count(json['program_year']), _date(json['created_at']),
  );
}

class DashboardChart {
  const DashboardChart(this.title, this.note, this.rows);
  final String title;
  final String note;
  final List<DashboardChartRow> rows;

  factory DashboardChart.fromJson(Map<String, dynamic> json) => DashboardChart(
    _text(json['title']), _text(json['note']),
    _rows(json['rows'], 12).map((row) => DashboardChartRow(_text(row['label']), _count(row['value']))).toList(growable: false),
  );
}

class DashboardChartRow {
  const DashboardChartRow(this.label, this.value);
  final String label;
  final int value;
}

class DashboardMonitoringRecord {
  const DashboardMonitoringRecord(this.title, this.year, this.quarter, this.date, this.status);
  final String title;
  final int year;
  final int quarter;
  final String date;
  final String status;

  factory DashboardMonitoringRecord.fromJson(Map<String, dynamic> json) {
    final quarter = _count(json['quarter_number']);
    if (quarter < 1 || quarter > 4) throw const FormatException('Invalid monitoring quarter.');
    return DashboardMonitoringRecord(_text(json['title']), _count(json['year']), quarter,
      _text(json['monitoring_date']), _text(json['project_status']));
  }
}

class DashboardActivity {
  const DashboardActivity(this.title, this.date, this.status);
  final String title;
  final String date;
  final String status;

  factory DashboardActivity.fromJson(Map<String, dynamic> json) => DashboardActivity(
    _text(json['title']), _text(json['activity_date']), _text(json['status']),
  );
}

class DashboardMonitoring {
  const DashboardMonitoring(this.records, this.periods, this.visits, this.legacyVisits, this.recent);
  final int records;
  final int periods;
  final int? visits;
  final int? legacyVisits;
  final List<DashboardMonitoringRecord> recent;

  factory DashboardMonitoring.fromJson(Map<String, dynamic> json) {
    final visits = json['visits'] == null ? null : _object(json['visits']);
    return DashboardMonitoring(_count(json['records']), _count(json['periods']),
      visits == null ? null : _count(visits['total']), visits == null ? null : _count(visits['legacy_imports']),
      _rows(json['recent'], 6).map(DashboardMonitoringRecord.fromJson).toList(growable: false));
  }
}

class DashboardTraining {
  const DashboardTraining(this.records, this.completed, this.distinctPresentMembers);
  final int records;
  final int completed;
  final int distinctPresentMembers;

  factory DashboardTraining.fromJson(Map<String, dynamic> json) => DashboardTraining(
    _count(json['records']), _count(json['completed']), _count(json['distinct_present_members']),
  );
}

class AssociationDashboard {
  const AssociationDashboard({
    required this.associationId, required this.name, required this.municipality,
    required this.status, required this.generatedAt, required this.metrics,
    required this.charts, required this.projects, required this.activities,
    required this.applications, required this.training, required this.monitoring,
  });
  static const version = 'assocmap.dashboard.v1';
  final String associationId;
  final String name;
  final String municipality;
  final String status;
  final DateTime generatedAt;
  final List<DashboardMetric> metrics;
  final List<DashboardChart?> charts;
  final List<DashboardProject>? projects;
  final List<DashboardActivity>? activities;
  final Map<String, int>? applications;
  final DashboardTraining? training;
  final DashboardMonitoring? monitoring;

  factory AssociationDashboard.fromJson(Map<String, dynamic> json) {
    if (json['version'] != version || json['role'] != 'association_account' || json['timezone'] != 'Asia/Manila' || json['scope_count'] != 1) {
      throw const FormatException('Unsupported dashboard contract.');
    }
    final association = _object(json['association']);
    if (association['status'] != 'active') throw const FormatException('Association is unavailable.');
    final applications = json['applications'] == null ? null : _object(json['applications']);
    final charts = json['charts'];
    if (charts is! List || charts.length > 4) throw const FormatException('Invalid dashboard charts.');
    return AssociationDashboard(
      associationId: _text(association['id']), name: _text(association['name']),
      municipality: _text(association['municipality']), status: _text(association['status']),
      generatedAt: _date(json['generated_at']),
      metrics: _rows(json['metrics'], 16).map(DashboardMetric.fromJson).toList(growable: false),
      charts: charts.map((chart) => chart == null ? null : DashboardChart.fromJson(_object(chart))).toList(growable: false),
      projects: json['recent_projects'] == null ? null : _rows(json['recent_projects'], 6).map(DashboardProject.fromJson).toList(growable: false),
      activities: json['activities'] == null ? null : _rows(json['activities'], 6).map(DashboardActivity.fromJson).toList(growable: false),
      applications: applications == null ? null : {for (final status in ['pending', 'approved', 'rejected']) status: _count(applications[status])},
      training: json['training'] == null ? null : DashboardTraining.fromJson(_object(json['training'])),
      monitoring: json['monitoring'] == null ? null : DashboardMonitoring.fromJson(_object(json['monitoring'])),
    );
  }
}

Map<String, dynamic> _object(dynamic value) {
  if (value is! Map<String, dynamic>) throw const FormatException('Invalid dashboard object.');
  return value;
}

List<Map<String, dynamic>> _rows(dynamic value, int limit) {
  if (value is! List || value.length > limit) throw const FormatException('Invalid dashboard list.');
  return value.map(_object).toList(growable: false);
}

String _text(dynamic value) {
  if (value is! String || value.trim().isEmpty || value.length > 1000) throw const FormatException('Invalid dashboard text.');
  return value;
}

int _count(dynamic value) {
  if (value is! int || value < 0 || value > 9007199254740991) throw const FormatException('Invalid dashboard count.');
  return value;
}

DateTime _date(dynamic value) {
  final date = value is String ? DateTime.tryParse(value) : null;
  if (date == null) throw const FormatException('Invalid dashboard timestamp.');
  return date;
}
