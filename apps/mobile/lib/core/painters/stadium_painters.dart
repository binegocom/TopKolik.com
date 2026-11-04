import 'dart:math' as math;
import 'package:flutter/material.dart';
import '../theme/topkolik_theme.dart';

/// 1. Stadium Floodlights & Layered Atmosphere CustomPainter
class StadiumAtmospherePainter extends CustomPainter {
  final Color clubPrimary;
  final Color clubSecondary;
  final double pulseValue;

  StadiumAtmospherePainter({
    required this.clubPrimary,
    required this.clubSecondary,
    required this.pulseValue,
  });

  @override
  void paint(Canvas canvas, Size size) {
    // Top-left floodlight beam
    final beamLeft = Paint()
      ..shader = RadialGradient(
        center: const Alignment(-0.85, -0.95),
        radius: 1.25 + (pulseValue * 0.12),
        colors: [
          clubPrimary.withValues(alpha: 0.24),
          clubSecondary.withValues(alpha: 0.08),
          Colors.transparent,
        ],
      ).createShader(Offset.zero & size);

    canvas.drawRect(Offset.zero & size, beamLeft);

    // Top-right turf neon floodlight beam
    final beamRight = Paint()
      ..shader = RadialGradient(
        center: const Alignment(0.85, -0.9),
        radius: 1.15,
        colors: [
          TopkolikTheme.turfNeon.withValues(alpha: 0.14),
          clubSecondary.withValues(alpha: 0.06),
          Colors.transparent,
        ],
      ).createShader(Offset.zero & size);

    canvas.drawRect(Offset.zero & size, beamRight);

    // Subtle stadium light trail arcs
    final arcPaint = Paint()
      ..color = Colors.white.withValues(alpha: 0.035)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.0;

    canvas.drawArc(
      Rect.fromCenter(
        center: Offset(size.width * 0.5, -40),
        width: size.width * 1.35,
        height: 340,
      ),
      0.15,
      math.pi - 0.3,
      false,
      arcPaint,
    );
  }

  @override
  bool shouldRepaint(covariant StadiumAtmospherePainter oldDelegate) {
    return oldDelegate.clubPrimary != clubPrimary ||
        oldDelegate.clubSecondary != clubSecondary ||
        (oldDelegate.pulseValue - pulseValue).abs() > 0.02;
  }
}

/// 2. Live Match Attack Momentum & Pressure Wave CustomPainter
class MomentumWavePainter extends CustomPainter {
  final List<int> series;
  final Color homeColor;
  final Color awayColor;
  final double animProgress;

  MomentumWavePainter({
    required this.series,
    required this.homeColor,
    required this.awayColor,
    this.animProgress = 1.0,
  });

  @override
  void paint(Canvas canvas, Size size) {
    final midY = size.height / 2;

    // Midline (Neutral axis)
    final axisPaint = Paint()
      ..color = Colors.white.withValues(alpha: 0.14)
      ..strokeWidth = 1.0;
    canvas.drawLine(Offset(0, midY), Offset(size.width, midY), axisPaint);

    if (series.isEmpty) return;

    final stepX = series.length > 1
        ? size.width / (series.length - 1)
        : size.width;
    final path = Path();
    final fillPath = Path();

    final points = <Offset>[];
    for (int i = 0; i < series.length; i++) {
      final normalized = (series[i].clamp(-100, 100) / 100.0) * animProgress;
      final x = i * stepX;
      final y = midY - (normalized * (size.height * 0.42));
      points.add(Offset(x, y));
    }

    path.moveTo(points.first.dx, points.first.dy);
    fillPath.moveTo(points.first.dx, midY);
    fillPath.lineTo(points.first.dx, points.first.dy);

    for (int i = 0; i < points.length - 1; i++) {
      final p0 = points[i];
      final p1 = points[i + 1];
      final ctrlX = (p0.dx + p1.dx) / 2;
      path.cubicTo(ctrlX, p0.dy, ctrlX, p1.dy, p1.dx, p1.dy);
      fillPath.cubicTo(ctrlX, p0.dy, ctrlX, p1.dy, p1.dx, p1.dy);
    }

    fillPath.lineTo(points.last.dx, midY);
    fillPath.close();

    final fillPaint = Paint()
      ..shader = LinearGradient(
        begin: Alignment.topCenter,
        end: Alignment.bottomCenter,
        colors: [
          homeColor.withValues(alpha: 0.42),
          Colors.transparent,
          awayColor.withValues(alpha: 0.42),
        ],
      ).createShader(Offset.zero & size);

    canvas.drawPath(fillPath, fillPaint);

    final strokePaint = Paint()
      ..shader = LinearGradient(
        begin: Alignment.topCenter,
        end: Alignment.bottomCenter,
        colors: [homeColor, TopkolikTheme.turfNeon, awayColor],
      ).createShader(Offset.zero & size)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2.6
      ..strokeCap = StrokeCap.round;

    canvas.drawPath(path, strokePaint);

    // Live pulsing tip dot
    final lastPt = points.last;
    final dotColor = lastPt.dy <= midY ? homeColor : awayColor;
    canvas.drawCircle(
      lastPt,
      7.0,
      Paint()..color = dotColor.withValues(alpha: 0.32),
    );
    canvas.drawCircle(lastPt, 3.8, Paint()..color = Colors.white);
  }

  @override
  bool shouldRepaint(covariant MomentumWavePainter oldDelegate) {
    return oldDelegate.series != series ||
        oldDelegate.animProgress != animProgress;
  }
}

/// 3. Interactive Tactical Football Pitch CustomPainter
class TacticalPitchPainter extends CustomPainter {
  final Color accentColor;

  TacticalPitchPainter({required this.accentColor});

  @override
  void paint(Canvas canvas, Size size) {
    final rect = Offset.zero & size;
    final rrect = RRect.fromRectAndRadius(rect, const Radius.circular(20));
    canvas.save();
    canvas.clipRRect(rrect);

    // Rich dark turf gradient
    final bgPaint = Paint()
      ..shader = const LinearGradient(
        begin: Alignment.topCenter,
        end: Alignment.bottomCenter,
        colors: [Color(0xFF0A2E1F), Color(0xFF082217), Color(0xFF061A11)],
      ).createShader(rect);
    canvas.drawRect(rect, bgPaint);

    // Alternating mowed turf stripes
    const stripeCount = 8;
    final stripeHeight = size.height / stripeCount;
    final stripePaint = Paint()..color = Colors.white.withValues(alpha: 0.03);
    for (int i = 0; i < stripeCount; i += 2) {
      canvas.drawRect(
        Rect.fromLTWH(0, i * stripeHeight, size.width, stripeHeight),
        stripePaint,
      );
    }

    final linePaint = Paint()
      ..color = Colors.white.withValues(alpha: 0.26)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.5;

    // Outer pitch boundary
    final pad = 14.0;
    final pitchRect = Rect.fromLTWH(
      pad,
      pad,
      size.width - pad * 2,
      size.height - pad * 2,
    );
    canvas.drawRect(pitchRect, linePaint);

    // Halfway line & Center circle
    final midY = size.height / 2;
    canvas.drawLine(
      Offset(pitchRect.left, midY),
      Offset(pitchRect.right, midY),
      linePaint,
    );
    canvas.drawCircle(
      Offset(size.width / 2, midY),
      size.width * 0.14,
      linePaint,
    );
    canvas.drawCircle(
      Offset(size.width / 2, midY),
      3.0,
      Paint()..color = Colors.white.withValues(alpha: 0.5),
    );

    // Top Penalty Box (Attacking goal)
    final boxWidth = pitchRect.width * 0.56;
    final boxHeight = pitchRect.height * 0.16;
    final topBox = Rect.fromLTWH(
      (size.width - boxWidth) / 2,
      pitchRect.top,
      boxWidth,
      boxHeight,
    );
    canvas.drawRect(topBox, linePaint);

    // Bottom Penalty Box (Defending goal)
    final bottomBox = Rect.fromLTWH(
      (size.width - boxWidth) / 2,
      pitchRect.bottom - boxHeight,
      boxWidth,
      boxHeight,
    );
    canvas.drawRect(bottomBox, linePaint);

    // Subtle tactical glow in center
    final tacticalGlow = Paint()
      ..shader = RadialGradient(
        colors: [accentColor.withValues(alpha: 0.14), Colors.transparent],
      ).createShader(Rect.fromCircle(center: Offset(size.width / 2, midY), radius: size.width * 0.35));
    canvas.drawCircle(Offset(size.width / 2, midY), size.width * 0.35, tacticalGlow);

    canvas.restore();
  }

  @override
  bool shouldRepaint(covariant TacticalPitchPainter oldDelegate) =>
      oldDelegate.accentColor != accentColor;
}

/// 4. Live Voice Tribune Audio Waveform Equalizer CustomPainter
class AudioWaveformPainter extends CustomPainter {
  final double level;
  final double phase;
  final Color color;

  AudioWaveformPainter({
    required this.level,
    required this.phase,
    required this.color,
  });

  @override
  void paint(Canvas canvas, Size size) {
    const barCount = 18;
    final spacing = size.width / barCount;
    final barWidth = spacing * 0.55;
    final paint = Paint()
      ..color = color
      ..strokeCap = StrokeCap.round
      ..style = PaintingStyle.fill;

    for (int i = 0; i < barCount; i++) {
      final wave = math.sin((i * 0.65) + phase * math.pi * 2).abs();
      final barHeight =
          (size.height * 0.18) + (size.height * 0.76 * wave * level);
      final x = i * spacing + (spacing - barWidth) / 2;
      final y = (size.height - barHeight) / 2;
      canvas.drawRRect(
        RRect.fromRectAndRadius(
          Rect.fromLTWH(x, y, barWidth, barHeight),
          const Radius.circular(4),
        ),
        paint,
      );
    }
  }

  @override
  bool shouldRepaint(covariant AudioWaveformPainter oldDelegate) {
    return oldDelegate.phase != phase || oldDelegate.level != level;
  }
}

/// 5. Goal & Fan Action Celebration Particle Burst CustomPainter
class CelebrationParticlePainter extends CustomPainter {
  final double progress;
  final Color primaryColor;
  final Color secondaryColor;

  CelebrationParticlePainter({
    required this.progress,
    required this.primaryColor,
    required this.secondaryColor,
  });

  @override
  void paint(Canvas canvas, Size size) {
    if (progress <= 0.0 || progress >= 1.0) return;

    final center = Offset(size.width / 2, size.height * 0.35);
    const particleCount = 36;
    final opacity = (1.0 - progress).clamp(0.0, 1.0);

    for (int i = 0; i < particleCount; i++) {
      final angle = (i * 2 * math.pi) / particleCount + (i % 3) * 0.18;
      final speed = 60.0 + (i % 5) * 42.0;
      final radius = speed * Curves.easeOutCubic.transform(progress);
      final gravityY = 85.0 * progress * progress;

      final dx = center.dx + math.cos(angle) * radius;
      final dy = center.dy + math.sin(angle) * radius + gravityY;

      final color = i % 3 == 0
          ? TopkolikTheme.turfNeon
          : (i.isEven ? primaryColor : secondaryColor);

      final paint = Paint()..color = color.withValues(alpha: opacity);
      final dotSize = (1.0 - progress * 0.5) * (i % 2 == 0 ? 5.5 : 3.8);

      canvas.drawCircle(Offset(dx, dy), dotSize, paint);
    }
  }

  @override
  bool shouldRepaint(covariant CelebrationParticlePainter oldDelegate) {
    return oldDelegate.progress != progress;
  }
}

