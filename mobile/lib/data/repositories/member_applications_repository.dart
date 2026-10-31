import '../models/member_application.dart';

enum MemberApplicationFailureKind {
  unavailable,
  expired,
  denied,
  validation,
  uncertain,
}

class MemberApplicationFailure implements Exception {
  const MemberApplicationFailure(this.kind, {this.errors = const {}});
  final MemberApplicationFailureKind kind;
  final Map<String, List<String>> errors;
  String get message => switch (kind) {
    MemberApplicationFailureKind.expired =>
      'Sign in again to access member applications.',
    MemberApplicationFailureKind.denied =>
      'Your Association Account access is unavailable.',
    MemberApplicationFailureKind.validation =>
      'Review the highlighted application fields.',
    MemberApplicationFailureKind.uncertain =>
      'Submission could not be confirmed. Check your applications before starting another submission. Do not submit the same application again if it is already listed.',
    MemberApplicationFailureKind.unavailable =>
      'Member applications are unavailable. Retry or contact the administrator.',
  };
}

abstract interface class MemberApplicationsRepository {
  Future<MemberApplicationOptions> options();
  Future<MemberApplicationPage> list({int page = 1, String? status});
  Future<MemberApplicationSummary> submit(
    Map<String, String> fields, {
    MemberApplicationPhoto? photo,
  });
}

class UnavailableMemberApplicationsRepository
    implements MemberApplicationsRepository {
  const UnavailableMemberApplicationsRepository();
  @override
  Future<MemberApplicationOptions> options() async =>
      throw const MemberApplicationFailure(
        MemberApplicationFailureKind.unavailable,
      );
  @override
  Future<MemberApplicationPage> list({int page = 1, String? status}) async =>
      throw const MemberApplicationFailure(
        MemberApplicationFailureKind.unavailable,
      );
  @override
  Future<MemberApplicationSummary> submit(
    Map<String, String> fields, {
    MemberApplicationPhoto? photo,
  }) async => throw const MemberApplicationFailure(
    MemberApplicationFailureKind.unavailable,
  );
}
