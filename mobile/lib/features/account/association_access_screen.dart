import 'package:flutter/material.dart';
import '../../app/configuration/app_config.dart';
import '../../app/routing/app_routes.dart';
import '../../app/theme/app_theme.dart';
import '../../core/authentication/auth_controller.dart';
import '../../core/authentication/auth_failure.dart';
import '../../core/widgets/assocmap_widgets.dart';
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
  bool _obscure = true;
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
    widget.controller.cancelSignIn();
    Navigator.of(context).pushReplacementNamed(AppRoutes.publicMap);
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
      return Scaffold(
        appBar: AppBar(
          toolbarHeight: MediaQuery.textScalerOf(
            context,
          ).scale(56).clamp(56, 112),
          backgroundColor: AppTheme.blue,
          foregroundColor: Colors.white,
          leading: IconButton(
            tooltip: 'Back to public map',
            onPressed: _publicMap,
            icon: const Icon(Icons.arrow_back),
          ),
          title: const Text(
            'Association login',
            style: TextStyle(color: Colors.white),
          ),
        ),
        body: SafeArea(
          child: SingleChildScrollView(
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 520),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Container(
                      color: AppTheme.blue,
                      padding: const EdgeInsets.fromLTRB(24, 24, 24, 30),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const AssocMapMark(size: 48),
                          const SizedBox(height: 18),
                          const Text(
                            'Welcome to AssocMap',
                            style: TextStyle(
                              color: Colors.white,
                              fontSize: 26,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                          const SizedBox(height: 10),
                          Text(
                            "Sign in to access your association's projects, activities and production data.",
                            style: TextStyle(
                              color: Colors.white.withValues(alpha: .9),
                              height: 1.5,
                            ),
                          ),
                        ],
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.all(20),
                      decoration: const BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.vertical(
                          top: Radius.circular(24),
                        ),
                      ),
                      child: Form(
                        key: _form,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            if (repository.signInBlocker != null) ...[
                              ReadOnlyNotice(
                                message: repository.signInBlocker!,
                                icon: Icons.info_outline,
                              ),
                              const SizedBox(height: 16),
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
                                labelText: 'Email address',
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
                            const SizedBox(height: 10),
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
                                      ? () =>
                                            setState(() => _obscure = !_obscure)
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
                            const SizedBox(height: 18),
                            if (failure != null) ...[
                              Semantics(
                                liveRegion: true,
                                child: ReadOnlyNotice(
                                  message: failure.message,
                                  icon: Icons.error_outline,
                                ),
                              ),
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
                            FilledButton(
                              key: const ValueKey('sign-in-button'),
                              onPressed: enabled ? _submit : null,
                              child: controller.busy
                                  ? const Wrap(
                                      spacing: 12,
                                      crossAxisAlignment:
                                          WrapCrossAlignment.center,
                                      children: [
                                        SizedBox(
                                          width: 18,
                                          height: 18,
                                          child: CircularProgressIndicator(
                                            strokeWidth: 2,
                                            color: Colors.white,
                                          ),
                                        ),
                                        Text('Signing in...'),
                                      ],
                                    )
                                  : const Text('Log in'),
                            ),
                            const SizedBox(height: 12),
                            OutlinedButton.icon(
                              onPressed: _publicMap,
                              icon: const Icon(Icons.map_outlined),
                              label: const Text('Open public map'),
                            ),
                            const SizedBox(height: 16),
                            const ReadOnlyNotice(
                              message:
                                  'Accounts are provisioned by the system administrator. Account self-registration is not available.',
                              icon: Icons.info_outline,
                            ),
                            const SizedBox(height: 24),
                            const Center(
                              child: Text(
                                'AssocMap',
                                style: TextStyle(color: AppTheme.muted),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      );
    },
  );
}
