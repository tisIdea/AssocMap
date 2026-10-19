import 'package:flutter/material.dart';

import 'app/app.dart';
import 'app/configuration/app_config.dart';
import 'data/repositories/repository_factory.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  const config = AppConfig.fromEnvironment();
  runApp(
    AssocMapApp(
      config: config,
      publicProjects: RepositoryFactory.publicProjects(config),
    ),
  );
}
