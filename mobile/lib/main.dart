import 'package:flutter/material.dart';
import 'package:supabase_flutter/supabase_flutter.dart';

import 'app/app.dart';
import 'app/configuration/app_config.dart';
import 'data/repositories/repository_factory.dart';
import 'data/remote/api_auth_repository.dart';
import 'data/repositories/auth_repository.dart';
import 'core/authentication/secure_auth_storage.dart';
import 'core/authentication/auth_failure.dart';
import 'core/networking/bounded_http_client.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  const config = AppConfig.fromEnvironment();
  AuthRepository? authentication;
  if (config.authenticationConfigured) {
    try {
      final storage = SecureAuthStorage(
        key:
            'assocmap.session.${Uri.parse(config.supabaseUrl).host}.${config.backendBaseUri!.host}',
      );
      await Supabase.initialize(
        url: config.supabaseUrl,
        publishableKey: config.supabasePublishableKey,
        debug: false,
        httpClient: BoundedHttpClient(maxBytes: 1024 * 1024),
        authOptions: FlutterAuthClientOptions(
          localStorage: storage,
          detectSessionInUri: false,
        ),
      );
      authentication = ApiAuthRepository(
        config: config,
        provider: Supabase.instance.client.auth,
        storage: storage,
      );
    } catch (_) {
      // Keep public GIS available when secure authentication cannot initialize.
      authentication = ApiAuthRepository(
        config: config,
        startupFailure: const AuthFailure(AuthFailureCode.storageUnavailable),
      );
    }
  }
  runApp(
    AssocMapApp(
      config: config,
      publicProjects: RepositoryFactory.publicProjects(config),
      authentication:
          authentication ?? RepositoryFactory.authentication(config),
    ),
  );
}
