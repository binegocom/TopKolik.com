import 'dart:ui';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_animate/flutter_animate.dart';
import '../theme/topkolik_theme.dart';

/// Physics-based spring scaling button per Flutter Motion Standards
class PressableScale extends StatefulWidget {
  final Widget child;
  final VoidCallback? onTap;
  final double scaleFactor;

  const PressableScale({
    super.key,
    required this.child,
    this.onTap,
    this.scaleFactor = 0.96,
  });

  @override
  State<PressableScale> createState() => _PressableScaleState();
}

class _PressableScaleState extends State<PressableScale>
    with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 120),
    reverseDuration: const Duration(milliseconds: 180),
  );

  late final Animation<double> _scale = Tween<double>(
    begin: 1.0,
    end: widget.scaleFactor,
  ).animate(CurvedAnimation(parent: _ctrl, curve: Curves.easeInOutCubic));

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTapDown: (_) {
        if (widget.onTap != null) {
          HapticFeedback.lightImpact();
          _ctrl.forward();
        }
      },
      onTapUp: (_) {
        _ctrl.reverse();
        widget.onTap?.call();
      },
      onTapCancel: () => _ctrl.reverse(),
      child: ScaleTransition(scale: _scale, child: widget.child),
    );
  }
}

/// Layered Glassmorphism Card with subtle border and blur
class GlassContainer extends StatelessWidget {
  final Widget child;
  final double blur;
  final double borderRadius;
  final EdgeInsetsGeometry? padding;
  final Color? borderColor;
  final Color? backgroundColor;
  final Gradient? gradient;

  const GlassContainer({
    super.key,
    required this.child,
    this.blur = 14.0,
    this.borderRadius = 20.0,
    this.padding,
    this.borderColor,
    this.backgroundColor,
    this.gradient,
  });

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(borderRadius),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: blur, sigmaY: blur),
        child: Container(
          padding: padding,
          decoration: BoxDecoration(
            color: backgroundColor ??
                TopkolikTheme.stadiumSurface.withValues(alpha: 0.78),
            gradient: gradient,
            borderRadius: BorderRadius.circular(borderRadius),
            border: Border.all(
              color: borderColor ?? Colors.white.withValues(alpha: 0.09),
              width: 1.15,
            ),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withValues(alpha: 0.18),
                blurRadius: 16,
                offset: const Offset(0, 6),
              ),
            ],
          ),
          child: child,
        ),
      ),
    );
  }
}

/// Procedural Vector Shield Crest for Football Clubs
class ClubCrestBadge extends StatelessWidget {
  final String shortName;
  final String primaryHex;
  final String secondaryHex;
  final double size;
  final bool isSelected;

  const ClubCrestBadge({
    super.key,
    required this.shortName,
    required this.primaryHex,
    required this.secondaryHex,
    this.size = 42,
    this.isSelected = false,
  });

  @override
  Widget build(BuildContext context) {
    final c1 = TopkolikTheme.parseHex(primaryHex);
    final c2 = TopkolikTheme.parseHex(
      secondaryHex,
      fallback: Colors.amberAccent,
    );

    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: LinearGradient(
          colors: [c1, c2],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        border: Border.all(
          color: isSelected
              ? TopkolikTheme.turfNeon
              : Colors.white.withValues(alpha: 0.35),
          width: isSelected ? 2.4 : 1.4,
        ),
        boxShadow: [
          BoxShadow(
            color: c1.withValues(alpha: 0.45),
            blurRadius: isSelected ? 14 : 8,
            spreadRadius: isSelected ? 1 : 0,
          ),
        ],
      ),
      alignment: Alignment.center,
      child: Container(
        width: size * 0.82,
        height: size * 0.82,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: TopkolikTheme.obsidianBg.withValues(alpha: 0.45),
        ),
        alignment: Alignment.center,
        padding: const EdgeInsets.all(2),
        child: FittedBox(
          fit: BoxFit.scaleDown,
          child: Text(
            shortName,
            style: TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w900,
              fontSize: size * 0.32,
              letterSpacing: 0.4,
            ),
          ),
        ),
      ),
    );
  }
}

/// Shimmer Skeleton Loading Placeholder
class SkeletonShimmerView extends StatelessWidget {
  const SkeletonShimmerView({super.key});

  @override
  Widget build(BuildContext context) {
    return ListView.separated(
      padding: const EdgeInsets.all(16),
      itemCount: 4,
      separatorBuilder: (_, _) => const SizedBox(height: 16),
      itemBuilder: (context, index) {
        return Container(
          height: index == 0 ? 190 : 120,
          decoration: BoxDecoration(
            color: TopkolikTheme.cardElevated,
            borderRadius: BorderRadius.circular(20),
            border: Border.all(color: Colors.white.withValues(alpha: 0.06)),
          ),
        )
            .animate(onPlay: (controller) => controller.repeat())
            .shimmer(
              duration: 1200.ms,
              color: Colors.white.withValues(alpha: 0.08),
            );
      },
    );
  }
}
