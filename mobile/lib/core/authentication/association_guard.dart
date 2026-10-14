import 'package:flutter/material.dart';

import '../../app/configuration/app_config.dart';
import '../../features/account/association_access_screen.dart';
import '../../features/account/association_home_screen.dart';
import 'auth_controller.dart';

class AssociationGuard extends StatelessWidget {
  const AssociationGuard({
    super.key,
    required this.config,
    required this.controller,
  });
  final AppConfig config;
  final AuthController controller;

  @override
  Widget build(BuildContext context) => ListenableBuilder(
    listenable: controller,
    builder: (context, _) {
      final session = controller.session;
      if (session == null) {
        return AssociationAccessScreen(config: config, controller: controller);
      }
      return AssociationHomeScreen(
        config: config,
        controller: controller,
        session: session,
      );
    },
  );
}
