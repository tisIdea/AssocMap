enum DataMode { mock, api, invalid }

enum GisDataMode { mock, live, invalid }

class AppConfig {
  const AppConfig({
    DataMode mode = DataMode.mock,
    this._backendBaseUrl = '',
    GisDataMode? gisMode,
  }) : _explicitMode = mode,
       _modeName = null,
       _explicitGisMode = gisMode,
       _gisModeName = '';

  const AppConfig.fromEnvironment()
    : _modeName = const String.fromEnvironment(
        'ASSOCMAP_DATA_MODE',
        defaultValue: 'mock',
      ),
      _explicitMode = null,
      _backendBaseUrl = const String.fromEnvironment(
        'ASSOCMAP_BACKEND_BASE_URL',
      ),
      _explicitGisMode = null,
      _gisModeName = const String.fromEnvironment('ASSOCMAP_GIS_MODE');

  final String? _modeName;
  final DataMode? _explicitMode;
  final String _backendBaseUrl;
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
    return uri;
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
