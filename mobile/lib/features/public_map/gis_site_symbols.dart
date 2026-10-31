import 'package:flutter/material.dart';

// Match the web symbol families; unrecognised and fallback codes stay neutral.
IconData gisSiteSymbol(String? code) => switch (code) {
  'fish_cage' => Icons.grid_on,
  'fish_pen' => Icons.fence,
  'seaweed_cultivation_site' => Icons.grass,
  'fishing_gear_site' => Icons.phishing,
  'post_harvest_facility' => Icons.warehouse_outlined,
  _ => Icons.location_on_outlined,
};

const gisPointColor = Color(0xFF0B4C8C);
const gisBoundaryColor = Color(0xFF7C3AED);
const gisSelectionColor = Color(0xFF15803D);
