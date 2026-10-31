import 'dart:ui' as ui;
import 'package:flutter/services.dart';
import '../../data/models/member_application.dart';

typedef MemberPhotoPicker = Future<MemberApplicationPhoto?> Function();

Future<MemberApplicationPhoto?> pickMemberApplicationPhoto() async {
  final bytes = await const MethodChannel(
    'assocmap/member_photo',
  ).invokeMethod<Uint8List>('pick');
  if (bytes == null) return null;
  return validateMemberApplicationPhoto(bytes);
}

Future<MemberApplicationPhoto> validateMemberApplicationPhoto(
  Uint8List bytes,
) async {
  final png =
      bytes.length >= 8 &&
      bytes[0] == 137 &&
      bytes[1] == 80 &&
      bytes[2] == 78 &&
      bytes[3] == 71;
  final jpg =
      bytes.length >= 3 &&
      bytes[0] == 255 &&
      bytes[1] == 216 &&
      bytes[2] == 255;
  if (bytes.isEmpty || bytes.length > 2 * 1024 * 1024 || (!png && !jpg)) {
    throw const FormatException('Choose a JPEG or PNG photo of at most 2 MiB.');
  }
  final buffer = await ui.ImmutableBuffer.fromUint8List(bytes);
  ui.ImageDescriptor? image;
  try {
    image = await ui.ImageDescriptor.encoded(buffer);
    if (image.width > 2048 || image.height > 2048) {
      throw const FormatException(
        'Photo dimensions must not exceed 2048 by 2048 pixels.',
      );
    }
  } finally {
    image?.dispose();
    buffer.dispose();
  }
  return MemberApplicationPhoto(
    bytes: bytes,
    name: png ? 'applicant.png' : 'applicant.jpg',
  );
}
