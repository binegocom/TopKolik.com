import 'dart:async';
import 'package:flutter/material.dart';
import '../../../../core/theme/topkolik_theme.dart';
import '../../../../core/localization/topkolik_localizations.dart';

class SlowModeBanner extends StatefulWidget {
  final int cooldownSeconds;
  final VoidCallback? onCooldownEnded;

  const SlowModeBanner({
    super.key,
    required this.cooldownSeconds,
    this.onCooldownEnded,
  });

  @override
  State<SlowModeBanner> createState() => _SlowModeBannerState();
}

class _SlowModeBannerState extends State<SlowModeBanner> {
  late int _remaining;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _remaining = widget.cooldownSeconds;
    if (_remaining > 0) {
      _startTimer();
    }
  }

  void _startTimer() {
    _timer?.cancel();
    _timer = Timer.periodic(const Duration(seconds: 1), (t) {
      if (!mounted) return;
      if (_remaining <= 1) {
        t.cancel();
        setState(() {
          _remaining = 0;
        });
        widget.onCooldownEnded?.call();
      } else {
        setState(() {
          _remaining--;
        });
      }
    });
  }

  @override
  void didUpdateWidget(covariant SlowModeBanner oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.cooldownSeconds != oldWidget.cooldownSeconds && widget.cooldownSeconds > 0) {
      setState(() {
        _remaining = widget.cooldownSeconds;
      });
      _startTimer();
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_remaining <= 0) return const SizedBox.shrink();

    final l10n = TopkolikLocalizations.of(context);
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Container(
      width: double.infinity,
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: TopkolikTheme.warningOrange.withValues(alpha: isDark ? 0.15 : 0.12),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(
          color: TopkolikTheme.warningOrange.withValues(alpha: 0.5),
        ),
      ),
      child: Row(
        children: [
          const Icon(
            Icons.timer_outlined,
            size: 16,
            color: TopkolikTheme.warningOrange,
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              '${l10n.translate('slow_mode_active')}${_remaining}s',
              style: TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w600,
                color: isDark ? const Color(0xFFFFB347) : const Color(0xFFC06D00),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
