class GisSiteType {
  const GisSiteType({required this.id, required this.code, required this.name});

  final int id;
  final String code;
  final String name;

  factory GisSiteType.fromJson(Map<String, dynamic> json) {
    final id = json['id'];
    final code = json['code'];
    final name = json['name'];
    if (id is! int ||
        id <= 0 ||
        code is! String ||
        !RegExp(r'^[a-z][a-z0-9_]{0,49}$').hasMatch(code) ||
        name is! String ||
        name.trim().isEmpty ||
        name.runes.length > 100) {
      throw const FormatException('Invalid site classification.');
    }
    return GisSiteType(id: id, code: code, name: name.trim());
  }

  static List<GisSiteType> catalogFromJson(Map<String, dynamic> json) {
    final data = json['data'];
    if (data is! List) {
      throw const FormatException('Invalid site classification catalog.');
    }
    final ids = <int>{};
    final codes = <String>{};
    final types = <GisSiteType>[];
    for (final row in data) {
      if (row is! Map<String, dynamic>) {
        throw const FormatException('Invalid site classification row.');
      }
      final type = GisSiteType.fromJson(row);
      if (!ids.add(type.id) || !codes.add(type.code)) {
        throw const FormatException('Duplicate site classification.');
      }
      types.add(type);
    }
    return List.unmodifiable(types);
  }
}
