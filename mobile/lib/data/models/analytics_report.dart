class AnalyticsReport {
  const AnalyticsReport({
    required this.title,
    required this.generatedAt,
    required this.timezone,
    required this.scope,
    required this.scopeBasis,
    required this.empty,
    required this.notes,
    required this.sections,
  });

  static const version = 'assocmap.reports.v1';
  final String title;
  final String generatedAt;
  final String timezone;
  final List<String> scope;
  final String scopeBasis;
  final bool empty;
  final List<String> notes;
  final List<AnalyticsSection> sections;

  factory AnalyticsReport.fromJson(Map<String, dynamic> json) {
    if (json['version'] != version || json['empty'] is! bool) {
      throw const FormatException('Unsupported report contract.');
    }
    final meta = _object(json['meta']);
    if (meta['timezone'] != 'Asia/Manila' || meta['currency'] != 'PHP') {
      throw const FormatException('Unsupported report timezone.');
    }
    final generated = _text(meta['generated_at']);
    DateTime.parse(generated);
    final sections = _list(json['sections'], 25)
        .map((value) => AnalyticsSection.fromJson(_object(value)))
        .toList(growable: false);
    return AnalyticsReport(
      title: _text(json['title']),
      generatedAt: generated,
      timezone: _text(meta['timezone']),
      scope: _strings(meta['scope'], 1000),
      scopeBasis: _text(meta['scope_basis']),
      empty: json['empty'] as bool,
      notes: _strings(json['notes'], 50),
      sections: sections,
    );
  }
}

class AnalyticsSection {
  const AnalyticsSection({
    required this.title,
    required this.columns,
    required this.rows,
    required this.measures,
    required this.notes,
    required this.chart,
  });
  final String title;
  final List<AnalyticsColumn> columns;
  final List<Map<String, dynamic>> rows;
  final List<AnalyticsMeasure> measures;
  final List<String> notes;
  final Map<String, dynamic> chart;

  factory AnalyticsSection.fromJson(Map<String, dynamic> json) {
    final columns = _list(json['columns'], 12).map((value) {
      final column = _object(value);
      final type = _text(column['type']);
      if (!['text', 'integer', 'decimal', 'date'].contains(type)) {
        throw const FormatException('Invalid report column.');
      }
      return AnalyticsColumn(_text(column['key']), _text(column['label']), type);
    }).toList(growable: false);
    if (columns.isEmpty || columns.map((c) => c.key).toSet().length != columns.length) {
      throw const FormatException('Invalid report columns.');
    }
    final rows = _list(json['rows'], 5000).map((value) {
      final row = _object(value);
      if (row.length != columns.length) {
        throw const FormatException('Invalid report row.');
      }
      for (final column in columns) {
        if (!row.containsKey(column.key)) {
          throw const FormatException('Missing report cell.');
        }
        final cell = row[column.key];
        if (cell == null) continue;
        final valid = switch (column.type) {
          'integer' => cell is int && cell >= 0,
          'decimal' => cell is String && RegExp(r'^\d{1,18}\.\d{2}$').hasMatch(cell),
          'date' => cell is String && DateTime.tryParse(cell) != null,
          _ => cell is String && cell.length <= 1000,
        };
        if (!valid) throw const FormatException('Invalid report cell.');
      }
      return row;
    }).toList(growable: false);
    final chart = _object(json['chart']);
    if (chart.isNotEmpty &&
        (!columns.any((c) => c.key == chart['label_key']) ||
            !columns.any((c) => c.key == chart['value_key'] && ['integer', 'decimal'].contains(c.type)) ||
            chart['title'] is! String || chart['unit'] is! String)) {
      throw const FormatException('Invalid report chart.');
    }
    if (chart.isNotEmpty && rows.any((row) => row[chart['value_key']] == null)) {
      throw const FormatException('Missing chart observation.');
    }
    final measures = _list(json['measures'], 15).map((value) {
      final measure = _object(value);
      final raw = measure['value'];
      if (raw is int && raw < 0) throw const FormatException('Invalid report measure.');
      if (raw != null && raw is! int &&
          !(raw is String && RegExp(r'^\d{1,18}\.\d{2}$').hasMatch(raw))) {
        throw const FormatException('Invalid report measure.');
      }
      return AnalyticsMeasure(_text(measure['label']), raw?.toString());
    }).toList(growable: false);
    return AnalyticsSection(title: _text(json['title']), columns: columns,
        rows: rows, measures: measures, notes: _strings(json['notes'], 30), chart: chart);
  }
}

class AnalyticsColumn {
  const AnalyticsColumn(this.key, this.label, this.type);
  final String key;
  final String label;
  final String type;
}

class AnalyticsMeasure {
  const AnalyticsMeasure(this.label, this.value);
  final String label;
  final String? value;
}

Map<String, dynamic> _object(dynamic value) {
  if (value is! Map<String, dynamic>) {
    throw const FormatException('Invalid report object.');
  }
  return value;
}

List<dynamic> _list(dynamic value, int max) {
  if (value is! List || value.length > max) {
    throw const FormatException('Invalid report list.');
  }
  return value;
}

String _text(dynamic value) {
  if (value is! String || value.isEmpty || value.length > 2000) {
    throw const FormatException('Invalid report text.');
  }
  return value;
}

List<String> _strings(dynamic value, int max) =>
    _list(value, max).map(_text).toList(growable: false);
