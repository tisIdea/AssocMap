import 'package:flutter/material.dart';

// Keep these icon families and colors aligned with web gis-site-symbols.js.
IconData gisSiteSymbol(String? code) => switch (code) {
  'fish_cage' => Icons.grid_on,
  'fish_pen' => Icons.fence,
  'culture_pond' => Icons.water,
  'seaweed_cultivation_site' => Icons.grass,
  'hatchery_nursery' => Icons.egg_outlined,
  'payao_installation' => Icons.anchor,
  'fishing_gear_site' => Icons.phishing,
  'post_harvest_facility' => Icons.warehouse_outlined,
  _ => Icons.location_on_outlined,
};

Color gisSiteColor(String? code) => switch (code) {
  'fish_cage' => const Color(0xFF2563EB),
  'fish_pen' => const Color(0xFF0F766E),
  'culture_pond' => const Color(0xFF0E7490),
  'seaweed_cultivation_site' => const Color(0xFF15803D),
  'hatchery_nursery' => const Color(0xFFB45309),
  'payao_installation' => const Color(0xFF7E22CE),
  'post_harvest_facility' => const Color(0xFF795548),
  'fishing_gear_site' => const Color(0xFF315F83),
  _ => gisUnclassifiedColor,
};

const gisUnclassifiedColor = Color(0xFF64748B);
const gisPointColor = Color(0xFF0B4C8C);
const gisBoundaryColor = Color(0xFF7C3AED);
const gisSelectionColor = Color(0xFF15803D);
