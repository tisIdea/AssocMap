enum FailureKind { integrationUnavailable, invalidConfiguration, invalidData }

class AppFailure implements Exception {
  const AppFailure(this.kind, this.message);

  final FailureKind kind;
  final String message;
}
