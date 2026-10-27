import 'package:flutter/material.dart';
import '../../app/configuration/app_config.dart';
import '../../app/routing/app_routes.dart';
import '../../app/theme/app_theme.dart';
import '../../core/authentication/auth_controller.dart';
import '../../core/widgets/assocmap_widgets.dart';
import '../../data/models/auth_session.dart';
import '../../data/repositories/analytics_repository.dart';
import '../../data/repositories/dashboard_repository.dart';
import '../reports/association_analytics_screen.dart';
import 'association_dashboard_screen.dart';
import 'association_sections.dart';
import 'member_registration_screen.dart';

class AssociationHomeScreen extends StatefulWidget {
  const AssociationHomeScreen({
    super.key,
    required this.config,
    required this.controller,
    required this.session,
    this.analytics = const UnavailableAnalyticsRepository(),
    this.dashboard = const UnavailableDashboardRepository(),
  });
  final AppConfig config;
  final AuthController controller;
  final AuthSession session;
  final AnalyticsRepository analytics;
  final DashboardRepository dashboard;
  @override
  State<AssociationHomeScreen> createState() => _AssociationHomeScreenState();
}

class _AssociationHomeScreenState extends State<AssociationHomeScreen> {
  int _tab = 0;
  String _section = 'home';
  void _open(String section) => setState(() {
    _section = section;
    _tab = switch (section) {
      'members' => 1,
      'projects' || 'trainings' || 'production' => 2,
      'register' => 3,
      'profile' => 4,
      _ => 0,
    };
  });
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
    navigator.pushNamedAndRemoveUntil(AppRoutes.publicMap, (_) => false);
    await signOut;
  }

  Widget _account() => PageContent(
    children: [
      Container(
        padding: const EdgeInsets.all(24),
        decoration: BoxDecoration(
          color: AppTheme.blue,
          borderRadius: BorderRadius.circular(18),
        ),
        child: Column(
          children: [
            const AssocMapMark(size: 64),
            const SizedBox(height: 16),
            Text(
              widget.session.displayName,
              textAlign: TextAlign.center,
              style: const TextStyle(
                color: Colors.white,
                fontSize: 22,
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(height: 6),
            const Text(
              'Association Account',
              style: TextStyle(color: Colors.white),
            ),
          ],
        ),
      ),
      const SectionHeading('Account information'),
      InformationCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text(
              'Association',
              style: TextStyle(fontSize: 12, color: AppTheme.muted),
            ),
            const SizedBox(height: 6),
            Text(widget.session.association!.name),
            const Divider(),
            const Text(
              'Access',
              style: TextStyle(fontSize: 12, color: AppTheme.muted),
            ),
            const SizedBox(height: 6),
            const Text('Association Account'),
            const Divider(),
            const Text(
              'Email',
              style: TextStyle(fontSize: 12, color: AppTheme.muted),
            ),
            const SizedBox(height: 6),
            const Text('Not available in account details'),
          ],
        ),
      ),
      const SizedBox(height: 16),
      const ReadOnlyNotice(
        message:
            'This account is linked to an association. Membership records and account access are managed separately.',
        icon: Icons.info_outline,
      ),
      const SizedBox(height: 18),
      OutlinedButton.icon(
        onPressed: _logout,
        style: OutlinedButton.styleFrom(
          foregroundColor: Theme.of(context).colorScheme.error,
          side: BorderSide(color: Theme.of(context).colorScheme.error),
        ),
        icon: const Icon(Icons.logout),
        label: const Text('Sign out'),
      ),
      const SizedBox(height: 24),
      const Center(
        child: Text('AssocMap', style: TextStyle(color: AppTheme.muted)),
      ),
    ],
  );
  @override
  Widget build(BuildContext context) {
    final title = switch (_section) {
      'association' => 'Association profile',
      'members' => 'Members & registrations',
      'projects' || 'trainings' => 'Programs',
      'production' => 'Production monitoring',
      'register' => 'New Member Registration',
      'profile' => 'Profile',
      'reports' => 'Reports & Analytics',
      _ => 'AssocMap',
    };
    final blueHeader = ['production', 'register', 'profile'].contains(_section);
    final Widget body = switch (_section) {
      'home' => AssociationDashboardScreen(
        repository: widget.dashboard,
        session: widget.session,
        onSessionExpired: widget.controller.expireSession,
        onOpenReports: () => _open('reports'),
        onOpenMap: () => Navigator.of(context).pushNamed(AppRoutes.publicMap),
        onOpenSection: _open,
      ),
      'association' ||
      'members' ||
      'projects' ||
      'trainings' => AssociationSections(
        key: ValueKey(_section),
        section: _section,
        repository: widget.dashboard,
        session: widget.session,
        onSessionExpired: widget.controller.expireSession,
        onOpenSection: _open,
      ),
      'production' || 'reports' => AssociationAnalyticsScreen(
        key: ValueKey(_section),
        repository: widget.analytics,
        onSessionExpired: widget.controller.expireSession,
        showReportTypeSelector: _section != 'production',
        initialReportType: _section == 'production'
            ? 'production'
            : 'comprehensive',
      ),
      'register' => MemberRegistrationScreen(
        associationName: widget.session.association!.name,
      ),
      _ => _account(),
    };
    return PopScope<Object?>(
      canPop: _section == 'home',
      onPopInvokedWithResult: (didPop, result) {
        if (!didPop && mounted && _section != 'home') _open('home');
      },
      child: Scaffold(
        appBar: AppBar(
          toolbarHeight: MediaQuery.textScalerOf(
            context,
          ).scale(56).clamp(56, 112),
          automaticallyImplyLeading: false,
          backgroundColor: blueHeader ? AppTheme.blue : Colors.white,
          foregroundColor: blueHeader ? Colors.white : AppTheme.ink,
          leading: _section == 'home'
              ? null
              : IconButton(
                  tooltip: 'Back to dashboard',
                  onPressed: () => _open('home'),
                  icon: const Icon(Icons.arrow_back),
                ),
          title: _section == 'home'
              ? const AssocMapBrand()
              : Text(
                  title,
                  maxLines: 2,
                  style: TextStyle(
                    fontSize: 17,
                    color: blueHeader ? Colors.white : AppTheme.ink,
                  ),
                ),
          actions: _section != 'home'
              ? null
              : [
                  IconButton(
                    tooltip: 'Open public map',
                    icon: const Icon(Icons.public),
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
        body: SafeArea(child: body),
        bottomNavigationBar: WorkspaceNavigation(
          selected: _tab,
          onSelected: (value) => _open(
            ['home', 'members', 'projects', 'register', 'profile'][value],
          ),
        ),
      ),
    );
  }
}
