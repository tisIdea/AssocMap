enum DataMode { mock, api, invalid }

class AppConfig {
  const AppConfig({DataMode mode = DataMode.mock})
    : _explicitMode = mode,
      _modeName = null;

  const AppConfig.fromEnvironment()
    : _modeName = const String.fromEnvironment(
        'ASSOCMAP_DATA_MODE',
        defaultValue: 'mock',
      ),
      _explicitMode = null;

  final String? _modeName;
  final DataMode? _explicitMode;

  DataMode get mode => _explicitMode ?? parseMode(_modeName ?? 'mock');

  static DataMode parseMode(String value) => switch (value) {
    'mock' => DataMode.mock,
    'api' => DataMode.api,
    _ => DataMode.invalid,
  };
}
