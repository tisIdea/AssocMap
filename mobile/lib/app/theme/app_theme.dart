import 'package:flutter/material.dart';

abstract final class AppTheme {
  static const blue = Color(0xFF0B4C8C);
  static const green = Color(0xFF1B624B);
  static const ink = Color(0xFF20382E);
  static const teal = green;
  static const canvas = Color(0xFFF5F7F4);
  static const muted = Color(0xFF5B6E63);
  static const line = Color(0xFFDCE5DD);
  static const tint = Color(0xFFE8F1EA);

  static ThemeData get light {
    final scheme =
        ColorScheme.fromSeed(
          seedColor: green,
          brightness: Brightness.light,
        ).copyWith(
          primary: green,
          secondary: green,
          surface: Colors.white,
          onSurface: ink,
          onSurfaceVariant: muted,
          outline: muted,
          outlineVariant: line,
        );
    final text = ThemeData.light().textTheme.apply(
      bodyColor: ink,
      displayColor: ink,
    );
    return ThemeData(
      fontFamily: 'Roboto',
      useMaterial3: true,
      colorScheme: scheme,
      scaffoldBackgroundColor: canvas,
      textTheme: text.copyWith(
        headlineSmall: text.headlineSmall?.copyWith(
          fontSize: 23,
          fontWeight: FontWeight.w700,
          height: 1.25,
        ),
        titleLarge: text.titleLarge?.copyWith(
          fontSize: 20,
          fontWeight: FontWeight.w700,
        ),
        titleMedium: text.titleMedium?.copyWith(
          fontSize: 16,
          fontWeight: FontWeight.w600,
        ),
        bodyLarge: text.bodyLarge?.copyWith(fontSize: 15, height: 1.45),
        bodyMedium: text.bodyMedium?.copyWith(fontSize: 14, height: 1.45),
        bodySmall: text.bodySmall?.copyWith(
          fontSize: 12,
          height: 1.4,
          color: muted,
        ),
      ),
      dividerTheme: const DividerThemeData(
        color: line,
        space: 24,
        thickness: 1,
      ),
      iconTheme: const IconThemeData(color: green),
      listTileTheme: const ListTileThemeData(iconColor: green),
      appBarTheme: const AppBarTheme(
        backgroundColor: Colors.white,
        foregroundColor: ink,
        centerTitle: false,
        scrolledUnderElevation: 0,
        titleTextStyle: TextStyle(
          color: ink,
          fontSize: 18,
          fontWeight: FontWeight.w700,
        ),
      ),
      cardTheme: CardThemeData(
        color: Colors.white,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(14),
          side: const BorderSide(color: line),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: Colors.white,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 16,
          vertical: 17,
        ),
        errorMaxLines: 4,
        helperMaxLines: 3,
        floatingLabelStyle: const TextStyle(color: green),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: green, width: 2),
        ),
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
          minimumSize: const Size(48, 50),
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size(48, 50),
          side: const BorderSide(color: line),
          padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(minimumSize: const Size(48, 48)),
      ),
      chipTheme: ChipThemeData(
        selectedColor: tint,
        backgroundColor: Colors.white,
        side: const BorderSide(color: line),
        labelStyle: const TextStyle(color: ink, fontSize: 13),
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      ),
      progressIndicatorTheme: const ProgressIndicatorThemeData(color: green),
      expansionTileTheme: const ExpansionTileThemeData(
        iconColor: green,
        textColor: ink,
        collapsedTextColor: ink,
        shape: Border(),
        collapsedShape: Border(),
      ),
      navigationBarTheme: const NavigationBarThemeData(
        backgroundColor: Colors.white,
        indicatorColor: tint,
        labelTextStyle: WidgetStatePropertyAll(
          TextStyle(fontSize: 12, color: ink, fontWeight: FontWeight.w600),
        ),
      ),
    );
  }
}
