import 'package:flutter/material.dart';

import '../../app/configuration/app_config.dart';
import '../../app/routing/app_routes.dart';
import '../../core/authentication/auth_controller.dart';
import '../../core/widgets/data_mode_banner.dart';
import '../../data/models/auth_session.dart';

class AssociationHomeScreen extends StatefulWidget {
  const AssociationHomeScreen({
    super.key,
    required this.config,
    required this.controller,
    required this.session,
  });
  final AppConfig config;
  final AuthController controller;
  final AuthSession session;

  @override
  State<AssociationHomeScreen> createState() => _AssociationHomeScreenState();
}

class _AssociationHomeScreenState extends State<AssociationHomeScreen> {
  int _tab = 0;

  Future<void> _logout() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Sign out?'),
        content: const Text(
          'You can continue using the public GIS map without signing in.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Sign out'),
          ),
        ],
      ),
    );
    if (!mounted || confirmed != true) return;
    final navigator = Navigator.of(context);
    final signOut = widget.controller.signOut();
    navigator.pushNamedAndRemoveUntil(
      AppRoutes.publicMap,
      (route) => route.settings.name == AppRoutes.home,
    );
    await signOut;
  }

  @override
  Widget build(BuildContext context) {
    final session = widget.session;
    return Scaffold(
      appBar: AppBar(
        title: Text(_tab == 0 ? 'Association workspace' : 'Account'),
        actions: [
          IconButton(
            tooltip: 'Open public map',
            icon: const Icon(Icons.map_outlined),
            onPressed: () =>
                Navigator.of(context).pushNamed(AppRoutes.publicMap),
          ),
          IconButton(
            tooltip: 'Sign out',
            icon: const Icon(Icons.logout),
            onPressed: _logout,
          ),
        ],
      ),
      body: SafeArea(
        child: Column(
          children: [
            DataModeBanner(mode: widget.config.mode),
            Expanded(
              child: SingleChildScrollView(
                padding: const EdgeInsets.all(24),
                child: Center(
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 760),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          session.association!.name,
                          style: Theme.of(context).textTheme.headlineSmall,
                        ),
                        const SizedBox(height: 12),
                        Text(
                          'Welcome, ${session.displayName}.',
                          style: const TextStyle(height: 1.5),
                        ),
                        const SizedBox(height: 20),
                        if (_tab == 0) ...[
                          Card(
                            child: Padding(
                              padding: const EdgeInsets.all(20),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  const Icon(Icons.pending_actions_outlined),
                                  const SizedBox(height: 12),
                                  Text(
                                    'Association modules pending',
                                    style: Theme.of(
                                      context,
                                    ).textTheme.titleLarge,
                                  ),
                                  const SizedBox(height: 10),
                                  const Text(
                                    'Association records and operations will appear here once the '
                                    'approved mobile modules and backend contracts are confirmed. '
                                    'This phase provides sign-in and navigation only.',
                                    style: TextStyle(height: 1.5),
                                  ),
                                ],
                              ),
                            ),
                          ),
                          const SizedBox(height: 20),
                          FilledButton.icon(
                            onPressed: () => Navigator.of(
                              context,
                            ).pushNamed(AppRoutes.publicMap),
                            icon: const Icon(Icons.map_outlined),
                            label: const Text('Explore public map'),
                          ),
                        ] else ...[
                          const ListTile(
                            contentPadding: EdgeInsets.zero,
                            leading: Icon(Icons.groups_outlined),
                            title: Text('Association Account'),
                            subtitle: Text(
                              'Association-linked login; not an individual member login',
                            ),
                          ),
                          if (session.isDemo) ...[
                            const ListTile(
                              contentPadding: EdgeInsets.zero,
                              leading: Icon(Icons.science_outlined),
                              title: Text('DEMO session'),
                              subtitle: Text(
                                'Synthetic identity · Ends after 15 minutes or when the app restarts',
                              ),
                            ),
                            const SizedBox(height: 12),
                            OutlinedButton(
                              onPressed: widget.controller.expireSession,
                              child: const Text('Simulate session expiry'),
                            ),
                          ],
                          const SizedBox(height: 20),
                          FilledButton.icon(
                            onPressed: _logout,
                            icon: const Icon(Icons.logout),
                            label: const Text('Sign out'),
                          ),
                        ],
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        onDestinationSelected: (value) => setState(() {
          _tab = value;
        }),
        destinations: const [
          NavigationDestination(
            icon: Icon(Icons.dashboard_outlined),
            label: 'Workspace',
          ),
          NavigationDestination(
            icon: Icon(Icons.person_outline),
            label: 'Account',
          ),
        ],
      ),
    );
  }
}
