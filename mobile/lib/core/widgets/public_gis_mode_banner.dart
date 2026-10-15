import 'package:flutter/material.dart';

import '../../app/configuration/app_config.dart';

class PublicGisModeBanner extends StatelessWidget {
  const PublicGisModeBanner({super.key, required this.mode});
  final GisDataMode mode;

  @override
  Widget build(BuildContext context) {
    final (icon, label, color) = switch (mode) {
      GisDataMode.mock => (Icons.science_outlined, 'Demo mode · Synthetic data only', const Color(0xFFFDF0D4)),
      GisDataMode.live => (Icons.public, 'Live public GIS · Laravel data source', const Color(0xFFE2EDF7)),
      GisDataMode.invalid => (Icons.error_outline, 'Configuration error · GIS data source unavailable', const Color(0xFFFCE4E4)),
    };
    return Container(width: double.infinity, color: color,
      padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 10),
      child: Row(children: [Icon(icon, size: 20), const SizedBox(width: 10),
        Expanded(child: Text(label, style: const TextStyle(fontSize: 13)))]));
  }
}
