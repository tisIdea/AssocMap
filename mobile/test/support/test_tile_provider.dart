import 'dart:convert';
import 'package:flutter/widgets.dart';
import 'package:flutter_map/flutter_map.dart';

// Synthetic tile transport prevents external imagery requests in widget tests.
class TestTileProvider extends TileProvider {
  @override
  ImageProvider getImage(
    TileCoordinates coordinates,
    TileLayer options,
  ) => MemoryImage(
    base64Decode(
      'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAJklEQVR4nGN88vbrfwYKABMlmkcNgAAmBgoB06gBDKNhwEB5GAAAjKgD5VwPcocAAAAASUVORK5CYII=',
    ),
  );
}
