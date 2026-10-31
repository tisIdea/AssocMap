import 'package:mobile/data/models/member_application.dart';
import 'package:mobile/data/repositories/member_applications_repository.dart';

const applicationAssociationId = '33333333-3333-4333-8333-333333333333';
const applicationId = '77777777-7777-4777-8777-777777777777';
Map<String, dynamic> applicationOptions() => {
  'association': {
    'id': applicationAssociationId,
    'name': 'Synthetic association',
  },
  'sex_categories': [
    {'id': 17, 'name': 'Synthetic sex category'},
  ],
  'positions': [
    {'id': 21, 'code': 'MEMBER', 'name': 'Member', 'occupied': false},
    {'id': 29, 'code': 'OTHER', 'name': 'Other', 'occupied': false},
    {'id': 31, 'code': 'PRESIDENT', 'name': 'President', 'occupied': true},
  ],
  'classifications': [
    {'id': 43, 'name': 'Synthetic classification'},
  ],
};
Map<String, dynamic> applicationSummary({String status = 'pending'}) => {
  'id': applicationId,
  'name': 'Synthetic Applicant',
  'status': status,
  'submitted_at': '2026-01-02T03:04:05Z',
  'reviewed_at': status == 'pending' ? null : '2026-01-03T03:04:05Z',
  'rejection_reason': status == 'rejected' ? 'Synthetic review reason' : null,
  'has_photo': false,
};
Map<String, dynamic> applicationPage({
  String status = 'pending',
  int page = 1,
}) => {
  'association_id': applicationAssociationId,
  'applications': [applicationSummary(status: status)],
  'page': page,
  'last_page': 2,
  'total': 26,
};

class TestMemberApplicationsRepository implements MemberApplicationsRepository {
  Future<MemberApplicationOptions> Function()? loadOptions;
  Future<MemberApplicationSummary> Function()? send;
  Future<MemberApplicationPage> Function(int page, String? status)? loadPage;
  int submissions = 0;
  Map<String, String>? fields;
  MemberApplicationPhoto? photo;
  @override
  Future<MemberApplicationOptions> options() async => loadOptions != null
      ? loadOptions!()
      : MemberApplicationOptions.fromJson(applicationOptions());
  @override
  Future<MemberApplicationPage> list({int page = 1, String? status}) async =>
      loadPage != null
      ? loadPage!(page, status)
      : MemberApplicationPage.fromJson(
          applicationPage(status: status ?? 'pending', page: page),
        );
  @override
  Future<MemberApplicationSummary> submit(
    Map<String, String> fields, {
    MemberApplicationPhoto? photo,
  }) async {
    submissions++;
    this.fields = fields;
    this.photo = photo;
    return send != null
        ? send!()
        : MemberApplicationSummary.fromJson(applicationSummary());
  }
}
