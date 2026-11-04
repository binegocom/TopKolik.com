import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import '../../../../core/theme/topkolik_theme.dart';
import '../../../../core/models/topkolik_domain_models.dart';
import '../../../../core/localization/topkolik_localizations.dart';

class ArenaPollCard extends StatelessWidget {
  final ArenaPoll poll;
  final ValueChanged<int> onVote;

  const ArenaPollCard({
    super.key,
    required this.poll,
    required this.onVote,
  });

  @override
  Widget build(BuildContext context) {
    final l10n = TopkolikLocalizations.of(context);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final totalVotes = poll.options.fold<int>(0, (sum, o) => sum + o.votesCount);
    final hasVoted = poll.myVoteOptionId != null;

    return Container(
      margin: const EdgeInsets.symmetric(vertical: 10),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: isDark ? TopkolikTheme.darkCard : TopkolikTheme.lightCard,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
          color: isDark ? TopkolikTheme.darkBorder : TopkolikTheme.lightBorder,
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                decoration: BoxDecoration(
                  color: TopkolikTheme.pitchGreen.withValues(alpha: 0.2),
                  borderRadius: BorderRadius.circular(6),
                ),
                child: Text(
                  l10n.translate('poll_title').toUpperCase(),
                  style: const TextStyle(
                    fontSize: 10,
                    fontWeight: FontWeight.w800,
                    letterSpacing: 0.5,
                    color: TopkolikTheme.electricAccent,
                  ),
                ),
              ),
              const Spacer(),
              if (poll.isClosed)
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                  decoration: BoxDecoration(
                    color: Colors.grey.withValues(alpha: 0.2),
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: const Text(
                    'KAPANDI',
                    style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: Colors.grey),
                  ),
                ),
            ],
          ),
          const SizedBox(height: 10),
          Text(
            poll.questionText,
            style: TextStyle(
              fontSize: 15,
              fontWeight: FontWeight.w700,
              color: isDark ? TopkolikTheme.textPrimaryDark : TopkolikTheme.textPrimaryLight,
            ),
          ),
          const SizedBox(height: 14),
          ...poll.options.map((opt) {
            final isMyChoice = poll.myVoteOptionId == opt.id;
            final double pct = totalVotes > 0 ? (opt.votesCount / totalVotes) : 0.0;
            final pctFormatted = (pct * 100).toStringAsFixed(1);

            return Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: InkWell(
                onTap: (poll.isClosed || hasVoted) ? null : () => onVote(opt.id),
                borderRadius: BorderRadius.circular(12),
                child: Container(
                  height: 44,
                  clipBehavior: Clip.antiAlias,
                  decoration: BoxDecoration(
                    color: isDark ? TopkolikTheme.darkSurface : TopkolikTheme.lightBg,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(
                      color: isMyChoice
                          ? TopkolikTheme.electricAccent
                          : (isDark ? TopkolikTheme.darkBorder : TopkolikTheme.lightBorder),
                      width: isMyChoice ? 1.5 : 1,
                    ),
                  ),
                  child: Stack(
                    children: [
                      // Animated percentage background bar
                      FractionallySizedBox(
                        alignment: Alignment.centerLeft,
                        widthFactor: pct,
                        child: Container(
                          decoration: BoxDecoration(
                            color: isMyChoice
                                ? TopkolikTheme.electricAccent.withValues(alpha: 0.25)
                                : TopkolikTheme.pitchGreen.withValues(alpha: isDark ? 0.25 : 0.12),
                          ),
                        ),
                      ),
                      Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 14),
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Flexible(
                              child: Row(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  if (isMyChoice)
                                    const Padding(
                                      padding: EdgeInsets.only(right: 6),
                                      child: Icon(
                                        Icons.check_circle_rounded,
                                        size: 16,
                                        color: TopkolikTheme.electricAccent,
                                      ),
                                    ),
                                  Flexible(
                                    child: Text(
                                      opt.label,
                                      overflow: TextOverflow.ellipsis,
                                      style: TextStyle(
                                        fontSize: 13,
                                        fontWeight: isMyChoice ? FontWeight.w700 : FontWeight.w500,
                                        color: isDark
                                            ? TopkolikTheme.textPrimaryDark
                                            : TopkolikTheme.textPrimaryLight,
                                      ),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                            Text(
                              '%$pctFormatted (${opt.votesCount})',
                              style: TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.w700,
                                color: isDark
                                    ? TopkolikTheme.textSecondaryDark
                                    : TopkolikTheme.textSecondaryLight,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            );
          }),
          const SizedBox(height: 4),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                'Toplam $totalVotes oy',
                style: TextStyle(
                  fontSize: 11,
                  color: isDark ? TopkolikTheme.textMutedDark : TopkolikTheme.textMutedLight,
                ),
              ),
              if (hasVoted)
                Text(
                  l10n.translate('voted'),
                  style: const TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.bold,
                    color: TopkolikTheme.electricAccent,
                  ),
                ),
            ],
          ),
        ],
      ),
    ).animate().fadeIn(duration: const Duration(milliseconds: 250));
  }
}
