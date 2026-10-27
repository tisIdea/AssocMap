import 'package:flutter/material.dart';
import '../../app/theme/app_theme.dart';
import '../../core/widgets/assocmap_widgets.dart';

class MemberRegistrationScreen extends StatefulWidget {
  const MemberRegistrationScreen({super.key, required this.associationName});
  final String associationName;
  @override
  State<MemberRegistrationScreen> createState() =>
      _MemberRegistrationScreenState();
}

class _MemberRegistrationScreenState extends State<MemberRegistrationScreen> {
  final _first = TextEditingController();
  final _middle = TextEditingController();
  final _last = TextEditingController();
  final _contact = TextEditingController();
  final _endorsement = TextEditingController();
  DateTime? _birth;
  bool _consent = false;
  @override
  void dispose() {
    for (final controller in [_first, _middle, _last, _contact, _endorsement]) {
      controller.clear();
      controller.dispose();
    }
    super.dispose();
  }

  Widget _field(
    String label,
    TextEditingController controller, {
    int maxLength = 120,
    TextInputType? keyboard,
  }) => Padding(
    padding: const EdgeInsets.only(bottom: 14),
    child: TextField(
      controller: controller,
      maxLength: maxLength,
      keyboardType: keyboard,
      decoration: InputDecoration(labelText: label),
      textInputAction: TextInputAction.next,
      autocorrect: false,
      enableSuggestions: false,
    ),
  );
  @override
  Widget build(BuildContext context) => PageContent(
    children: [
      const ReadOnlyNotice(
        message:
            'Mobile registration submission is currently unavailable. Details entered here are not saved or sent. Use your association web workspace to submit an application.',
        icon: Icons.info_outline,
      ),
      const SizedBox(height: 18),
      Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          for (final step in [
            (1, 'Enter details'),
            (2, 'Submit'),
            (3, 'Review'),
          ])
            Expanded(
              child: Column(
                children: [
                  CircleAvatar(
                    radius: 12,
                    backgroundColor: step.$1 == 1
                        ? AppTheme.blue
                        : const Color(0xFFDFE9F7),
                    child: Text(
                      '${step.$1}',
                      style: TextStyle(
                        fontSize: 11,
                        color: step.$1 == 1 ? Colors.white : AppTheme.muted,
                      ),
                    ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    step.$2,
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontSize: 11,
                      color: step.$1 == 1 ? AppTheme.blue : AppTheme.muted,
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
      const SectionHeading('Personal information'),
      _field('First name *', _first),
      _field('Middle name (optional)', _middle),
      _field('Last name *', _last),
      OutlinedButton.icon(
        onPressed: () async {
          final now = DateTime.now();
          final date = await showDatePicker(
            context: context,
            initialDate: _birth ?? DateTime(now.year - 18),
            firstDate: DateTime(1900),
            lastDate: DateTime(now.year, now.month, now.day),
          );
          if (mounted && date != null) setState(() => _birth = date);
        },
        icon: const Icon(Icons.calendar_today_outlined),
        label: Text(
          _birth == null
              ? 'Date of birth *'
              : '${_birth!.year}-${_birth!.month.toString().padLeft(2, '0')}-${_birth!.day.toString().padLeft(2, '0')}',
        ),
      ),
      const SizedBox(height: 14),
      const TextField(
        enabled: false,
        decoration: InputDecoration(
          labelText: 'Sex *',
          hintText: 'Options unavailable',
          suffixIcon: Icon(Icons.expand_more),
        ),
      ),
      const SizedBox(height: 14),
      const TextField(
        enabled: false,
        decoration: InputDecoration(
          labelText: 'Beneficiary classification',
          hintText: 'Options unavailable',
          suffixIcon: Icon(Icons.expand_more),
        ),
      ),
      const SizedBox(height: 14),
      _field(
        'Mobile number (optional)',
        _contact,
        maxLength: 30,
        keyboard: TextInputType.phone,
      ),
      const SectionHeading('Association information'),
      InformationCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Association linked to your account',
              style: TextStyle(fontSize: 12, color: AppTheme.muted),
            ),
            const SizedBox(height: 6),
            Text(
              widget.associationName,
              style: const TextStyle(fontWeight: FontWeight.w600),
            ),
          ],
        ),
      ),
      const SizedBox(height: 14),
      const TextField(
        enabled: false,
        decoration: InputDecoration(
          labelText: 'Association position *',
          hintText: 'Options unavailable',
        ),
      ),
      const SizedBox(height: 14),
      const TextField(
        enabled: false,
        decoration: InputDecoration(
          labelText: 'Barangay / municipality',
          hintText: 'Address details unavailable',
        ),
      ),
      const SizedBox(height: 14),
      const TextField(
        enabled: false,
        decoration: InputDecoration(
          labelText: 'Primary livelihood',
          hintText: 'Options unavailable',
        ),
      ),
      const SizedBox(height: 14),
      _field(
        'Endorsement letter *',
        _endorsement,
        maxLength: 10000,
        keyboard: TextInputType.multiline,
      ),
      const PhotoPlaceholder(height: 110, label: 'Applicant photo unavailable'),
      const SizedBox(height: 12),
      CheckboxListTile(
        contentPadding: EdgeInsets.zero,
        value: _consent,
        onChanged: (value) => setState(() => _consent = value ?? false),
        title: const Text(
          "I have the applicant's consent to share these details for membership review.",
        ),
      ),
      const SizedBox(height: 14),
      const Text(
        'Membership applications require Field Officer review. Submission is unavailable on mobile.',
        style: TextStyle(fontSize: 12, color: AppTheme.muted),
      ),
      const SizedBox(height: 12),
      const FilledButton(onPressed: null, child: Text('Submit for Review')),
      const SizedBox(height: 20),
    ],
  );
}
