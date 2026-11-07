import 'dart:async';
import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/app/theme/app_theme.dart';
import 'package:mobile/core/media/member_photo_picker.dart';
import 'package:mobile/data/models/member_application.dart';
import 'package:mobile/data/repositories/member_applications_repository.dart';
import 'package:mobile/features/account/member_registration_screen.dart';
import 'package:mobile/features/account/member_applications_screen.dart';
import 'support/member_application_fixtures.dart';

void main() {
  Future<void> open(
    WidgetTester tester,
    TestMemberApplicationsRepository repository, {
    double scale = 1,
    VoidCallback? unauthorized,
    ValueChanged<bool>? busy,
    VoidCallback? applications,
    MemberPhotoPicker photoPicker = pickMemberApplicationPhoto,
  }) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.light,
        builder: (context, child) => MediaQuery(
          data: MediaQuery.of(
            context,
          ).copyWith(textScaler: TextScaler.linear(scale)),
          child: child!,
        ),
        home: Scaffold(
          body: MemberRegistrationScreen(
            associationName: 'Synthetic association',
            associationId: applicationAssociationId,
            repository: repository,
            onUnauthorized: unauthorized,
            onBusyChanged: busy,
            onApplications: applications,
            photoPicker: photoPicker,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  Future<void> text(WidgetTester tester, String key, String value) async {
    final field = find.byKey(ValueKey('application-$key'));
    await tester.ensureVisible(field);
    await tester.enterText(field, value);
    tester.testTextInput.hide();
    await tester.pumpAndSettle();
  }

  Future<void> choose(WidgetTester tester, String key, String label) async {
    final field = find.byKey(ValueKey('application-$key'));
    await tester.ensureVisible(field);
    await tester.tap(field);
    await tester.pumpAndSettle();
    await tester.tap(find.text(label).last);
    await tester.pumpAndSettle();
  }

  Future<void> fill(WidgetTester tester) async {
    await text(tester, 'first_name', '  Synthetic  ');
    await text(tester, 'last_name', 'Applicant');
    await text(tester, 'birth_date', '2000-01-02');
    await choose(tester, 'sex_id', 'Synthetic sex category');
    await choose(tester, 'position_id', 'Member');
    await text(tester, 'endorsement_letter', 'Synthetic endorsement');
  }

  Future<void> submit(WidgetTester tester) async {
    final button = find.widgetWithText(FilledButton, 'Submit for Review');
    await tester.ensureVisible(button);
    await tester.tap(button);
    await tester.pump();
  }

  testWidgets('Registration choices load with retry and no fake choices', (
    tester,
  ) async {
    var attempts = 0;
    final repository = TestMemberApplicationsRepository()
      ..loadOptions = () async {
        if (++attempts == 1) {
          throw const MemberApplicationFailure(
            MemberApplicationFailureKind.unavailable,
          );
        }
        return MemberApplicationOptions.fromJson(applicationOptions());
      };
    await open(tester, repository);
    expect(find.text('Registration unavailable'), findsOneWidget);
    expect(find.byType(TextFormField), findsNothing);
    await tester.tap(find.text('Retry'));
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('application-first_name')),
      findsOneWidget,
    );
    expect(repository.submissions, 0);
  });
  testWidgets('Required fields and invalid birth dates prevent writes', (
    tester,
  ) async {
    final repository = TestMemberApplicationsRepository();
    await open(tester, repository);
    await submit(tester);
    await tester.pumpAndSettle();
    expect(repository.submissions, 0);
    expect(find.text('This field is required.'), findsWidgets);
    await fill(tester);
    await text(tester, 'birth_date', '2000-02-31');
    await submit(tester);
    await tester.pumpAndSettle();
    expect(repository.submissions, 0);
    await text(tester, 'birth_date', '2999-01-01');
    await submit(tester);
    await tester.pumpAndSettle();
    expect(repository.submissions, 0);
  });
  testWidgets(
    'Valid application is submitted once, shows pending receipt and clears personal fields',
    (tester) async {
      final response = Completer<MemberApplicationSummary>();
      final busy = <bool>[];
      final repository = TestMemberApplicationsRepository()
        ..send = () => response.future;
      await open(tester, repository, busy: busy.add);
      await fill(tester);
      final choice = find.text('Synthetic classification');
      await tester.ensureVisible(choice);
      await tester.tap(choice);
      await submit(tester);
      expect(repository.submissions, 1);
      expect(find.text('Submitting…'), findsOneWidget);
      expect(
        tester
            .widget<FilledButton>(
              find.widgetWithText(FilledButton, 'Submitting…'),
            )
            .onPressed,
        isNull,
      );
      expect(repository.fields!['first_name'], 'Synthetic');
      expect(repository.fields!['classification_ids[0]'], '43');
      expect(repository.fields!.containsKey('association_id'), isFalse);
      expect(repository.fields!.containsKey('status'), isFalse);
      response.complete(
        MemberApplicationSummary.fromJson(applicationSummary()),
      );
      await tester.pumpAndSettle();
      expect(find.text('Status: Pending'), findsOneWidget);
      expect(find.byType(TextFormField), findsNothing);
      expect(busy, [true, false]);
      expect(repository.submissions, 1);
    },
  );
  testWidgets(
    'OTHER position requires details and occupied executive choices are disabled',
    (tester) async {
      final repository = TestMemberApplicationsRepository();
      await open(tester, repository);
      await fill(tester);
      final positions = tester.widget<DropdownButton<int>>(
        find.descendant(
          of: find.byKey(const ValueKey('application-position_id')),
          matching: find.byType(DropdownButton<int>),
        ),
      );
      expect(
        positions.items!.singleWhere((item) => item.value == 31).enabled,
        isFalse,
      );
      await choose(tester, 'position_id', 'Other');
      await submit(tester);
      await tester.pumpAndSettle();
      expect(repository.submissions, 0);
      await text(tester, 'position_other_text', 'Synthetic position');
      await submit(tester);
      await tester.pumpAndSettle();
      expect(repository.fields!['position_other_text'], 'Synthetic position');
    },
  );

  testWidgets(
    'Optional photo selection, removal and upload stay in the current application',
    (tester) async {
      final repository = TestMemberApplicationsRepository();
      final photo = MemberApplicationPhoto(
        bytes: base64Decode(
          'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j6aQAAAAASUVORK5CYII=',
        ),
        name: 'applicant.png',
      );
      await open(tester, repository, photoPicker: () async => photo);
      await fill(tester);
      final choosePhoto = find.text('Choose photo');
      await tester.ensureVisible(choosePhoto);
      await tester.tap(choosePhoto);
      await tester.pumpAndSettle();
      expect(find.bySemanticsLabel('Selected applicant photo'), findsOneWidget);
      await tester.ensureVisible(find.text('Remove photo'));
      await tester.tap(find.text('Remove photo'));
      await tester.pumpAndSettle();
      expect(find.text('Remove photo'), findsNothing);
      await tester.ensureVisible(choosePhoto);
      await tester.tap(choosePhoto);
      await tester.pumpAndSettle();
      await submit(tester);
      await tester.pumpAndSettle();
      expect(repository.photo, same(photo));
      expect(repository.submissions, 1);
    },
  );
  testWidgets(
    'Server validation keeps editable fields and permits an explicit correction',
    (tester) async {
      var attempts = 0;
      final repository = TestMemberApplicationsRepository()
        ..send = () async {
          if (++attempts == 1) {
            throw const MemberApplicationFailure(
              MemberApplicationFailureKind.validation,
              errors: {
                'first_name': ['Synthetic server validation'],
              },
            );
          }
          return MemberApplicationSummary.fromJson(applicationSummary());
        };
      await open(tester, repository);
      await fill(tester);
      await submit(tester);
      await tester.pumpAndSettle();
      expect(find.textContaining('Synthetic server validation'), findsWidgets);
      expect(repository.submissions, 1);
      await text(tester, 'first_name', 'Corrected');
      await submit(tester);
      await tester.pumpAndSettle();
      expect(find.text('Status: Pending'), findsOneWidget);
      expect(repository.submissions, 2);
    },
  );
  testWidgets(
    'Uncertain writes lock resubmission and direct the user to existing applications',
    (tester) async {
      var opened = false;
      final repository = TestMemberApplicationsRepository()
        ..send = () async => throw const MemberApplicationFailure(
          MemberApplicationFailureKind.uncertain,
        );
      await open(tester, repository, applications: () => opened = true);
      await fill(tester);
      await submit(tester);
      await tester.pumpAndSettle();
      expect(
        find.textContaining('Submission could not be confirmed'),
        findsOneWidget,
      );
      expect(
        tester
            .widget<FilledButton>(
              find.widgetWithText(FilledButton, 'Submit for Review'),
            )
            .onPressed,
        isNull,
      );
      final button = find.text('View my applications');
      await tester.ensureVisible(button);
      await tester.tap(button);
      expect(opened, isTrue);
      expect(repository.submissions, 1);
    },
  );
  testWidgets(
    'Expired access invokes the guard without showing protected form data',
    (tester) async {
      var expired = false;
      final repository = TestMemberApplicationsRepository()
        ..loadOptions = () async => throw const MemberApplicationFailure(
          MemberApplicationFailureKind.expired,
        );
      await open(tester, repository, unauthorized: () => expired = true);
      expect(expired, isTrue);
      expect(find.byType(TextFormField), findsNothing);
    },
  );
  testWidgets('Application list filters and pages remain read-only', (
    tester,
  ) async {
    final requests = <String>[];
    final repository = TestMemberApplicationsRepository()
      ..loadPage = (page, status) async {
        requests.add('$page:$status');
        return MemberApplicationPage.fromJson(
          applicationPage(page: page, status: status ?? 'pending'),
        );
      };
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: MemberApplicationsScreen(
            repository: repository,
            associationId: applicationAssociationId,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Status: pending'), findsOneWidget);
    final next = find.text('Next');
    await tester.ensureVisible(next);
    await tester.tap(next);
    await tester.pumpAndSettle();
    expect(requests.last, '2:null');
    final status = find.byType(DropdownButtonFormField<String>);
    await tester.ensureVisible(status);
    await tester.tap(status);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Rejected').last);
    await tester.pumpAndSettle();
    expect(requests.last, '1:rejected');
    expect(find.text('Review reason: Synthetic review reason'), findsOneWidget);
    expect(repository.submissions, 0);
    expect(find.text('Approve'), findsNothing);
  });
  for (final size in [const Size(320, 640), const Size(640, 360)]) {
    testWidgets('Registration supports $size and 200 percent text', (
      tester,
    ) async {
      tester.view.devicePixelRatio = 1;
      tester.view.physicalSize = size;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      await open(tester, TestMemberApplicationsRepository(), scale: 2);
      await fill(tester);
      await submit(tester);
      await tester.pumpAndSettle();
      expect(find.text('Status: Pending'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
}
