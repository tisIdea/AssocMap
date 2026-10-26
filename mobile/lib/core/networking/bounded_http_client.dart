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
  final Map<http.Client, void Function()> _active = {};

  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) =>
      sendWithBudget(request, timeout: timeout, maxBytes: maxBytes);

  Future<http.StreamedResponse> sendWithBudget(
    http.BaseRequest request, {
    required Duration timeout,
    required int maxBytes,
  }) async {
    if (timeout <= Duration.zero || maxBytes <= 0) {
      throw ArgumentError('Transport budgets must be positive.');
    }
    final client = _factory();
    final completed = Completer<http.StreamedResponse>();
    StreamSubscription<List<int>>? subscription;
    void fail(Object error, [StackTrace? stack]) {
      if (!completed.isCompleted) completed.completeError(error, stack);
    }

    _active[client] = () =>
        fail(http.ClientException('Request cancelled.', request.url));
    request.followRedirects = false;
    final timer = Timer(
      timeout,
      () => fail(TimeoutException('Response deadline exceeded.', timeout)),
    );

    Future<void> read() async {
      try {
        final response = await client.send(request);
        if (completed.isCompleted) {
          // A cancelled/timed-out header future must never start buffering data.
          unawaited(
            response.stream
                .listen(null, onError: (Object _) {})
                .cancel()
                .catchError((Object _) {}),
          );
          return;
        }
        if ((response.contentLength ?? 0) > maxBytes) {
          unawaited(
            response.stream
                .listen(null, onError: (Object _) {})
                .cancel()
                .catchError((Object _) {}),
          );
          throw const FormatException('Response too large.');
        }
        final bytes = BytesBuilder(copy: false);
        subscription = response.stream.listen(
          (chunk) {
            if (completed.isCompleted) return;
            if (bytes.length + chunk.length > maxBytes) {
              fail(const FormatException('Response too large.'));
            } else {
              bytes.add(chunk);
            }
          },
          onError: fail,
          onDone: () {
            if (completed.isCompleted) return;
            completed.complete(
              http.StreamedResponse(
                Stream.value(bytes.takeBytes()),
                response.statusCode,
                headers: response.headers,
                request: request,
                isRedirect: response.isRedirect,
              ),
            );
          },
        );
      } catch (error, stack) {
        fail(error, stack);
      }
    }

    unawaited(read());
    try {
      return await completed.future;
    } finally {
      timer.cancel();
      _active.remove(client);
      client.close();
      // A misbehaving stream's cleanup cannot extend the request deadline.
      unawaited(subscription?.cancel().catchError((Object _) {}));
    }
  }

  void cancelActive() {
    for (final cancel in _active.values.toList()) {
      cancel();
    }
  }

  @override
  void close() => cancelActive();
}
