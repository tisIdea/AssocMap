import 'package:flutter/material.dart';

import '../../app/configuration/app_config.dart';
import '../../features/account/association_access_screen.dart';
import '../../features/account/association_home_screen.dart';
import '../../data/repositories/analytics_repository.dart';
import '../../data/repositories/dashboard_repository.dart';
import '../../data/repositories/member_applications_repository.dart';
import '../../data/models/auth_session.dart';
import 'auth_controller.dart';

class AssociationGuard extends StatefulWidget {
  const AssociationGuard({
    super.key,
    required this.config,
    required this.controller,
    this.analytics = const UnavailableAnalyticsRepository(),
    this.dashboard = const UnavailableDashboardRepository(),
    this.memberApplications = const UnavailableMemberApplicationsRepository(),
  });
  final AppConfig config;
  final AuthController controller;
  final AnalyticsRepository analytics;
  final DashboardRepository dashboard;
  final MemberApplicationsRepository memberApplications;

  @override
  State<AssociationGuard> createState() => _AssociationGuardState();
}

class _AssociationGuardState extends State<AssociationGuard> {
  AuthSession? _lastSession;

  @override
  void didUpdateWidget(covariant AssociationGuard oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.controller != widget.controller) _lastSession = null;
  }

  @override
  Widget build(BuildContext context) => ListenableBuilder(
    listenable: widget.controller,
    builder: (context, _) {
      final current = widget.controller.session;
      if (current != null) {
        _lastSession = current;
      } else if (!widget.controller.busy) {
        _lastSession = null;
      }
      final session = _lastSession;
      if (session == null) {
        return AssociationAccessScreen(
          config: widget.config,
          controller: widget.controller,
        );
      }
      // Retain transient form state during reauthorization, with no visible or interactive private UI.
      return Stack(
        fit: StackFit.expand,
        children: [
          Offstage(
            offstage: current == null,
            child: ExcludeFocus(
              excluding: current == null,
              child: TickerMode(
                enabled: current != null,
                child: AssociationHomeScreen(
                  key: ValueKey('${session.userId}:${session.association?.id}'),
                  config: widget.config,
                  controller: widget.controller,
                  session: session,
                  analytics: widget.analytics,
                  dashboard: widget.dashboard,
                  memberApplications: widget.memberApplications,
                ),
              ),
            ),
          ),
          if (current == null)
            const Scaffold(
              body: Center(
                child: CircularProgressIndicator(
                  semanticsLabel: 'Verifying Association Account access',
                ),
              ),
            ),
        ],
      );
    },
  );
}
