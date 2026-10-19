import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:http/http.dart' as http;

import '../errors/public_gis_failure.dart';

class PublicGisClient {
  PublicGisClient({
    required this.baseUri,
    http.Client Function()? clientFactory,
    this.timeout = const Duration(seconds: 15),
    this.maxResponseBytes = 3 * 1024 * 1024,
  }) : _clientFactory = clientFactory ?? http.Client.new;

  final Uri? Function() baseUri;
  final http.Client Function() _clientFactory;
  final Duration timeout;
  final int maxResponseBytes;

  Future<Map<String, dynamic>> getData(Map<String, String> query) async {
    Uri? base;
    try {
      base = baseUri();
    } on FormatException {
      throw PublicGisFailure(GisFailureCode.configuration);
    }
    if (base == null ||
        base.scheme != 'https' ||
        base.host.isEmpty ||
        base.userInfo.isNotEmpty ||
        base.hasQuery ||
        base.hasFragment) {
      throw PublicGisFailure(GisFailureCode.configuration);
    }
    final prefix = base.path.replaceFirst(RegExp(r'/+$'), '');
    final uri = base.replace(path: '$prefix/map/data', queryParameters: query);
    final client = _clientFactory();
    StreamSubscription<List<int>>? subscription;
    Completer<void>? finished;
    var active = true;

    Future<Map<String, dynamic>> request() async {
      final request = http.Request('GET', uri)..followRedirects = false;
      request.headers['Accept'] = 'application/json';
      final response = await client.send(request);
      if (!active) {
        throw PublicGisFailure(GisFailureCode.timeout);
      }
      if (response.statusCode != 200) {
        throw PublicGisFailure(
          response.statusCode == 429
              ? GisFailureCode.rateLimited
              : GisFailureCode.http,
          statusCode: response.statusCode,
        );
      }
      final contentType = response.headers['content-type']
          ?.split(';').first.trim().toLowerCase();
      if (response.isRedirect ||
          contentType != 'application/json' ||
          (response.contentLength != null &&
              response.contentLength! > maxResponseBytes)) {
        throw PublicGisFailure(GisFailureCode.invalidResponse);
      }
      final bytes = BytesBuilder(copy: false);
      final bodyFinished = Completer<void>();
      finished = bodyFinished;
      subscription = response.stream.listen(
        (chunk) {
          if (bodyFinished.isCompleted) {
            return;
          }
          if (bytes.length + chunk.length > maxResponseBytes) {
            bodyFinished.completeError(
              PublicGisFailure(GisFailureCode.invalidResponse),
            );
          } else {
            bytes.add(chunk);
          }
        },
        onError: (Object error, StackTrace stack) {
          if (!bodyFinished.isCompleted) {
            bodyFinished.completeError(error, stack);
          }
        },
        onDone: () {
          if (!bodyFinished.isCompleted) {
            bodyFinished.complete();
          }
        },
      );
      await bodyFinished.future;
      final decoded = jsonDecode(utf8.decode(bytes.takeBytes()));
      if (decoded is! Map<String, dynamic>) {
        throw const FormatException('Invalid public response.');
      }
      return decoded;
    }

    try {
      return await request().timeout(timeout);
    } on PublicGisFailure {
      rethrow;
    } on TimeoutException {
      throw PublicGisFailure(GisFailureCode.timeout);
    } on FormatException {
      throw PublicGisFailure(GisFailureCode.invalidResponse);
    } catch (_) {
      throw PublicGisFailure(GisFailureCode.network);
    } finally {
      active = false;
      if (finished != null && !finished!.isCompleted) {
        finished!.completeError(PublicGisFailure(GisFailureCode.timeout));
      }
      client.close();
      try {
        await subscription?.cancel().timeout(const Duration(seconds: 1));
      } catch (_) {
        // Cleanup must not expose transport errors or delay feedback indefinitely.
      }
    }
  }
}
