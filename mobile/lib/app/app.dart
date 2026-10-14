import 'package:flutter/material.dart';

import '../core/widgets/feedback_panel.dart';
import '../core/authentication/auth_controller.dart';
import '../core/authentication/association_guard.dart';
import '../data/repositories/auth_repository.dart';
import '../data/repositories/repository_factory.dart';
import '../data/repositories/public_projects_repository.dart';
import '../features/public_map/public_map_screen.dart';
import '../features/welcome/welcome_screen.dart';
import 'configuration/app_config.dart';
import 'routing/app_routes.dart';
import 'theme/app_theme.dart';

class AssocMapApp extends StatefulWidget {
  const AssocMapApp({
    super.key,
    required this.config,
    required this.publicProjects,
    this.authentication,
  });

  final AppConfig config;
  final PublicProjectsRepository publicProjects;
  final AuthRepository? authentication;

  @override
  State<AssocMapApp> createState() => _AssocMapAppState();
}

class _AssocMapAppState extends State<AssocMapApp> with WidgetsBindingObserver {
  late final AuthController _auth;

  @override
  void initState() {
    super.initState();
    _auth = AuthController(
      repository:
          widget.authentication ??
          RepositoryFactory.authentication(widget.config),
      mode: widget.config.mode,
    );
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _auth.checkSessionValidity();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _auth.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => MaterialApp(
    title: 'AssocMap',
    debugShowCheckedModeBanner: false,
    theme: AppTheme.light,
    initialRoute: AppRoutes.home,
    routes: {
      AppRoutes.home: (_) => WelcomeScreen(config: widget.config),
      AppRoutes.publicMap: (_) => PublicMapScreen(
        config: widget.config,
        repository: widget.publicProjects,
      ),
      AppRoutes.associationAccess: (_) =>
          AssociationGuard(config: widget.config, controller: _auth),
      AppRoutes.associationHome: (_) =>
          AssociationGuard(config: widget.config, controller: _auth),
    },
    onUnknownRoute: (_) => MaterialPageRoute<void>(
      builder: (context) => Scaffold(
        appBar: AppBar(title: const Text('Page unavailable')),
        body: FeedbackPanel(
          icon: Icons.lock_outline,
          title: 'This page is unavailable',
          message:
              'Association workflows will be added after the next phase is reviewed.',
          actionLabel: 'Return home',
          onAction: () => Navigator.of(
            context,
          ).pushNamedAndRemoveUntil(AppRoutes.home, (_) => false),
        ),
      ),
    ),
  );
}
