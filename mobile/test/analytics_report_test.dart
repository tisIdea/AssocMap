import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/data/models/analytics_report.dart';
import 'package:mobile/data/repositories/analytics_repository.dart';

Map<String, dynamic> report() => {
  'version': AnalyticsReport.version,
  'title': 'Synthetic income report',
  'empty': false,
  'notes': <String>[],
  'meta': {
    'generated_at': '2026-10-15T12:00:00+08:00', 'timezone': 'Asia/Manila', 'currency': 'PHP',
    'scope': ['Synthetic association'], 'scope_basis': 'Synthetic fixture',
  },
  'sections': [{
    'title': 'Recorded income', 'notes': <String>[], 'chart': <String, dynamic>{},
    'columns': [{'key': 'gross', 'label': 'Gross income', 'type': 'decimal'}],
    'rows': [{'gross': null}, {'gross': '0.00'}],
    'measures': [{'label': 'Recorded gross income', 'value': '0.00'}],
  }],
};

void main() {
  test('missing and recorded zero amounts are preserved without calculation', () {
    final parsed = AnalyticsReport.fromJson(report());
    expect(parsed.sections.first.rows.first['gross'], isNull);
    expect(parsed.sections.first.rows.last['gross'], '0.00');
    expect(parsed.sections.first.measures.first.value, '0.00');
  });

  test('unsupported contracts fail closed', () {
    final data = report()..['version'] = 'untrusted-version';
    expect(() => AnalyticsReport.fromJson(data), throwsFormatException);
  });

  test('default repository never returns fabricated organizational analytics', () async {
    await expectLater(const UnavailableAnalyticsRepository().load({'report_type': 'members'}),
      throwsA(isA<AnalyticsFailure>()));
  });
}
