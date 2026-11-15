import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

import '../../core/networking/bounded_http_client.dart';

/// Reads only the existing anonymous, publication-filtered GIS gallery.
class PublicLocationPhotos extends StatefulWidget {
  const PublicLocationPhotos({
    super.key,
    required this.baseUri,
    required this.locationId,
    this.clientFactory,
  });

  final Uri baseUri;
  final String locationId;
  final http.Client Function()? clientFactory;

  @override
  State<PublicLocationPhotos> createState() => _PublicLocationPhotosState();
}

class _PublicLocationPhotosState extends State<PublicLocationPhotos> {
  late final _client = BoundedHttpClient(
    clientFactory: widget.clientFactory,
    timeout: const Duration(seconds: 15),
    maxBytes: 8 * 1024 * 1024,
  );
  late Future<List<({Uri thumbnail, Uri display, String caption})>> _photos;
  int _selected = 0;

  String get _prefix => widget.baseUri.path.replaceFirst(RegExp(r'/+$'), '');
  static final _uuid = RegExp(
    r'^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$',
  );

  @override
  void initState() {
    super.initState();
    _photos = _load();
  }

  @override
  void dispose() {
    _client.close();
    super.dispose();
  }

  Future<List<({Uri thumbnail, Uri display, String caption})>> _load() async {
    final base = widget.baseUri;
    if (base.scheme != 'https' ||
        base.host.isEmpty ||
        base.userInfo.isNotEmpty ||
        base.hasQuery ||
        base.hasFragment ||
        !_uuid.hasMatch(widget.locationId)) {
      throw const FormatException('Invalid public gallery.');
    }
    final uri = base.replace(path: '$_prefix/map/${widget.locationId}/photos');
    final response = await _client.sendWithBudget(
      http.Request('GET', uri)..headers['Accept'] = 'application/json',
      timeout: const Duration(seconds: 15),
      maxBytes: 64 * 1024,
    );
    if (response.statusCode != 200 ||
        response.headers['content-type']?.split(';').first.trim() !=
            'application/json') {
      throw const FormatException('Public gallery unavailable.');
    }
    final data = jsonDecode(await response.stream.bytesToString());
    final photos = data is Map ? data['photos'] : null;
    if (photos is! List || photos.length > 5) {
      throw const FormatException('Invalid public gallery.');
    }
    return photos
        .map((photo) {
          if (photo is! Map ||
              photo['id'] is! String ||
              !_uuid.hasMatch(photo['id'] as String)) {
            throw const FormatException('Invalid public photo.');
          }
          Uri url(String variant) {
            final value = photo['${variant}_url'];
            final uri = value is String ? Uri.tryParse(value) : null;
            if (uri == null ||
                uri.scheme != 'https' ||
                uri.origin != base.origin ||
                uri.userInfo.isNotEmpty ||
                uri.hasQuery ||
                uri.hasFragment ||
                uri.path !=
                    '$_prefix/map/${widget.locationId}/photos/${photo['id']}/$variant') {
              throw const FormatException('Invalid public photo URL.');
            }
            return uri;
          }

          final caption = photo['caption'];
          if (caption != null &&
              (caption is! String || caption.length > 2000)) {
            throw const FormatException('Invalid public photo caption.');
          }
          return (
            thumbnail: url('thumbnail'),
            display: url('display'),
            caption: (caption as String?)?.trim() ?? '',
          );
        })
        .toList(growable: false);
  }

  Future<Uint8List> _image(Uri uri) async {
    final response = await _client.send(
      http.Request('GET', uri)..headers['Accept'] = 'image/*',
    );
    if (response.statusCode != 200 ||
        !const [
          'image/jpeg',
          'image/png',
          'image/webp',
        ].contains(response.headers['content-type']?.split(';').first.trim())) {
      throw const FormatException('Public photo unavailable.');
    }
    return response.stream.toBytes();
  }

  void _open(Uri uri, String caption) {
    showDialog<void>(
      context: context,
      useSafeArea: false,
      builder: (context) => Dialog.fullscreen(
        child: Scaffold(
          appBar: AppBar(
            leading: IconButton(
              tooltip: 'Close photo',
              onPressed: () => Navigator.pop(context),
              icon: const Icon(Icons.close),
            ),
            title: const Text('Location photo'),
          ),
          body: SafeArea(
            child: Column(
              children: [
                Expanded(
                  child: _PhotoImage(load: () => _image(uri), zoomable: true),
                ),
                if (caption.isNotEmpty)
                  Flexible(
                    flex: 0,
                    child: ConstrainedBox(
                      constraints: BoxConstraints(
                        maxHeight: MediaQuery.sizeOf(context).height * .25,
                      ),
                      child: SingleChildScrollView(
                        padding: const EdgeInsets.all(16),
                        child: Text(caption),
                      ),
                    ),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) => FutureBuilder(
    future: _photos,
    builder: (context, snapshot) {
      if (snapshot.connectionState != ConnectionState.done) {
        return const Padding(
          padding: EdgeInsets.symmetric(vertical: 20),
          child: LinearProgressIndicator(
            semanticsLabel: 'Loading public photos',
          ),
        );
      }
      if (snapshot.hasError) {
        return _PhotoMessage(
          label: 'Public photos unavailable',
          onRetry: () => setState(() {
            _selected = 0;
            _photos = _load();
          }),
        );
      }
      final photos = snapshot.data!;
      if (photos.isEmpty) {
        return const Padding(
          padding: EdgeInsets.symmetric(vertical: 12),
          child: Text('No published photos for this location.'),
        );
      }
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            height: 210,
            child: PageView.builder(
              itemCount: photos.length,
              onPageChanged: (value) => setState(() => _selected = value),
              itemBuilder: (context, index) {
                final photo = photos[index];
                return Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 2),
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(12),
                    child: _PhotoImage(
                      load: () => _image(photo.thumbnail),
                      onOpen: () => _open(photo.display, photo.caption),
                    ),
                  ),
                );
              },
            ),
          ),
          const SizedBox(height: 8),
          Text(
            'Photo ${_selected + 1} of ${photos.length} · Tap to enlarge${photos.length > 1 ? ' · Swipe for more' : ''}',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          if (photos[_selected].caption.isNotEmpty) ...[
            const SizedBox(height: 6),
            Text(photos[_selected].caption),
          ],
        ],
      );
    },
  );
}

class _PhotoImage extends StatefulWidget {
  const _PhotoImage({required this.load, this.onOpen, this.zoomable = false});
  final Future<Uint8List> Function() load;
  final VoidCallback? onOpen;
  final bool zoomable;
  @override
  State<_PhotoImage> createState() => _PhotoImageState();
}

class _PhotoImageState extends State<_PhotoImage> {
  late Future<Uint8List> _bytes = widget.load();

  @override
  Widget build(BuildContext context) => FutureBuilder(
    future: _bytes,
    builder: (context, snapshot) {
      if (snapshot.connectionState != ConnectionState.done) {
        return const Center(
          child: CircularProgressIndicator(
            semanticsLabel: 'Loading location photo',
          ),
        );
      }
      Widget unavailable() => _PhotoMessage(
        label: 'Photo unavailable',
        onRetry: () => setState(() {
          _bytes = widget.load();
        }),
      );
      if (snapshot.hasError) return unavailable();
      final image = Image.memory(
        snapshot.data!,
        fit: BoxFit.contain,
        width: double.infinity,
        semanticLabel: 'Published location photo',
        errorBuilder: (_, _, _) => unavailable(),
      );
      return widget.zoomable
          ? InteractiveViewer(
              minScale: 1,
              maxScale: 4,
              child: Center(child: image),
            )
          : Semantics(
              button: true,
              label: 'Enlarge location photo',
              child: InkWell(onTap: widget.onOpen, child: image),
            );
    },
  );
}

class _PhotoMessage extends StatelessWidget {
  const _PhotoMessage({required this.label, required this.onRetry});
  final String label;
  final VoidCallback onRetry;
  @override
  Widget build(BuildContext context) => Center(
    child: SingleChildScrollView(
      padding: const EdgeInsets.all(12),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(label, textAlign: TextAlign.center),
          TextButton.icon(
            onPressed: onRetry,
            icon: const Icon(Icons.refresh),
            label: const Text('Retry photos'),
          ),
        ],
      ),
    ),
  );
}
