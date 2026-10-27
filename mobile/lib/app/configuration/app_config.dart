import 'dart:convert';

enum DataMode { mock, api, invalid }

enum GisDataMode { mock, live, invalid }

class AppConfig {
  const AppConfig({
    DataMode mode = DataMode.mock,
    this._backendBaseUrl = '',
    this.supabaseUrl = '',
    this.supabasePublishableKey = '',
    GisDataMode? gisMode,
  }) : _explicitMode = mode,
       _modeName = null,
       _explicitGisMode = gisMode,
       _gisModeName = '';

  const AppConfig.fromEnvironment()
    : _modeName = const String.fromEnvironment(
        'ASSOCMAP_DATA_MODE',
        defaultValue: 'api',
      ),
      _explicitMode = null,
      _backendBaseUrl = const String.fromEnvironment(
        'ASSOCMAP_BACKEND_BASE_URL',
      ),
      supabaseUrl = const String.fromEnvironment('ASSOCMAP_SUPABASE_URL'),
      supabasePublishableKey = const String.fromEnvironment(
        'ASSOCMAP_SUPABASE_PUBLISHABLE_KEY',
      ),
      _explicitGisMode = null,
      _gisModeName = const String.fromEnvironment('ASSOCMAP_GIS_MODE');

  final String? _modeName;
  final DataMode? _explicitMode;
  final String _backendBaseUrl;
  final String supabaseUrl;
  final String supabasePublishableKey;
  final GisDataMode? _explicitGisMode;
  final String _gisModeName;

  DataMode get mode => _explicitMode ?? parseMode(_modeName ?? 'mock');

  GisDataMode get gisMode =>
      _explicitGisMode ??
      (_gisModeName.isEmpty
          ? switch (mode) {
              DataMode.mock => GisDataMode.mock,
              DataMode.api => GisDataMode.live,
              DataMode.invalid => GisDataMode.invalid,
            }
          : parseGisMode(_gisModeName));

  static GisDataMode parseGisMode(String value) => switch (value) {
    'mock' => GisDataMode.mock,
    'live' => GisDataMode.live,
    _ => GisDataMode.invalid,
  };

  Uri? get backendBaseUri {
    final value = _backendBaseUrl.trim();
    if (value.isEmpty) return null;
    final uri = Uri.tryParse(value);
    if (uri == null ||
        uri.scheme != 'https' ||
        uri.host.isEmpty ||
        uri.userInfo.isNotEmpty ||
        uri.hasQuery ||
        uri.hasFragment) {
      throw const FormatException(
        'Use an HTTPS backend URL without credentials, query, or fragment.',
      );
    }
    if (uri.path.split('/').contains('api')) {
      throw const FormatException(
        'Use the application origin without the API path.',
      );
    }
    return uri;
  }

  bool get authenticationConfigured {
    try {
      final auth = Uri.tryParse(supabaseUrl);
      if (mode != DataMode.api ||
          backendBaseUri == null ||
          auth == null ||
          auth.scheme != 'https' ||
          auth.host.isEmpty ||
          auth.userInfo.isNotEmpty ||
          auth.hasQuery ||
          auth.hasFragment ||
          !['', '/'].contains(auth.path)) {
        return false;
      }
      if (RegExp(
        r'^sb_publishable_[A-Za-z0-9_-]+$',
      ).hasMatch(supabasePublishableKey)) {
        return true;
      }
      final parts = supabasePublishableKey.split('.');
      if (parts.length != 3) return false;
      final claims = jsonDecode(
        utf8.decode(base64Url.decode(base64Url.normalize(parts[1]))),
      );
      return claims is Map && claims['role'] == 'anon';
    } catch (_) {
      return false;
    }
  }

  Uri? get identityUri {
    final base = backendBaseUri;
    if (base == null) return null;
    final prefix = base.path.replaceFirst(RegExp(r'/+$'), '');
    return base.replace(path: '$prefix/api/v1/me');
  }

  static DataMode parseMode(String value) => switch (value) {
    'mock' => DataMode.mock,
    'api' => DataMode.api,
    _ => DataMode.invalid,
  };
}
