import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../../core/media/member_photo_picker.dart';
import '../../core/widgets/assocmap_widgets.dart';
import '../../core/widgets/feedback_panel.dart';
import '../../data/models/member_application.dart';
import '../../data/repositories/member_applications_repository.dart';

class MemberRegistrationScreen extends StatefulWidget {
  const MemberRegistrationScreen({
    super.key,
    required this.associationName,
    this.associationId = '',
    this.repository = const UnavailableMemberApplicationsRepository(),
    this.onUnauthorized,
    this.onApplications,
    this.onBusyChanged,
    this.photoPicker = pickMemberApplicationPhoto,
  });
  final String associationName, associationId;
  final MemberApplicationsRepository repository;
  final VoidCallback? onUnauthorized, onApplications;
  final ValueChanged<bool>? onBusyChanged;
  final MemberPhotoPicker photoPicker;
  @override
  State<MemberRegistrationScreen> createState() =>
      _MemberRegistrationScreenState();
}

class _MemberRegistrationScreenState extends State<MemberRegistrationScreen> {
  final _form = GlobalKey<FormState>();
  final _fields = {
    for (final key in [
      'first_name',
      'middle_name',
      'last_name',
      'suffix',
      'birth_date',
      'contact_number',
      'fishr_registration_number',
      'position_other_text',
      'endorsement_letter',
    ])
      key: TextEditingController(),
  };
  MemberApplicationOptions? _options;
  MemberApplicationFailure? _failure;
  MemberApplicationSummary? _receipt;
  Map<String, List<String>> _errors = {};
  MemberApplicationPhoto? _photo;
  int? _sex, _position;
  final _classifications = <int>{};
  bool _loading = true, _submitting = false, _picking = false, _locked = false;
  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _photo = null;
    for (final field in _fields.values) {
      field.clear();
      field.dispose();
    }
    super.dispose();
  }

  void _unauthorized(MemberApplicationFailure failure) {
    if ([
      MemberApplicationFailureKind.expired,
      MemberApplicationFailureKind.denied,
    ].contains(failure.kind)) {
      widget.onUnauthorized?.call();
    }
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _failure = null;
    });
    try {
      final options = await widget.repository.options();
      if (!mounted) return;
      if (options.associationId != widget.associationId) {
        throw const MemberApplicationFailure(
          MemberApplicationFailureKind.denied,
        );
      }
      setState(() {
        _options = options;
        _loading = false;
      });
    } on MemberApplicationFailure catch (error) {
      if (!mounted) return;
      setState(() {
        _failure = error;
        _loading = false;
      });
      _unauthorized(error);
    } catch (_) {
      if (mounted) {
        setState(() {
          _failure = const MemberApplicationFailure(
            MemberApplicationFailureKind.unavailable,
          );
          _loading = false;
        });
      }
    }
  }

  Future<void> _pick() async {
    if (_picking || _submitting || _locked) return;
    setState(() => _picking = true);
    try {
      final photo = await widget.photoPicker();
      if (mounted && photo != null) {
        setState(() {
          _photo = photo;
          _errors.remove('applicant_photo');
        });
      }
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              error is MissingPluginException ||
                      (error is PlatformException &&
                          error.code == 'unavailable')
                  ? 'Photo selection is unavailable on this device. You can submit without a photo.'
                  : 'Choose a readable JPEG or PNG photo, at most 2 MiB and 2048 × 2048 pixels.',
            ),
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _picking = false);
    }
  }

  String? _error(String key) => _errors[key]?.firstOrNull;
  Widget _field(
    String key,
    String label, {
    int max = 120,
    bool required = false,
    int lines = 1,
    TextInputType? keyboard,
  }) => Padding(
    padding: const EdgeInsets.only(bottom: 14),
    child: TextFormField(
      key: ValueKey('application-$key'),
      controller: _fields[key],
      maxLength: max,
      maxLines: lines,
      enabled: !_submitting && !_locked,
      autocorrect: false,
      enableSuggestions: false,
      keyboardType: keyboard,
      decoration: InputDecoration(labelText: label),
      onChanged: (_) {
        if (_errors.containsKey(key)) setState(() => _errors.remove(key));
      },
      validator: (value) {
        final text = value?.trim() ?? '';
        if (required && text.isEmpty) return 'This field is required.';
        if (text.runes.length > max) return 'Use at most $max characters.';
        if (key == 'birth_date') {
          final date = DateTime.tryParse(text);
          final today = DateTime.now();
          if (!RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(text) ||
              date == null ||
              date.toIso8601String().substring(0, 10) != text ||
              date.isAfter(DateTime(today.year, today.month, today.day))) {
            return 'Enter a valid birth date on or before today (YYYY-MM-DD).';
          }
        }
        return _error(key);
      },
    ),
  );
  Widget _choice(
    String key,
    String label,
    int? value,
    List<MemberApplicationChoice> choices,
    ValueChanged<int?> change,
  ) => Padding(
    padding: const EdgeInsets.only(bottom: 14),
    child: DropdownButtonFormField<int>(
      key: ValueKey('application-$key'),
      initialValue: value,
      isExpanded: true,
      decoration: InputDecoration(labelText: label),
      items: [
        for (final choice in choices)
          DropdownMenuItem(
            value: choice.id,
            enabled: !choice.occupied,
            child: Text(
              choice.name + (choice.occupied ? ' (occupied)' : ''),
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
          ),
      ],
      onChanged: _submitting || _locked
          ? null
          : (value) => setState(() {
              _errors.remove(key);
              change(value);
            }),
      validator: (value) => value == null ? 'Choose an option.' : _error(key),
    ),
  );
  Future<void> _submit() async {
    if (_submitting || _locked || _picking) return;
    setState(() {
      _errors = {};
      _failure = null;
    });
    if (_form.currentState?.validate() != true) return;
    final fields = <String, String>{
      for (final entry in _fields.entries)
        if (entry.value.text.trim().isNotEmpty)
          entry.key: entry.value.text.trim(),
      'sex_id': '$_sex',
      'position_id': '$_position',
      for (final (index, id) in _classifications.indexed)
        'classification_ids[$index]': '$id',
    };
    setState(() => _submitting = true);
    widget.onBusyChanged?.call(true);
    try {
      final receipt = await widget.repository.submit(fields, photo: _photo);
      if (!mounted) return;
      for (final field in _fields.values) {
        field.clear();
      }
      setState(() {
        _receipt = receipt;
        _photo = null;
        _locked = true;
      });
    } on MemberApplicationFailure catch (error) {
      if (!mounted) return;
      setState(() {
        _failure = error;
        _errors = Map.of(error.errors);
        _locked = error.kind == MemberApplicationFailureKind.uncertain;
      });
      _form.currentState?.validate();
      _unauthorized(error);
    } catch (_) {
      if (mounted) {
        setState(() {
          _failure = const MemberApplicationFailure(
            MemberApplicationFailureKind.uncertain,
          );
          _locked = true;
        });
      }
    } finally {
      if (mounted) setState(() => _submitting = false);
      widget.onBusyChanged?.call(false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Center(
        child: CircularProgressIndicator(
          semanticsLabel: 'Loading registration choices',
        ),
      );
    }
    final options = _options;
    if (options == null) {
      return FeedbackPanel(
        icon: Icons.cloud_off_outlined,
        title: 'Registration unavailable',
        message:
            _failure?.message ?? 'Registration choices could not be loaded.',
        actionLabel: 'Retry',
        onAction: _load,
      );
    }
    if (options.sexes.isEmpty || options.positions.isEmpty) {
      return FeedbackPanel(
        icon: Icons.info_outline,
        title: 'Registration choices not configured',
        message:
            'Contact the administrator to configure the required membership choices.',
        actionLabel: 'Retry',
        onAction: _load,
      );
    }
    final receipt = _receipt;
    if (receipt != null) {
      return PageContent(
        children: [
          Semantics(
            liveRegion: true,
            child: Text(
              'Application submitted for Field Officer review',
              style: Theme.of(context).textTheme.headlineSmall,
            ),
          ),
          const SizedBox(height: 16),
          Text('Reference: ${receipt.id}'),
          const Text('Status: Pending'),
          const Text(
            'This submission has not created an official member. Approval is handled by your Field Officer.',
          ),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: widget.onApplications,
            child: const Text('View my applications'),
          ),
        ],
      );
    }
    final other = options.positions.any(
      (position) => position.id == _position && position.code == 'OTHER',
    );
    return Form(
      key: _form,
      child: PageContent(
        children: [
          const ReadOnlyNotice(
            message:
                'Submit a membership application for Field Officer review. Your association is assigned by the server.',
          ),
          const SizedBox(height: 16),
          Text(
            widget.associationName,
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const SectionHeading('Personal information'),
          _field('first_name', 'First name *', required: true),
          _field('middle_name', 'Middle name (optional)'),
          _field('last_name', 'Last name *', required: true),
          _field('suffix', 'Suffix (optional)', max: 20),
          _field(
            'birth_date',
            'Birth date (YYYY-MM-DD) *',
            required: true,
            max: 10,
            keyboard: TextInputType.datetime,
          ),
          _choice(
            'sex_id',
            'Sex *',
            _sex,
            options.sexes,
            (value) => _sex = value,
          ),
          _field(
            'contact_number',
            'Contact number (optional)',
            max: 30,
            keyboard: TextInputType.phone,
          ),
          _field(
            'fishr_registration_number',
            'FishR registration number (optional)',
            max: 100,
          ),
          const SectionHeading('Membership information'),
          _choice(
            'position_id',
            'Association position *',
            _position,
            options.positions,
            (value) {
              _position = value;
              _fields['position_other_text']!.clear();
            },
          ),
          if (other)
            _field(
              'position_other_text',
              'Specify position *',
              required: true,
              max: 100,
            ),
          if (options.classifications.isNotEmpty) ...[
            const Text('Beneficiary classifications (optional)'),
            for (final choice in options.classifications)
              CheckboxListTile(
                title: Text(choice.name),
                value: _classifications.contains(choice.id),
                onChanged: _submitting || _locked
                    ? null
                    : (value) => setState(() {
                        if (value == true) {
                          _classifications.add(choice.id);
                        } else {
                          _classifications.remove(choice.id);
                        }
                      }),
              ),
          ],
          _field(
            'endorsement_letter',
            'Endorsement letter *',
            max: 10000,
            required: true,
            lines: 5,
            keyboard: TextInputType.multiline,
          ),
          const Text(
            'Applicant photo (optional) · JPEG/PNG · up to 2 MiB · up to 2048 × 2048 pixels',
          ),
          if (_photo != null)
            Image.memory(
              _photo!.bytes,
              height: 140,
              semanticLabel: 'Selected applicant photo',
              errorBuilder: (_, _, _) =>
                  const Text('Photo preview unavailable'),
            ),
          Wrap(
            spacing: 8,
            children: [
              OutlinedButton.icon(
                onPressed: _submitting || _locked || _picking ? null : _pick,
                icon: const Icon(Icons.photo_library_outlined),
                label: Text(_picking ? 'Opening photos…' : 'Choose photo'),
              ),
              if (_photo != null)
                TextButton(
                  onPressed: _submitting || _locked
                      ? null
                      : () => setState(() => _photo = null),
                  child: const Text('Remove photo'),
                ),
            ],
          ),
          if (_failure != null)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 12),
              child: Semantics(
                liveRegion: true,
                child: Text(
                  [
                    _failure!.message,
                    ..._errors.values.expand((messages) => messages),
                  ].join('\n'),
                ),
              ),
            ),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: _submitting || _locked || _picking ? null : _submit,
            child: Text(_submitting ? 'Submitting…' : 'Submit for Review'),
          ),
          if (widget.onApplications != null)
            TextButton(
              onPressed: _submitting ? null : widget.onApplications,
              child: const Text('View my applications'),
            ),
          const SizedBox(height: 20),
        ],
      ),
    );
  }
}
