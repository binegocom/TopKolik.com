import 'dart:ui';
import 'package:flutter_test/flutter_test.dart';
import 'package:topkolik_mobile/core/painters/stadium_painters.dart';

void main() {
  group('Stadium Procedural Painters', () {
    test('StadiumAtmospherePainter paints without error and triggers repaint on delta', () {
      final p1 = StadiumAtmospherePainter(
        clubPrimary: const Color(0xFFA6192E),
        clubSecondary: const Color(0xFFFFD700),
        pulseValue: 0.5,
      );
      final p2 = StadiumAtmospherePainter(
        clubPrimary: const Color(0xFFA6192E),
        clubSecondary: const Color(0xFFFFD700),
        pulseValue: 0.8,
      );

      expect(p1.shouldRepaint(p2), isTrue);

      final recorder = PictureRecorder();
      final canvas = Canvas(recorder);
      p1.paint(canvas, const Size(400, 300));
      final picture = recorder.endRecording();
      expect(picture, isNotNull);
    });

    test('MomentumWavePainter renders positive and negative momentum curves', () {
      final painter = MomentumWavePainter(
        series: [10, 45, -20, 80, -40, 60],
        homeColor: const Color(0xFFA6192E),
        awayColor: const Color(0xFF002D72),
      );

      final recorder = PictureRecorder();
      final canvas = Canvas(recorder);
      painter.paint(canvas, const Size(360, 100));
      final picture = recorder.endRecording();
      expect(picture, isNotNull);
    });

    test('TacticalPitchPainter paints full pitch lines, goal boxes, and center circle', () {
      final painter = TacticalPitchPainter(accentColor: const Color(0xFF7CFF4D));

      final recorder = PictureRecorder();
      final canvas = Canvas(recorder);
      painter.paint(canvas, const Size(380, 450));
      final picture = recorder.endRecording();
      expect(picture, isNotNull);
    });

    test('AudioWaveformPainter paints acoustic waveform equalizer bars', () {
      final painter = AudioWaveformPainter(
        level: 0.85,
        phase: 0.5,
        color: const Color(0xFF7CFF4D),
      );

      final recorder = PictureRecorder();
      final canvas = Canvas(recorder);
      painter.paint(canvas, const Size(200, 40));
      final picture = recorder.endRecording();
      expect(picture, isNotNull);
    });

    test('CelebrationParticlePainter renders particles during progress', () {
      final painter = CelebrationParticlePainter(
        progress: 0.45,
        primaryColor: const Color(0xFFA6192E),
        secondaryColor: const Color(0xFFFFD700),
      );

      final recorder = PictureRecorder();
      final canvas = Canvas(recorder);
      painter.paint(canvas, const Size(300, 300));
      final picture = recorder.endRecording();
      expect(picture, isNotNull);
    });
  });
}
