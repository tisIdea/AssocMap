import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/app/theme/app_theme.dart';
import 'package:mobile/data/models/analytics_report.dart';
import 'package:mobile/data/repositories/analytics_repository.dart';
import 'package:mobile/features/reports/association_analytics_screen.dart';

import 'analytics_report_test.dart' show report;

class _Reports implements AnalyticsRepository {
  @override
  Future<AnalyticsPeriods> periods() async =>
      AnalyticsPeriods(years: [2026], programYears: [2026]);
  @override
  Future<AnalyticsReport> load(Map<String, String> filters) async {
    final data = report();
    final section = (data['sections'] as List).first as Map<String, dynamic>;
    section['columns'] = [
      {'key': 'project', 'label': 'Project', 'type': 'text'},
      {'key': 'gross', 'label': 'Gross income', 'type': 'decimal'},
    ];
    section['rows'] = [
      for (var i = 0; i < 26; i++)
        {'project': 'Project ${i + 1}', 'gross': i == 0 ? null : '0.00'},
    ];
    return AnalyticsReport.fromJson(data);
  }
}

void main() {
  WidgetController.hitTestWarningShouldBeFatal = true;
  testWidgets(
    'Phone report rows preserve missing versus zero values and all pages at large text',
    (tester) async {
      tester.view.physicalSize = const Size(320, 640);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          builder: (context, child) => MediaQuery(
            data: MediaQuery.of(
              context,
            ).copyWith(textScaler: TextScaler.linear(2)),
            child: child!,
          ),
          home: Scaffold(
            body: AssociationAnalyticsScreen(
              repository: _Reports(),
              initialReportType: 'production',
              showReportTypeSelector: false,
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      final records = find.text('Recorded income');
      await tester.scrollUntilVisible(
        records,
        300,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.pumpAndSettle();
      expect(find.byType(DataTable), findsNothing);
      expect(find.text('Project 1'), findsOneWidget);
      expect(find.text('Unobserved / N/A'), findsOneWidget);
      expect(find.text('0.00'), findsWidgets);
      await tester.ensureVisible(find.text('Next'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Next'));
      await tester.pumpAndSettle();
      expect(find.text('Project 26'), findsOneWidget);
      expect(find.text('Project 1'), findsNothing);
      expect(tester.takeException(), isNull);
    },
  );
}
