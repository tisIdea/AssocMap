import 'package:flutter/material.dart';

import '../../app/configuration/app_config.dart';
import '../../core/authentication/auth_failure.dart';
import '../../core/widgets/feedback_panel.dart';

class AssociationDeniedScreen extends StatelessWidget {
  const AssociationDeniedScreen({
    super.key,
    required this.config,
    required this.failure,
    required this.onSignIn,
    required this.onPublicMap,
  });
  final AppConfig config;
  final AuthFailure failure;
  final VoidCallback onSignIn;
  final VoidCallback onPublicMap;

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Mobile access unavailable')),
    body: SafeArea(
      child: Column(
        children: [
          Expanded(
            child: FeedbackPanel(
              icon: Icons.lock_outline,
              title: failure.code == AuthFailureCode.wrongRole
                  ? 'Association Account required'
                  : 'Active association link required',
              message: failure.message,
              actionLabel: 'Back to sign-in',
              onAction: onSignIn,
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(16),
            child: OutlinedButton.icon(
              onPressed: onPublicMap,
              icon: const Icon(Icons.map_outlined),
              label: const Text('Open public map'),
            ),
          ),
        ],
      ),
    ),
  );
}
