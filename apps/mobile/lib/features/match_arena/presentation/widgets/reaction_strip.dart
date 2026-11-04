import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import '../../../../core/theme/topkolik_theme.dart';
import '../../../../core/localization/topkolik_localizations.dart';

class ReactionStrip extends StatelessWidget {
  final Map<String, int> reactionTotals;
  final String? activeReaction;
  final ValueChanged<String> onSelectReaction;

  const ReactionStrip({
    super.key,
    required this.reactionTotals,
    this.activeReaction,
    required this.onSelectReaction,
  });

  static const List<Map<String, String>> _reactionTypes = [
    {'type': 'fire', 'emoji': '🔥'},
    {'type': 'hype', 'emoji': '⚡'},
    {'type': 'tactics', 'emoji': '👏'},
    {'type': 'shock', 'emoji': '😱'},
    {'type': 'sad', 'emoji': '💔'},
    {'type': 'protest', 'emoji': '📢'},
  ];

  @override
  Widget build(BuildContext context) {
    final l10n = TopkolikLocalizations.of(context);
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(
        color: isDark ? TopkolikTheme.darkCard : TopkolikTheme.lightCard,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
          color: isDark ? TopkolikTheme.darkBorder : TopkolikTheme.lightBorder,
        ),
      ),
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        physics: const BouncingScrollPhysics(),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: _reactionTypes.map((item) {
            final type = item['type']!;
            final emoji = item['emoji']!;
            final isSelected = activeReaction == type;
            final count = reactionTotals[type] ?? 0;
            final label = l10n.translate('reaction_$type');

            return Padding(
              padding: const EdgeInsets.only(right: 8),
              child: Tooltip(
                message: label,
                child: InkWell(
                  onTap: () => onSelectReaction(type),
                  borderRadius: BorderRadius.circular(12),
                  child: AnimatedContainer(
                    duration: const Duration(milliseconds: 200),
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                    decoration: BoxDecoration(
                      color: isSelected
                          ? TopkolikTheme.electricAccent.withValues(alpha: 0.18)
                          : (isDark ? TopkolikTheme.darkSurface : TopkolikTheme.lightBg),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(
                        color: isSelected
                            ? TopkolikTheme.electricAccent
                            : (isDark ? TopkolikTheme.darkBorder : TopkolikTheme.lightBorder),
                        width: isSelected ? 1.5 : 1,
                      ),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(
                          emoji,
                          style: const TextStyle(fontSize: 18),
                        ),
                        const SizedBox(width: 6),
                        Text(
                          '$count',
                          style: TextStyle(
                            fontSize: 12,
                            fontWeight: isSelected ? FontWeight.w800 : FontWeight.w600,
                            color: isSelected
                                ? (isDark ? TopkolikTheme.electricAccent : TopkolikTheme.pitchGreen)
                                : (isDark ? TopkolikTheme.textSecondaryDark : TopkolikTheme.textSecondaryLight),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ).animate(target: isSelected ? 1.0 : 0.0).scale(
                  begin: const Offset(1.0, 1.0),
                  end: const Offset(1.08, 1.08),
                  duration: const Duration(milliseconds: 180),
                  curve: Curves.easeOutBack,
                );
          }).toList(),
        ),
      ),
    );
  }
}
