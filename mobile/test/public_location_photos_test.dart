import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:mobile/app/theme/app_theme.dart';
import 'package:mobile/features/public_map/public_location_photos.dart';

const location = '11111111-1111-4111-8111-111111111111';
const photo = '22222222-2222-4222-8222-222222222222';
const origin = 'https://backend.example.invalid/assocmap';
final pixel = base64Decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII=',
);

Map<String, dynamic> gallery({String? display, String caption = ''}) => {
  'photos': [
    {
      'id': photo,
      'caption': caption,
      'thumbnail_url': '$origin/map/$location/photos/$photo/thumbnail',
      'display_url': display ?? '$origin/map/$location/photos/$photo/display',
    },
  ],
};

http.Response json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

Future<void> open(
  WidgetTester tester,
  FutureOr<http.Response> Function(http.Request) reply, {
  double scale = 1,
}) async {
  await tester.pumpWidget(
    MaterialApp(
      theme: AppTheme.light,
      home: Scaffold(
        body: MediaQuery(
          data: const MediaQueryData(
            size: Size(320, 640),
          ).copyWith(textScaler: TextScaler.linear(scale)),
          child: SingleChildScrollView(
            child: SizedBox(
              width: 320,
              child: PublicLocationPhotos(
                baseUri: Uri.parse(origin),
                locationId: location,
                clientFactory: () =>
                    MockClient((request) async => reply(request)),
              ),
            ),
          ),
        ),
      ),
    ),
  );
}

void main() {
  testWidgets(
    'Gallery loads only public photos and enlarges without credentials or redirects',
    (tester) async {
      final requests = <http.Request>[];
      await open(tester, (request) {
        requests.add(request);
        if (request.url.path.endsWith('/photos')) {
          return json(gallery(caption: 'Published site'));
        }
        return http.Response.bytes(
          pixel,
          200,
          headers: {'content-type': 'image/png'},
        );
      });
      await tester.pumpAndSettle();
      expect(find.byType(Image), findsOneWidget);
      expect(find.text('Published site'), findsOneWidget);
      await tester.tap(find.byType(Image));
      await tester.pumpAndSettle();
      expect(find.byType(InteractiveViewer), findsOneWidget);
      expect(requests.last.url.path, endsWith('/display'));
      await tester.tap(find.byTooltip('Close photo'));
      await tester.pumpAndSettle();
      for (final request in requests) {
        expect(request.method, 'GET');
        expect(
          request.headers.keys.map((key) => key.toLowerCase()),
          isNot(contains('authorization')),
        );
        expect(request.followRedirects, isFalse);
        expect(request.url.origin, Uri.parse(origin).origin);
      }
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('Loading, empty, and retry states do not invent photographs', (
    tester,
  ) async {
    final pending = Completer<http.Response>();
    var calls = 0;
    await open(
      tester,
      (_) => ++calls == 1 ? pending.future : json({'photos': []}),
    );
    await tester.pump();
    expect(find.byType(LinearProgressIndicator), findsOneWidget);
    pending.complete(http.Response('Gateway unavailable', 503));
    await tester.pumpAndSettle();
    expect(find.text('Public photos unavailable'), findsOneWidget);
    await tester.tap(find.text('Retry photos'));
    await tester.pumpAndSettle();
    expect(find.text('No published photos for this location.'), findsOneWidget);
    expect(find.byType(Image), findsNothing);
  });

  for (final unsafe in [
    'https://other.example.invalid/photo.jpg',
    '$origin/admin/private-photo',
    '$origin/map/$location/photos/$photo/display?access_token=unsafe',
    'http://backend.example.invalid/assocmap/map/$location/photos/$photo/display',
  ]) {
    testWidgets('Unsafe photo URL fails before an image request: $unsafe', (
      tester,
    ) async {
      var calls = 0;
      await open(tester, (_) {
        calls++;
        return json(gallery(display: unsafe));
      });
      await tester.pumpAndSettle();
      expect(find.text('Public photos unavailable'), findsOneWidget);
      expect(calls, 1);
      expect(find.byType(Image), findsNothing);
    });
  }

  testWidgets(
    'Photo errors retry locally and long captions fit at 200 percent text',
    (tester) async {
      tester.view.physicalSize = const Size(320, 640);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      var imageCalls = 0;
      await open(tester, (request) {
        if (request.url.path.endsWith('/photos')) {
          return json(
            gallery(
              caption: List.filled(12, 'Published location caption').join(' '),
            ),
          );
        }
        if (++imageCalls == 1) return http.Response('', 404);
        return http.Response.bytes(
          pixel,
          200,
          headers: {'content-type': 'image/png'},
        );
      }, scale: 2);
      await tester.pumpAndSettle();
      expect(find.text('Photo unavailable'), findsOneWidget);
      await tester.tap(find.text('Retry photos'));
      await tester.pumpAndSettle();
      expect(imageCalls, 2);
      expect(find.byType(Image), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );
}
