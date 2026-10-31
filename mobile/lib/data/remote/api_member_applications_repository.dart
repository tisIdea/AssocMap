import '../../app/configuration/app_config.dart';
import '../../core/networking/laravel_api_client.dart';
import '../models/member_application.dart';
import '../repositories/member_applications_repository.dart';

typedef MemberApplicationRead = Future<Map<String, dynamic>> Function(Uri uri);
typedef MemberApplicationSubmit =
    Future<Map<String, dynamic>> Function(
      Map<String, String> fields, {
      MemberApplicationPhoto? photo,
    });

class ApiMemberApplicationsRepository implements MemberApplicationsRepository {
  ApiMemberApplicationsRepository({
    required this.config,
    required this.read,
    required this.write,
    required this.associationId,
  });
  final AppConfig config;
  final MemberApplicationRead read;
  final MemberApplicationSubmit write;
  final String? Function() associationId;
  bool _submitting = false;

  Map<String, dynamic> _data(
    Map<String, dynamic> response, {
    bool options = false,
  }) {
    final data = response['data'];
    if (data is! Map<String, dynamic>) {
      throw const FormatException('Invalid application response.');
    }
    final scope = options
        ? (data['association'] as Map<String, dynamic>)['id']
        : data['association_id'];
    if (associationId() == null || scope != associationId()) {
      throw const MemberApplicationFailure(MemberApplicationFailureKind.denied);
    }
    return data;
  }

  Uri _uri(String suffix, [Map<String, String>? query]) {
    final base = config.backendBaseUri;
    if (base == null) {
      throw const MemberApplicationFailure(
        MemberApplicationFailureKind.unavailable,
      );
    }
    final prefix = base.path.replaceFirst(RegExp(r'/+$'), '');
    return base.replace(
      path: '$prefix/api/v1/association/member-applications$suffix',
      queryParameters: query,
    );
  }

  MemberApplicationFailure _failure(
    ApiFailure error, {
    bool submitting = false,
  }) => MemberApplicationFailure(switch (error.kind) {
    ApiFailureKind.unauthenticated => MemberApplicationFailureKind.expired,
    ApiFailureKind.forbidden => MemberApplicationFailureKind.denied,
    ApiFailureKind.validation => MemberApplicationFailureKind.validation,
    _ =>
      submitting
          ? MemberApplicationFailureKind.uncertain
          : MemberApplicationFailureKind.unavailable,
  }, errors: error.errors);

  @override
  Future<MemberApplicationOptions> options() async {
    try {
      return MemberApplicationOptions.fromJson(
        _data(await read(_uri('/options')), options: true),
      );
    } on ApiFailure catch (error) {
      throw _failure(error);
    } on MemberApplicationFailure {
      rethrow;
    } catch (_) {
      throw const MemberApplicationFailure(
        MemberApplicationFailureKind.unavailable,
      );
    }
  }

  @override
  Future<MemberApplicationPage> list({int page = 1, String? status}) async {
    if (page < 1 ||
        page > 100000 ||
        (status != null &&
            !const ['pending', 'approved', 'rejected'].contains(status))) {
      throw const MemberApplicationFailure(
        MemberApplicationFailureKind.validation,
      );
    }
    try {
      return MemberApplicationPage.fromJson(
        _data(await read(_uri('', {'page': '$page', 'status': ?status}))),
      );
    } on ApiFailure catch (error) {
      throw _failure(error);
    } on MemberApplicationFailure {
      rethrow;
    } catch (_) {
      throw const MemberApplicationFailure(
        MemberApplicationFailureKind.unavailable,
      );
    }
  }

  @override
  Future<MemberApplicationSummary> submit(
    Map<String, String> fields, {
    MemberApplicationPhoto? photo,
  }) async {
    if (_submitting) {
      throw const MemberApplicationFailure(
        MemberApplicationFailureKind.uncertain,
      );
    }
    _submitting = true;
    try {
      final response = await write(Map.unmodifiable(fields), photo: photo);
      final data = _data(response);
      final result = MemberApplicationSummary.fromJson(
        data['application'] as Map<String, dynamic>,
      );
      if (result.status != 'pending') {
        throw const FormatException('Invalid submission receipt.');
      }
      return result;
    } on ApiFailure catch (error) {
      throw _failure(error, submitting: true);
    } catch (_) {
      throw const MemberApplicationFailure(
        MemberApplicationFailureKind.uncertain,
      );
    } finally {
      _submitting = false;
    }
  }
}
