import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:http/http.dart' as http;

import '../../app/configuration/app_config.dart';
import 'bounded_http_client.dart';

enum ApiFailureKind {
  configuration,
  unauthenticated,
  forbidden,
  offline,
  rateLimited,
  unavailable,
  invalidResponse,
  validation,
}

class ApiFailure implements Exception {
  const ApiFailure(this.kind, {this.errors = const {}});
  final ApiFailureKind kind;
  final Map<String, List<String>> errors;
}

class LaravelApiClient {
  LaravelApiClient({required this.config, http.Client? client})
    : _client = client ?? BoundedHttpClient();
  final AppConfig config;
  final http.Client _client;

  // Includes auth, extraction, Python (45s), scope recheck and response transfer.
  // Laravel can expand Python's 8 MiB UTF-8 JSON up to 3x when escaping Unicode.
  // Reserve another 1 KiB for the HTTP JSON envelope. See README transport policy.
  static const reportTimeout = Duration(seconds: 120);
  static const reportMaxBytes = 3 * 8 * 1024 * 1024 + 1024;

  Future<Map<String, dynamic>> get(Uri uri, String token) async {
    final base = config.backendBaseUri;
    final prefix = base?.path.replaceFirst(RegExp(r'/+$'), '') ?? '';
    if (base == null ||
        uri.origin != base.origin ||
        uri.userInfo.isNotEmpty ||
        uri.hasFragment ||
        ![
          '$prefix/api/v1/me',
          '$prefix/api/v1/association/dashboard',
          '$prefix/api/v1/association/reports',
          '$prefix/api/v1/association/reports/options',
          '$prefix/api/v1/association/member-applications',
          '$prefix/api/v1/association/member-applications/options',
        ].contains(uri.path)) {
      throw const ApiFailure(ApiFailureKind.configuration);
    }
    try {
      final request = http.Request('GET', uri)..followRedirects = false;
      request.headers.addAll({
        'Accept': 'application/json',
        'Authorization': 'Bearer $token',
      });
      final client = _client;
      final response =
          client is BoundedHttpClient &&
              uri.path == '$prefix/api/v1/association/reports'
          ? await client.sendWithBudget(
              request,
              timeout: reportTimeout,
              maxBytes: reportMaxBytes,
            )
          : await client.send(request);
      return await _decode(response);
    } on ApiFailure {
      rethrow;
    } on FormatException {
      throw const ApiFailure(ApiFailureKind.invalidResponse);
    } on TimeoutException {
      throw const ApiFailure(ApiFailureKind.offline);
    } catch (_) {
      throw const ApiFailure(ApiFailureKind.offline);
    }
  }

  Future<Map<String, dynamic>> submitMemberApplication(
    String token,
    Map<String, String> fields, {
    Uint8List? photo,
    String? photoName,
  }) async {
    final base = config.backendBaseUri;
    if (base == null ||
        (photo != null &&
            (photo.length > 2 * 1024 * 1024 ||
                !const [
                  'applicant.jpg',
                  'applicant.png',
                ].contains(photoName)))) {
      throw const ApiFailure(ApiFailureKind.configuration);
    }
    final prefix = base.path.replaceFirst(RegExp(r'/+$'), '');
    final request =
        http.MultipartRequest(
            'POST',
            base.replace(
              path: '$prefix/api/v1/association/member-applications',
            ),
          )
          ..followRedirects = false
          ..headers.addAll({
            'Accept': 'application/json',
            'Authorization': 'Bearer $token',
          })
          ..fields.addAll(fields);
    if (photo != null) {
      request.files.add(
        http.MultipartFile.fromBytes(
          'applicant_photo',
          photo,
          filename: photoName,
        ),
      );
    }
    try {
      return await _decode(
        await _client.send(request),
        success: 201,
        validation: true,
      );
    } on ApiFailure {
      rethrow;
    } on FormatException {
      throw const ApiFailure(ApiFailureKind.invalidResponse);
    } catch (_) {
      throw const ApiFailure(ApiFailureKind.offline);
    }
  }

  Future<Map<String, dynamic>> _decode(
    http.StreamedResponse response, {
    int success = 200,
    bool validation = false,
  }) async {
    switch (response.statusCode) {
      case 401:
        throw const ApiFailure(ApiFailureKind.unauthenticated);
      case 403:
        throw const ApiFailure(ApiFailureKind.forbidden);
      case 429:
        throw const ApiFailure(ApiFailureKind.rateLimited);
    }
    final fieldErrors = validation && response.statusCode == 422;
    if (response.statusCode != success && !fieldErrors) {
      throw const ApiFailure(ApiFailureKind.unavailable);
    }
    if (response.headers['content-type']
            ?.split(';')
            .first
            .trim()
            .toLowerCase() !=
        'application/json') {
      throw const ApiFailure(ApiFailureKind.invalidResponse);
    }
    final json = jsonDecode(await response.stream.bytesToString());
    if (fieldErrors) {
      final errors = json is Map<String, dynamic> ? json['errors'] : null;
      if (errors is! Map<String, dynamic> || errors.length > 40) {
        throw const ApiFailure(ApiFailureKind.invalidResponse);
      }
      final safe = <String, List<String>>{};
      for (final entry in errors.entries) {
        if (!RegExp(
          r'^(first_name|middle_name|last_name|suffix|birth_date|sex_id|contact_number|fishr_registration_number|position_id|position_other_text|classification_ids(?:\.[0-9]+)?|endorsement_letter|applicant_photo|account)$',
        ).hasMatch(entry.key)) {
          continue;
        }
        final messages = entry.value;
        if (messages is! List ||
            messages.any(
              (message) => message is! String || message.length > 500,
            ) ||
            messages.length > 5) {
          throw const ApiFailure(ApiFailureKind.invalidResponse);
        }
        safe[entry.key] = List<String>.unmodifiable(messages);
      }
      throw ApiFailure(
        ApiFailureKind.validation,
        errors: Map.unmodifiable(safe),
      );
    }
    if (json is! Map<String, dynamic> ||
        json.containsKey('error') ||
        json['data'] is! Map<String, dynamic>) {
      throw const ApiFailure(ApiFailureKind.invalidResponse);
    }
    return json;
  }

  void cancelActive() {
    final client = _client;
    if (client is BoundedHttpClient) client.cancelActive();
  }

  void close() => _client.close();
}
