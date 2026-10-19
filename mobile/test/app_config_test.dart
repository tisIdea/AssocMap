import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/app/configuration/app_config.dart';

void main() {
  test('GIS selection is independent of authentication and preserves legacy defaults', () {
    expect(const AppConfig().gisMode, GisDataMode.mock);
    expect(const AppConfig(mode: DataMode.api).gisMode, GisDataMode.live);
    expect(const AppConfig(mode: DataMode.invalid).gisMode, GisDataMode.invalid);
    const liveGis = AppConfig(gisMode: GisDataMode.live);
    expect(liveGis.mode, DataMode.mock);
    expect(liveGis.gisMode, GisDataMode.live);
    const offlineGis = AppConfig(mode: DataMode.api, gisMode: GisDataMode.mock);
    expect(offlineGis.mode, DataMode.api);
    expect(offlineGis.gisMode, GisDataMode.mock);
    expect(AppConfig.parseGisMode('live'), GisDataMode.live);
    expect(AppConfig.parseGisMode('mock'), GisDataMode.mock);
    expect(AppConfig.parseGisMode('api'), GisDataMode.invalid);
  });
  test('Unset backend URL leaves demo configuration independent of any server', () {
    const config = AppConfig();
    expect(config.mode, DataMode.mock);
    expect(config.backendBaseUri, isNull);
    expect(config.identityUri, isNull);
  });

  test('HTTPS identity URL preserves application prefix and port', () {
    for (final base in ['https://backend.example.invalid/assocmap', 'https://backend.example.invalid/assocmap/']) {
      final config = AppConfig(mode: DataMode.api, backendBaseUrl: base);
      expect(config.identityUri.toString(), 'https://backend.example.invalid/assocmap/api/v1/me');
    }
    const config = AppConfig(backendBaseUrl: 'https://localhost:8443');
    expect(config.identityUri.toString(), 'https://localhost:8443/api/v1/me');
  });

  test('Insecure and credential-bearing configuration is rejected without echoing it', () {
    for (final base in ['http://backend.example.invalid', '/relative', 'https://',
      'https://user:synthetic-password@backend.example.invalid',
      'https://backend.example.invalid?access_token=synthetic',
      'https://backend.example.invalid/#synthetic-token']) {
      final config = AppConfig(backendBaseUrl: base);
      expect(() => config.backendBaseUri, throwsFormatException);
    }
  });
}
