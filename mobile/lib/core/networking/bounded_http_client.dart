import 'dart:async';
import 'dart:typed_data';

import 'package:http/http.dart' as http;

// Bound the whole response and never forward credentials through redirects.
class BoundedHttpClient extends http.BaseClient {
  BoundedHttpClient({
    http.Client Function()? clientFactory,
    this.timeout = const Duration(seconds: 25),
    this.maxBytes = 4 * 1024 * 1024,
  }) : _factory = clientFactory ?? http.Client.new;

  final http.Client Function() _factory;
  final Duration timeout;
  final int maxBytes;
  final Set<http.Client> _active = {};

  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) async {
    final client = _factory();
    _active.add(client);
    request.followRedirects = false;
    try {
      return await (() async {
        final response = await client.send(request);
        if ((response.contentLength ?? 0) > maxBytes) {
          throw const FormatException('Response too large.');
        }
        final bytes = BytesBuilder(copy: false);
        await for (final chunk in response.stream) {
          if (bytes.length + chunk.length > maxBytes) {
            throw const FormatException('Response too large.');
          }
          bytes.add(chunk);
        }
        return http.StreamedResponse(
          Stream.value(bytes.takeBytes()),
          response.statusCode,
          headers: response.headers,
          request: request,
          isRedirect: response.isRedirect,
        );
      })().timeout(timeout);
    } finally {
      _active.remove(client);
      client.close();
    }
  }

  @override
  void close() {
    for (final client in _active) {
      client.close();
    }
    _active.clear();
  }
}
