import 'dart:typed_data';

String _text(Object? value) {
  if (value is! String || value.trim().isEmpty) {
    throw const FormatException('Invalid application text.');
  }
  return value.trim();
}

String _uuid(Object? value) {
  final text = _text(value);
  if (!RegExp(
    r'^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$',
  ).hasMatch(text)) {
    throw const FormatException('Invalid application identifier.');
  }
  return text;
}

class MemberApplicationChoice {
  const MemberApplicationChoice({
    required this.id,
    required this.name,
    this.code,
    this.occupied = false,
  });
  final int id;
  final String name;
  final String? code;
  final bool occupied;
  factory MemberApplicationChoice.fromJson(
    Map<String, dynamic> json, {
    bool position = false,
  }) {
    final id = json['id'];
    if (id is! int || id <= 0 || (position && json['occupied'] is! bool)) {
      throw const FormatException('Invalid application choice.');
    }
    return MemberApplicationChoice(
      id: id,
      name: _text(json['name']),
      code: position ? _text(json['code']) : null,
      occupied: position && json['occupied'] == true,
    );
  }
}

class MemberApplicationOptions {
  const MemberApplicationOptions({
    required this.associationId,
    required this.associationName,
    required this.sexes,
    required this.positions,
    required this.classifications,
  });
  final String associationId;
  final String associationName;
  final List<MemberApplicationChoice> sexes, positions, classifications;

  factory MemberApplicationOptions.fromJson(Map<String, dynamic> data) {
    final association = data['association'];
    if (association is! Map<String, dynamic>) {
      throw const FormatException('Missing application scope.');
    }
    List<MemberApplicationChoice> choices(String key, {bool position = false}) {
      final rows = data[key];
      if (rows is! List || rows.length > 10000) {
        throw const FormatException('Invalid application choices.');
      }
      final ids = <int>{};
      return List.unmodifiable(
        rows.map((row) {
          if (row is! Map<String, dynamic>) {
            throw const FormatException('Invalid application choice.');
          }
          final item = MemberApplicationChoice.fromJson(
            row,
            position: position,
          );
          if (!ids.add(item.id)) {
            throw const FormatException('Duplicate application choice.');
          }
          return item;
        }),
      );
    }

    return MemberApplicationOptions(
      associationId: _uuid(association['id']),
      associationName: _text(association['name']),
      sexes: choices('sex_categories'),
      positions: choices('positions', position: true),
      classifications: choices('classifications'),
    );
  }
}

class MemberApplicationSummary {
  const MemberApplicationSummary({
    required this.id,
    required this.name,
    required this.status,
    required this.submittedAt,
    required this.hasPhoto,
    this.reviewedAt,
    this.rejectionReason,
  });
  final String id, name, status;
  final DateTime submittedAt;
  final DateTime? reviewedAt;
  final String? rejectionReason;
  final bool hasPhoto;
  factory MemberApplicationSummary.fromJson(Map<String, dynamic> data) {
    if (!const ['pending', 'approved', 'rejected'].contains(data['status']) ||
        data['has_photo'] is! bool ||
        (data['rejection_reason'] != null &&
            data['rejection_reason'] is! String)) {
      throw const FormatException('Invalid application status.');
    }
    return MemberApplicationSummary(
      id: _uuid(data['id']),
      name: _text(data['name']),
      status: data['status'] as String,
      submittedAt: DateTime.parse(_text(data['submitted_at'])),
      hasPhoto: data['has_photo'] as bool,
      reviewedAt: data['reviewed_at'] == null
          ? null
          : DateTime.parse(_text(data['reviewed_at'])),
      rejectionReason: data['rejection_reason'] as String?,
    );
  }
}

class MemberApplicationPage {
  MemberApplicationPage({
    required this.associationId,
    required List<MemberApplicationSummary> applications,
    required this.page,
    required this.lastPage,
    required this.total,
  }) : applications = List.unmodifiable(applications);
  final String associationId;
  final List<MemberApplicationSummary> applications;
  final int page, lastPage, total;
  factory MemberApplicationPage.fromJson(Map<String, dynamic> data) {
    final rows = data['applications'];
    final page = data['page'], last = data['last_page'], total = data['total'];
    if (rows is! List ||
        rows.length > 25 ||
        page is! int ||
        page < 1 ||
        last is! int ||
        last < 1 ||
        total is! int ||
        total < 0) {
      throw const FormatException('Invalid application page.');
    }
    final ids = <String>{};
    final applications = rows.map((row) {
      if (row is! Map<String, dynamic>) {
        throw const FormatException('Invalid application.');
      }
      final application = MemberApplicationSummary.fromJson(row);
      if (!ids.add(application.id)) {
        throw const FormatException('Duplicate application.');
      }
      return application;
    }).toList();
    return MemberApplicationPage(
      associationId: _uuid(data['association_id']),
      applications: applications,
      page: page,
      lastPage: last,
      total: total,
    );
  }
}

class MemberApplicationPhoto {
  MemberApplicationPhoto({required Uint8List bytes, required this.name})
    : bytes = Uint8List.fromList(bytes).asUnmodifiableView();
  final Uint8List bytes;
  final String name;
}
