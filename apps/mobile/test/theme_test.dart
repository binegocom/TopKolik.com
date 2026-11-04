import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:topkolik_mobile/core/theme/topkolik_theme.dart';

void main() {
  group('TopkolikTheme Design Tokens', () {
    test('enforces brand colors per Master Directive', () {
      expect(TopkolikTheme.pitchGreen, const Color(0xFF0B5D3B));
      expect(TopkolikTheme.electricAccent, const Color(0xFF7CFF4D));
      expect(TopkolikTheme.darkBg, const Color(0xFF0E1714));
      expect(TopkolikTheme.lightBg, const Color(0xFFF4F7F5));
    });

    test('dark theme is configured with correct color scheme', () {
      final darkTheme = TopkolikTheme.darkTheme;
      expect(darkTheme.brightness, Brightness.dark);
      expect(darkTheme.scaffoldBackgroundColor, TopkolikTheme.darkBg);
      expect(darkTheme.colorScheme.primary, TopkolikTheme.electricAccent);
      expect(darkTheme.colorScheme.secondary, TopkolikTheme.pitchGreen);
    });

    test('light theme is configured with correct color scheme', () {
      final lightTheme = TopkolikTheme.lightTheme;
      expect(lightTheme.brightness, Brightness.light);
      expect(lightTheme.scaffoldBackgroundColor, TopkolikTheme.lightBg);
      expect(lightTheme.colorScheme.primary, TopkolikTheme.pitchGreen);
    });
  });
}
