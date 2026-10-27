import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'dart:async';

import '../core/widgets/feedback_panel.dart';
import '../core/authentication/auth_controller.dart';
import '../core/authentication/association_guard.dart';
import '../data/repositories/auth_repository.dart';
import '../data/repositories/analytics_repository.dart';
import '../data/repositories/dashboard_repository.dart';
import '../data/repositories/repository_factory.dart';
import '../data/repositories/public_projects_repository.dart';
import '../data/remote/api_auth_repository.dart';
import '../data/remote/api_dashboard_repository.dart';
import '../data/remote/api_analytics_repository.dart';
import '../features/public_map/public_map_screen.dart';
import 'configuration/app_config.dart';
import 'routing/app_routes.dart';
import 'theme/app_theme.dart';

class AssocMapApp extends StatefulWidget {
  const AssocMapApp({
    super.key,
    required this.config,
    required this.publicProjects,
    this.authentication,
    this.analytics,
    this.dashboard,
    this.tileProviderFactory,
  });

  final AppConfig config;
  final PublicProjectsRepository publicProjects;
  final AuthRepository? authentication;
  final AnalyticsRepository? analytics;
  final DashboardRepository? dashboard;
  final TileProvider Function()? tileProviderFactory;

  @override
  State<AssocMapApp> createState() => _AssocMapAppState();
}

class _AssocMapAppState extends State<AssocMapApp> with WidgetsBindingObserver {
  late final AuthController _auth;
  late final AuthRepository _repository;
  late final AnalyticsRepository _analytics;
  late final DashboardRepository _dashboard;

  @override
  void initState() {
    super.initState();
    _repository =
        widget.authentication ??
        RepositoryFactory.authentication(widget.config);
    final repository = _repository;
    _analytics =
        widget.analytics ??
        (repository is ApiAuthRepository
            ? ApiAnalyticsRepository(
                config: widget.config,
                read: repository.read,
              )
            : const UnavailableAnalyticsRepository());
    _dashboard =
        widget.dashboard ??
        (repository is ApiAuthRepository
            ? ApiDashboardRepository(
                config: widget.config,
                read: repository.read,
              )
            : const UnavailableDashboardRepository());
    _auth = AuthController(repository: _repository, mode: widget.config.mode);
    WidgetsBinding.instance.addObserver(this);
    unawaited(_auth.restoreSession());
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _auth.checkSessionValidity();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _auth.dispose();
    if (_repository is ApiAuthRepository) unawaited(_repository.dispose());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => MaterialApp(
    title: 'AssocMap',
    debugShowCheckedModeBanner: false,
    theme: AppTheme.light,
    initialRoute: AppRoutes.home,
    routes: {
      AppRoutes.home: (_) => PublicMapScreen(
        config: widget.config,
        repository: widget.publicProjects,
        tileProvider: widget.tileProviderFactory?.call(),
      ),
      AppRoutes.publicMap: (_) => PublicMapScreen(
        config: widget.config,
        repository: widget.publicProjects,
        tileProvider: widget.tileProviderFactory?.call(),
      ),
      AppRoutes.associationAccess: (_) => AssociationGuard(
        config: widget.config,
        controller: _auth,
        analytics: _analytics,
        dashboard: _dashboard,
      ),
      AppRoutes.associationHome: (_) => AssociationGuard(
        config: widget.config,
        controller: _auth,
        analytics: _analytics,
        dashboard: _dashboard,
      ),
    },
    onUnknownRoute: (_) => MaterialPageRoute<void>(
      builder: (context) => Scaffold(
        appBar: AppBar(title: const Text('Page unavailable')),
        body: FeedbackPanel(
          icon: Icons.lock_outline,
          title: 'This page is unavailable',
          message:
              'This page could not be found. Return to the public map to continue.',
          actionLabel: 'Return home',
          onAction: () => Navigator.of(
            context,
          ).pushNamedAndRemoveUntil(AppRoutes.home, (_) => false),
        ),
      ),
    ),
  );
}
