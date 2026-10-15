import 'app_failure.dart';

enum GisFailureCode { configuration, invalidQuery, invalidResponse, timeout, network, http, rateLimited }

class PublicGisFailure extends AppFailure {
  PublicGisFailure(this.code, {this.statusCode}) : super(
    code == GisFailureCode.configuration ? FailureKind.invalidConfiguration
      : code == GisFailureCode.invalidResponse ? FailureKind.invalidData : FailureKind.integrationUnavailable,
    switch (code) {
      GisFailureCode.configuration => 'Live GIS is not configured. Set a valid HTTPS ASSOCMAP_BACKEND_BASE_URL.',
      GisFailureCode.invalidQuery => 'Use a search of at most 100 characters and a supported public location type.',
      GisFailureCode.invalidResponse => 'The public GIS service returned invalid data. No locations were displayed. Retry or contact the administrator.',
      GisFailureCode.timeout => 'The public GIS request timed out. Check your connection and retry.',
      GisFailureCode.network => 'The public GIS service could not be reached. Check connectivity, HTTPS access, or browser permissions, then retry.',
      GisFailureCode.http => 'The public GIS endpoint is unavailable. Retry later or contact the administrator.',
      GisFailureCode.rateLimited => 'Too many GIS requests. Wait before retrying.',
    });
  final GisFailureCode code;
  final int? statusCode;
}
