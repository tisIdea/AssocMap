import 'package:flutter/material.dart';

import '../../app/configuration/app_config.dart';

class DataModeBanner extends StatelessWidget {
  const DataModeBanner({super.key, required this.mode});
  final DataMode mode;

  @override
  Widget build(BuildContext context) {
    final (icon, label, color) = switch (mode) {
      DataMode.mock => (
        Icons.science_outlined,
        'Demo mode · Synthetic data only',
        const Color(0xFFFDF0D4),
      ),
      DataMode.api => (
        Icons.cloud_off_outlined,
        'API mode · Integration awaiting verification',
        const Color(0xFFE2EDF7),
      ),
      DataMode.invalid => (
        Icons.error_outline,
        'Configuration error · No data source selected',
        const Color(0xFFFCE4E4),
      ),
    };
    return Container(
      width: double.infinity,
      color: color,
      padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 10),
      child: Row(
        children: [
          Icon(icon, size: 20),
          const SizedBox(width: 10),
          Expanded(child: Text(label, style: const TextStyle(fontSize: 13))),
        ],
      ),
    );
  }
}
