import 'package:flutter/material.dart';

abstract final class AppTheme {
  static const blue = Color(0xFF0B4C8C);
  static const ink = Color(0xFF172B46);
  static const teal = blue;
  static const canvas = Color(0xFFF0F4F9);
  static const muted = Color(0xFF60738C);
  static const line = Color(0xFFDCE5EF);

  static ThemeData get light {
    final scheme = ColorScheme.fromSeed(
      seedColor: blue,
    ).copyWith(primary: blue, secondary: blue, surface: Colors.white);
    return ThemeData(
      fontFamily: 'Roboto',
      useMaterial3: true,
      colorScheme: scheme,
      scaffoldBackgroundColor: canvas,
      appBarTheme: const AppBarTheme(
        backgroundColor: Colors.white,
        foregroundColor: ink,
        centerTitle: false,
        scrolledUnderElevation: 0,
      ),
      cardTheme: CardThemeData(
        color: Colors.white,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(16),
          side: const BorderSide(color: line),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: Colors.white,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: line),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: line),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size(48, 52),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size(48, 52),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
        ),
      ),
      navigationBarTheme: const NavigationBarThemeData(
        backgroundColor: Colors.white,
        indicatorColor: Color(0xFFE8F1FC),
        labelTextStyle: WidgetStatePropertyAll(
          TextStyle(fontSize: 11, color: muted),
        ),
      ),
    );
  }
}
