abstract final class SatelliteBasemap {
  // Public imagery is separate from authenticated Laravel business data.
  static const urlTemplate = String.fromEnvironment(
    'ASSOCMAP_SATELLITE_TILE_URL',
    defaultValue:
        'https://services.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
  );
  static const attribution = String.fromEnvironment(
    'ASSOCMAP_SATELLITE_ATTRIBUTION',
    defaultValue:
        'Imagery: Esri, Maxar, Earthstar Geographics, GIS User Community',
  );
}
