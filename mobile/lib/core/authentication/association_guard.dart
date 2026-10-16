import 'package:flutter/material.dart';

import '../../app/configuration/app_config.dart';
import '../../features/account/association_access_screen.dart';
import '../../features/account/association_home_screen.dart';
import '../../data/repositories/analytics_repository.dart';
import '../../data/repositories/dashboard_repository.dart';
import 'auth_controller.dart';

class AssociationGuard extends StatelessWidget {
  const AssociationGuard({
    super.key,
    required this.config,
    required this.controller,
    this.analytics = const UnavailableAnalyticsRepository(),
    this.dashboard = const UnavailableDashboardRepository(),
  });
  final AppConfig config;
  final AuthController controller;
  final AnalyticsRepository analytics;
  final DashboardRepository dashboard;

  @override
  Widget build(BuildContext context) => ListenableBuilder(
    listenable: controller,
    builder: (context, _) {
      final session = controller.session;
      if (session == null) {
        return AssociationAccessScreen(config: config, controller: controller);
      }
      return AssociationHomeScreen(
        key: ValueKey('${session.userId}:${session.association?.id}'),
        config: config,
        controller: controller,
        session: session,
        analytics: analytics,
        dashboard: dashboard,
      );
    },
  );
}
