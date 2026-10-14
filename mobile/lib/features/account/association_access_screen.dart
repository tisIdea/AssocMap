import 'package:flutter/material.dart';

import '../../app/configuration/app_config.dart';
import '../../app/routing/app_routes.dart';
import '../../core/authentication/auth_controller.dart';
import '../../core/authentication/auth_failure.dart';
import '../../core/widgets/data_mode_banner.dart';
import 'association_denied_screen.dart';

class AssociationAccessScreen extends StatefulWidget {
  const AssociationAccessScreen({
    super.key,
    required this.config,
    required this.controller,
  });
  final AppConfig config;
  final AuthController controller;

  @override
  State<AssociationAccessScreen> createState() =>
      _AssociationAccessScreenState();
}

class _AssociationAccessScreenState extends State<AssociationAccessScreen> {
  final _form = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _passwordFocus = FocusNode();
  String? _demoEmail;
  bool _obscure = true;

  @override
  void initState() {
    super.initState();
    final accounts = widget.controller.repository.demoAccounts;
    if (accounts.isNotEmpty) _demoEmail = accounts.first.email;
  }

  @override
  void dispose() {
    _password.clear();
    _password.dispose();
    _email.dispose();
    _passwordFocus.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (widget.controller.busy || !(_form.currentState?.validate() ?? false)) {
      return;
    }
    FocusScope.of(context).unfocus();
    try {
      await widget.controller.signIn(
        email: _email.text,
        password: _password.text,
      );
    } finally {
      if (mounted) _password.clear();
    }
  }

  void _publicMap() {
    final navigator = Navigator.of(context);
    widget.controller.cancelSignIn();
    navigator.pushReplacementNamed(AppRoutes.publicMap);
  }

  void _fillDemo() {
    final repository = widget.controller.repository;
    if (_demoEmail == null || repository.demoPasswordHint == null) return;
    widget.controller.clearFailure();
    setState(() {
      _email.text = _demoEmail!;
      _password.text = repository.demoPasswordHint!;
    });
  }

  @override
  Widget build(BuildContext context) => ListenableBuilder(
    listenable: widget.controller,
    builder: (context, _) {
      final controller = widget.controller;
      final repository = controller.repository;
      final failure = controller.failure;
      if (failure?.code == AuthFailureCode.wrongRole ||
          failure?.code == AuthFailureCode.invalidAssociationLink) {
        return AssociationDeniedScreen(
          config: widget.config,
          failure: failure!,
          onSignIn: controller.clearFailure,
          onPublicMap: _publicMap,
        );
      }
      final enabled = repository.canAttemptSignIn && !controller.busy;
      final accounts = repository.demoAccounts;
      final hint = repository.demoPasswordHint;
      return Scaffold(
        appBar: AppBar(title: const Text('Association sign-in')),
        body: SafeArea(
          child: Column(
            children: [
              DataModeBanner(mode: widget.config.mode),
              Expanded(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.all(24),
                  child: Center(
                    child: ConstrainedBox(
                      constraints: const BoxConstraints(maxWidth: 520),
                      child: Form(
                        key: _form,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Icon(Icons.groups_outlined, size: 40),
                            const SizedBox(height: 16),
                            Text(
                              'Association Account',
                              style: Theme.of(context).textTheme.headlineSmall,
                            ),
                            const SizedBox(height: 10),
                            const Text(
                              'Sign in with your association-linked account. The public GIS map remains available without login.',
                              style: TextStyle(height: 1.5),
                            ),
                            const SizedBox(height: 20),
                            if (repository.signInBlocker != null) ...[
                              _Notice(
                                message: repository.signInBlocker!,
                                error: true,
                              ),
                              const SizedBox(height: 20),
                            ],
                            if (accounts.isNotEmpty) ...[
                              const _Notice(
                                message:
                                    'DEMO authentication only. Use the synthetic identities below. '
                                    'Do not enter genuine credentials. This demonstration does not provide real account security.',
                              ),
                              const SizedBox(height: 16),
                              DropdownButtonFormField<String>(
                                key: const ValueKey('demo-account-picker'),
                                initialValue: _demoEmail,
                                isExpanded: true,
                                decoration: const InputDecoration(
                                  labelText: 'Synthetic demo scenario',
                                ),
                                items: accounts
                                    .map(
                                      (account) => DropdownMenuItem(
                                        value: account.email,
                                        child: Text(
                                          account.label,
                                          maxLines: 1,
                                          overflow: TextOverflow.ellipsis,
                                        ),
                                      ),
                                    )
                                    .toList(),
                                onChanged: enabled
                                    ? (value) {
                                        controller.clearFailure();
                                        setState(() {
                                          _demoEmail = value;
                                          _email.clear();
                                          _password.clear();
                                        });
                                      }
                                    : null,
                              ),
                              const SizedBox(height: 10),
                              if (hint != null)
                                Text(
                                  'Synthetic demo password: $hint',
                                  style: Theme.of(context).textTheme.bodySmall,
                                ),
                              const SizedBox(height: 10),
                              OutlinedButton(
                                onPressed: enabled ? _fillDemo : null,
                                child: const Text('Fill demo credentials'),
                              ),
                              const SizedBox(height: 20),
                            ],
                            TextFormField(
                              key: const ValueKey('login-email'),
                              controller: _email,
                              enabled: enabled,
                              keyboardType: TextInputType.emailAddress,
                              textInputAction: TextInputAction.next,
                              autocorrect: false,
                              enableSuggestions: false,
                              maxLength: 255,
                              decoration: const InputDecoration(
                                labelText: 'Email',
                                prefixIcon: Icon(Icons.mail_outline),
                              ),
                              validator: (value) {
                                final email = value?.trim() ?? '';
                                if (email.isEmpty) {
                                  return 'Enter your email address.';
                                }
                                if (email.length > 255 ||
                                    !RegExp(
                                      r'^[^@\s]+@[^@\s]+\.[^@\s]+$',
                                    ).hasMatch(email)) {
                                  return 'Enter a valid email address.';
                                }
                                return null;
                              },
                            ),
                            const SizedBox(height: 12),
                            TextFormField(
                              key: const ValueKey('login-password'),
                              controller: _password,
                              focusNode: _passwordFocus,
                              enabled: enabled,
                              obscureText: _obscure,
                              autocorrect: false,
                              enableSuggestions: false,
                              textInputAction: TextInputAction.done,
                              onFieldSubmitted: (_) {
                                if (enabled) _submit();
                              },
                              decoration: InputDecoration(
                                labelText: 'Password',
                                prefixIcon: const Icon(Icons.lock_outline),
                                suffixIcon: IconButton(
                                  tooltip: _obscure
                                      ? 'Show password'
                                      : 'Hide password',
                                  onPressed: enabled
                                      ? () => setState(() {
                                          _obscure = !_obscure;
                                        })
                                      : null,
                                  icon: Icon(
                                    _obscure
                                        ? Icons.visibility_outlined
                                        : Icons.visibility_off_outlined,
                                  ),
                                ),
                              ),
                              validator: (value) =>
                                  value == null || value.isEmpty
                                  ? 'Enter your password.'
                                  : null,
                            ),
                            const SizedBox(height: 16),
                            if (failure != null) ...[
                              _Notice(message: failure.message, error: true),
                              if (failure.code ==
                                  AuthFailureCode.connectionUnavailable)
                                TextButton(
                                  onPressed: () {
                                    controller.clearFailure();
                                    _passwordFocus.requestFocus();
                                  },
                                  child: const Text('Retry'),
                                ),
                              const SizedBox(height: 16),
                            ],
                            SizedBox(
                              width: double.infinity,
                              child: FilledButton(
                                key: const ValueKey('sign-in-button'),
                                onPressed: enabled ? _submit : null,
                                child: controller.busy
                                    ? const Row(
                                        mainAxisSize: MainAxisSize.min,
                                        children: [
                                          SizedBox(
                                            width: 18,
                                            height: 18,
                                            child: CircularProgressIndicator(
                                              strokeWidth: 2,
                                            ),
                                          ),
                                          SizedBox(width: 12),
                                          Text('Signing in…'),
                                        ],
                                      )
                                    : const Text('Sign in'),
                              ),
                            ),
                            const SizedBox(height: 12),
                            SizedBox(
                              width: double.infinity,
                              child: OutlinedButton.icon(
                                onPressed: _publicMap,
                                icon: const Icon(Icons.map_outlined),
                                label: const Text('Open public map'),
                              ),
                            ),
                            const SizedBox(height: 12),
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      );
    },
  );
}

class _Notice extends StatelessWidget {
  const _Notice({required this.message, this.error = false});
  final String message;
  final bool error;

  @override
  Widget build(BuildContext context) => Semantics(
    liveRegion: error,
    child: Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: error
            ? Theme.of(context).colorScheme.errorContainer
            : const Color(0xFFFDF0D4),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Text(message, style: const TextStyle(height: 1.5)),
    ),
  );
}
