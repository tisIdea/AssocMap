import 'package:flutter/material.dart';
import '../../core/widgets/assocmap_widgets.dart';
import '../../core/widgets/feedback_panel.dart';
import '../../data/models/member_application.dart';
import '../../data/repositories/member_applications_repository.dart';

class MemberApplicationsScreen extends StatefulWidget {
  const MemberApplicationsScreen({
    super.key,
    required this.repository,
    required this.associationId,
    this.onUnauthorized,
    this.onRegister,
  });
  final MemberApplicationsRepository repository;
  final String associationId;
  final VoidCallback? onUnauthorized, onRegister;
  @override
  State<MemberApplicationsScreen> createState() =>
      _MemberApplicationsScreenState();
}

class _MemberApplicationsScreenState extends State<MemberApplicationsScreen> {
  MemberApplicationPage? _data;
  MemberApplicationFailure? _failure;
  String? _status;
  int _page = 1, _operation = 0;
  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final operation = ++_operation;
    setState(() {
      _data = null;
      _failure = null;
    });
    try {
      final data = await widget.repository.list(page: _page, status: _status);
      if (!mounted || operation != _operation) return;
      if (data.associationId != widget.associationId) {
        throw const MemberApplicationFailure(
          MemberApplicationFailureKind.denied,
        );
      }
      setState(() => _data = data);
    } on MemberApplicationFailure catch (error) {
      if (!mounted || operation != _operation) return;
      setState(() => _failure = error);
      if ([
        MemberApplicationFailureKind.expired,
        MemberApplicationFailureKind.denied,
      ].contains(error.kind)) {
        widget.onUnauthorized?.call();
      }
    } catch (_) {
      if (mounted && operation == _operation) {
        setState(
          () => _failure = const MemberApplicationFailure(
            MemberApplicationFailureKind.unavailable,
          ),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_failure != null) {
      return FeedbackPanel(
        icon: Icons.cloud_off_outlined,
        title: 'Applications unavailable',
        message: _failure!.message,
        actionLabel: 'Retry',
        onAction: _load,
      );
    }
    final data = _data;
    if (data == null) {
      return const Center(
        child: CircularProgressIndicator(
          semanticsLabel: 'Loading member applications',
        ),
      );
    }
    return RefreshIndicator(
      onRefresh: _load,
      child: PageContent(
        children: [
          const SectionHeading('My member applications'),
          const Text(
            'Only applications submitted by this account to its current association are shown. Field Officers handle approvals.',
          ),
          const SizedBox(height: 16),
          DropdownButtonFormField<String>(
            key: ValueKey('application-status-${_status ?? 'all'}'),
            initialValue: _status ?? 'all',
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Application status'),
            items: [
              for (final value in ['all', 'pending', 'approved', 'rejected'])
                DropdownMenuItem(
                  value: value,
                  child: Text(
                    value == 'all'
                        ? 'All statuses'
                        : value[0].toUpperCase() + value.substring(1),
                  ),
                ),
            ],
            onChanged: (value) {
              setState(() {
                _status = value == 'all' ? null : value;
                _page = 1;
              });
              _load();
            },
          ),
          const SizedBox(height: 12),
          if (data.applications.isEmpty)
            const Text('No applications match this status.'),
          for (final application in data.applications)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: InformationCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      application.name,
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    Text('Status: ${application.status}'),
                    Text(
                      'Submitted: ${application.submittedAt.toLocal().toString().substring(0, 10)}',
                    ),
                    Text('Reference: ${application.id}'),
                    if (application.rejectionReason != null)
                      Text('Review reason: ${application.rejectionReason}'),
                    Text(
                      application.hasPhoto
                          ? 'Private photo evidence attached'
                          : 'No photo evidence attached',
                    ),
                  ],
                ),
              ),
            ),
          Wrap(
            spacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Text(
                'Page ${data.page} of ${data.lastPage} · ${data.total} applications',
              ),
              TextButton(
                onPressed: _page <= 1
                    ? null
                    : () {
                        _page--;
                        _load();
                      },
                child: const Text('Previous'),
              ),
              TextButton(
                onPressed: _page >= data.lastPage
                    ? null
                    : () {
                        _page++;
                        _load();
                      },
                child: const Text('Next'),
              ),
              IconButton(
                tooltip: 'Refresh my applications',
                onPressed: _load,
                icon: const Icon(Icons.refresh),
              ),
            ],
          ),
          if (widget.onRegister != null)
            FilledButton(
              onPressed: widget.onRegister,
              child: const Text('New member application'),
            ),
        ],
      ),
    );
  }
}
