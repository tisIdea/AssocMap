import 'package:flutter/material.dart';
import '../../core/widgets/feedback_panel.dart';
import '../../data/models/association_dashboard.dart';
import '../../data/models/auth_session.dart';
import '../../data/repositories/dashboard_repository.dart';

class AssociationDataView extends StatefulWidget {
  const AssociationDataView({
    super.key,
    required this.repository,
    required this.session,
    required this.onSessionExpired,
    required this.builder,
  });
  final DashboardRepository repository;
  final AuthSession session;
  final VoidCallback onSessionExpired;
  final Widget Function(
    AssociationDashboard data,
    Future<void> Function() refresh,
  )
  builder;
  @override
  State<AssociationDataView> createState() => _AssociationDataViewState();
}

class _AssociationDataViewState extends State<AssociationDataView> {
  AssociationDashboard? _data;
  DashboardFailure? _failure;
  int _operation = 0;
  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant AssociationDataView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.repository != widget.repository ||
        oldWidget.session != widget.session) {
      _load();
    }
  }

  Future<void> _load() async {
    final operation = ++_operation;
    setState(() {
      _data = null;
      _failure = null;
    });
    try {
      if (widget.session.isDemo) {
        throw const DashboardFailure(DashboardFailureKind.integration);
      }
      if (widget.session.association?.isValid != true) {
        throw const DashboardFailure(DashboardFailureKind.denied);
      }
      final data = await widget.repository.load();
      if (!mounted || operation != _operation) return;
      if (data.associationId != widget.session.association?.id) {
        throw const DashboardFailure(DashboardFailureKind.denied);
      }
      setState(() => _data = data);
    } on DashboardFailure catch (error) {
      if (!mounted || operation != _operation) return;
      setState(() => _failure = error);
      if (error.kind == DashboardFailureKind.expired) widget.onSessionExpired();
    } catch (_) {
      if (mounted && operation == _operation) {
        setState(
          () => _failure = const DashboardFailure(
            DashboardFailureKind.unavailable,
          ),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final failure = _failure;
    if (failure != null) {
      return FeedbackPanel(
        icon: Icons.cloud_off_outlined,
        title: failure.kind == DashboardFailureKind.integration
            ? 'Dashboard connection unavailable'
            : 'Dashboard unavailable',
        message: failure.message,
        actionLabel: 'Retry',
        onAction: _load,
      );
    }
    final data = _data;
    return data == null
        ? const Center(
            child: CircularProgressIndicator(
              semanticsLabel: 'Loading association records',
            ),
          )
        : widget.builder(data, _load);
  }
}
