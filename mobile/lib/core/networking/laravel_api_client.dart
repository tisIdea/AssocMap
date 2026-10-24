import 'dart:async';
import 'dart:convert';

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
}

class ApiFailure implements Exception {
  const ApiFailure(this.kind);
  final ApiFailureKind kind;
}

class LaravelApiClient {
  LaravelApiClient({required this.config, http.Client? client})
    : _client = client ?? BoundedHttpClient();
  final AppConfig config;
  final http.Client _client;

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
        ].contains(uri.path)) {
      throw const ApiFailure(ApiFailureKind.configuration);
    }
    try {
      final request = http.Request('GET', uri)..followRedirects = false;
      request.headers.addAll({
        'Accept': 'application/json',
        'Authorization': 'Bearer $token',
      });
      final response = await _client.send(request);
      switch (response.statusCode) {
        case 401:
          throw const ApiFailure(ApiFailureKind.unauthenticated);
        case 403:
          throw const ApiFailure(ApiFailureKind.forbidden);
        case 429:
          throw const ApiFailure(ApiFailureKind.rateLimited);
      }
      if (response.statusCode != 200) {
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
      if (json is! Map<String, dynamic> ||
          json.containsKey('error') ||
          json['data'] is! Map<String, dynamic>) {
        throw const ApiFailure(ApiFailureKind.invalidResponse);
      }
      return json;
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

  void close() => _client.close();
}
