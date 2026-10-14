import 'package:flutter/material.dart';

import '../../app/configuration/app_config.dart';
import '../../app/routing/app_routes.dart';
import '../../app/theme/app_theme.dart';
import '../../core/widgets/data_mode_banner.dart';

class WelcomeScreen extends StatelessWidget {
  const WelcomeScreen({super.key, required this.config});
  final AppConfig config;

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('AssocMap')),
    body: SafeArea(
      child: Column(
        children: [
          DataModeBanner(mode: config.mode),
          Expanded(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(24),
              child: Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 780),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const SizedBox(height: 20),
                      Container(
                        padding: const EdgeInsets.all(18),
                        decoration: BoxDecoration(
                          color: AppTheme.teal.withValues(alpha: .1),
                          borderRadius: BorderRadius.circular(22),
                        ),
                        child: const Icon(
                          Icons.hub_outlined,
                          color: AppTheme.teal,
                          size: 42,
                        ),
                      ),
                      const SizedBox(height: 24),
                      Text(
                        'Community livelihoods,\nconnected by place.',
                        style: Theme.of(context).textTheme.headlineMedium
                            ?.copyWith(
                              fontWeight: FontWeight.w700,
                              color: AppTheme.ink,
                            ),
                      ),
                      const SizedBox(height: 16),
                      const Text(
                        'Explore public project locations and discover association summaries. '
                        'The public map is available without an account.',
                        style: TextStyle(fontSize: 16, height: 1.6),
                      ),
                      const SizedBox(height: 28),
                      _WelcomeCard(
                        icon: Icons.map_outlined,
                        title: 'Public GIS map',
                        subtitle:
                            'Explore locations, sample boundaries, and public summaries.',
                        action: 'Explore map',
                        onPressed: () => Navigator.of(
                          context,
                        ).pushNamed(AppRoutes.publicMap),
                      ),
                      const SizedBox(height: 16),
                      _WelcomeCard(
                        icon: Icons.groups_outlined,
                        title: 'Association Account',
                        subtitle:
                            'An association-linked account. Member logins are not assumed.',
                        action: 'Association access',
                        onPressed: () => Navigator.of(
                          context,
                        ).pushNamed(AppRoutes.associationAccess),
                      ),
                      const SizedBox(height: 24),
                      const Text(
                        'Development preview',
                        style: TextStyle(fontWeight: FontWeight.w700),
                      ),
                      const SizedBox(height: 6),
                      const Text(
                        'The offline GIS demonstration uses fictional associations and geometries. '
                        'It does not represent actual organizational records. Association sign-in uses synthetic demo identities; real sign-in is pending.',
                        style: TextStyle(height: 1.5),
                      ),
                      const SizedBox(height: 16),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    ),
  );
}

class _WelcomeCard extends StatelessWidget {
  const _WelcomeCard({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.action,
    required this.onPressed,
  });
  final IconData icon;
  final String title;
  final String subtitle;
  final String action;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) => Card(
    color: Colors.white,
    child: Padding(
      padding: const EdgeInsets.all(22),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(icon, color: AppTheme.teal),
              const SizedBox(width: 12),
              Expanded(
                child: Text(
                  title,
                  style: Theme.of(context).textTheme.titleLarge,
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Text(subtitle, style: const TextStyle(height: 1.5)),
          const SizedBox(height: 18),
          FilledButton(onPressed: onPressed, child: Text(action)),
        ],
      ),
    ),
  );
}
