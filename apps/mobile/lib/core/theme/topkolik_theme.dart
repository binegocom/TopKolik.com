import 'package:flutter/material.dart';

/// Topkolik brand design system and tokens per ADR-006 & Master Directive:
/// Primary: #0B5D3B (Deep Pitch Green)
/// Accent: #7CFF4D (Electric Meadow Green)
/// Dark Neutral: #1F2933 (Graphite)
/// Dark Surface: #111A17
/// Dark Card: #162420
/// Light Surface: #F4F7F5
/// White: #FFFFFF
class TopkolikTheme {
  TopkolikTheme._();

  static const Color pitchGreen = Color(0xFF0B5D3B);
  static const Color pitchGreenDark = Color(0xFF073F28);
  static const Color electricAccent = Color(0xFF7CFF4D);
  static const Color electricAccentDark = Color(0xFF55C728);

  static const Color darkBg = Color(0xFF0E1714);
  static const Color darkSurface = Color(0xFF14201C);
  static const Color darkCard = Color(0xFF192823);
  static const Color darkBorder = Color(0xFF243B33);

  static const Color lightBg = Color(0xFFF4F7F5);
  static const Color lightSurface = Color(0xFFFFFFFF);
  static const Color lightCard = Color(0xFFFFFFFF);
  static const Color lightBorder = Color(0xFFE0E7E3);

  static const Color textPrimaryDark = Color(0xFFF4F7F5);
  static const Color textSecondaryDark = Color(0xFF9EBAAF);
  static const Color textMutedDark = Color(0xFF5E796F);

  static const Color textPrimaryLight = Color(0xFF13201B);
  static const Color textSecondaryLight = Color(0xFF486156);
  static const Color textMutedLight = Color(0xFF819A8F);

  static const Color warningOrange = Color(0xFFFF9F1C);
  static const Color dangerRed = Color(0xFFE71D36);
  static const Color infoBlue = Color(0xFF2EC4B6);

  // Luxury Motion and Stadium Visual aliases
  static const Color turfNeon = electricAccent;
  static const Color obsidianBg = darkBg;
  static const Color stadiumSurface = darkSurface;
  static const Color cardElevated = darkCard;
  static const Color liveCrimson = dangerRed;
  static const Color derbyGold = warningOrange;
  static const Color textSecondary = textSecondaryDark;
  static const Color textMuted = textMutedDark;

  static Color parseHex(String hex, {Color fallback = electricAccent}) {
    try {
      final clean = hex.replaceAll('#', '').trim();
      if (clean.length == 6) {
        return Color(int.parse('0xFF$clean'));
      } else if (clean.length == 8) {
        return Color(int.parse('0x$clean'));
      }
    } catch (_) {}
    return fallback;
  }

  static ThemeData get darkTheme {
    return ThemeData(
      useMaterial3: true,
      brightness: Brightness.dark,
      scaffoldBackgroundColor: darkBg,
      primaryColor: pitchGreen,
      colorScheme: const ColorScheme.dark(
        primary: electricAccent,
        onPrimary: Color(0xFF072B1A),
        secondary: pitchGreen,
        onSecondary: Colors.white,
        surface: darkSurface,
        onSurface: textPrimaryDark,
        error: dangerRed,
        onError: Colors.white,
      ),
      appBarTheme: const AppBarTheme(
        backgroundColor: darkSurface,
        elevation: 0,
        centerTitle: false,
        iconTheme: IconThemeData(color: textPrimaryDark),
        titleTextStyle: TextStyle(
          color: textPrimaryDark,
          fontSize: 18,
          fontWeight: FontWeight.w700,
          letterSpacing: -0.2,
        ),
      ),
      cardTheme: CardThemeData(
        color: darkCard,
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(14),
          side: const BorderSide(color: darkBorder, width: 1),
        ),
        margin: EdgeInsets.zero,
      ),
      bottomNavigationBarTheme: const BottomNavigationBarThemeData(
        backgroundColor: darkSurface,
        selectedItemColor: electricAccent,
        unselectedItemColor: textMutedDark,
        type: BottomNavigationBarType.fixed,
        elevation: 8,
      ),
      dividerColor: darkBorder,
      fontFamily: 'system-ui',
    );
  }

  static ThemeData get lightTheme {
    return ThemeData(
      useMaterial3: true,
      brightness: Brightness.light,
      scaffoldBackgroundColor: lightBg,
      primaryColor: pitchGreen,
      colorScheme: const ColorScheme.light(
        primary: pitchGreen,
        onPrimary: Colors.white,
        secondary: electricAccent,
        onSecondary: Color(0xFF072B1A),
        surface: lightSurface,
        onSurface: textPrimaryLight,
        error: dangerRed,
        onError: Colors.white,
      ),
      appBarTheme: const AppBarTheme(
        backgroundColor: lightSurface,
        elevation: 0,
        centerTitle: false,
        iconTheme: IconThemeData(color: textPrimaryLight),
        titleTextStyle: TextStyle(
          color: textPrimaryLight,
          fontSize: 18,
          fontWeight: FontWeight.w700,
          letterSpacing: -0.2,
        ),
      ),
      cardTheme: CardThemeData(
        color: lightCard,
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(14),
          side: const BorderSide(color: lightBorder, width: 1),
        ),
        margin: EdgeInsets.zero,
      ),
      bottomNavigationBarTheme: const BottomNavigationBarThemeData(
        backgroundColor: lightSurface,
        selectedItemColor: pitchGreen,
        unselectedItemColor: textMutedLight,
        type: BottomNavigationBarType.fixed,
        elevation: 8,
      ),
      dividerColor: lightBorder,
      fontFamily: 'system-ui',
    );
  }
}
