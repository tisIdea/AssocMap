import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/data/models/association_dashboard.dart';
import 'package:mobile/data/repositories/dashboard_repository.dart';

Map<String, dynamic> dashboard() => {
  'version': AssociationDashboard.version, 'role': 'association_account',
  'timezone': 'Asia/Manila', 'generated_at': '2026-10-15T12:00:00+08:00', 'scope_count': 1,
  'association': {'id': '33333333-3333-4333-8333-333333333333', 'name': 'Synthetic association', 'municipality': 'Synthetic town', 'status': 'active'},
  'metrics': [
    {'key': 'members_total', 'label': 'Official members', 'value': 0, 'note': 'Synthetic test count'},
    {'key': 'training_records', 'label': 'Training records', 'value': null, 'note': 'Synthetic unavailable widget'},
  ],
  'charts': <dynamic>[], 'recent_projects': <dynamic>[], 'activities': null,
  'applications': {'pending': 0, 'approved': 0, 'rejected': 0}, 'training': null, 'monitoring': null,
};

void main() {
  test('recorded zero stays distinct from unavailable widgets', () {
    final parsed = AssociationDashboard.fromJson(dashboard());
    expect(parsed.metrics.first.value, 0);
    expect(parsed.metrics.last.value, isNull);
    expect(parsed.projects, isEmpty);
    expect(parsed.activities, isNull);
  });

  test('wrong role, multiple associations and negative counters fail closed', () {
    expect(() => AssociationDashboard.fromJson(dashboard()..['role'] = 'system_admin'), throwsFormatException);
    expect(() => AssociationDashboard.fromJson(dashboard()..['scope_count'] = 2), throwsFormatException);
    final data = dashboard();
    (data['metrics'] as List).first['value'] = -1;
    expect(() => AssociationDashboard.fromJson(data), throwsFormatException);
  });

  test('unconnected mobile repository never fabricates live records', () async {
    await expectLater(const UnavailableDashboardRepository().load(), throwsA(isA<DashboardFailure>()));
  });
}
