import 'package:flutter/material.dart';
import '../../../../core/theme/topkolik_theme.dart';
import '../../../../core/models/topkolik_domain_models.dart';
import '../../../../core/localization/topkolik_localizations.dart';

class RankingsTabView extends StatefulWidget {
  final List<AnalystRanking> analysts;
  final List<ClubRanking> clubs;

  const RankingsTabView({
    super.key,
    required this.analysts,
    required this.clubs,
  });

  @override
  State<RankingsTabView> createState() => _RankingsTabViewState();
}

class _RankingsTabViewState extends State<RankingsTabView> {
  int _tabIndex = 0; // 0 = Analysts, 1 = Clubs

  @override
  Widget build(BuildContext context) {
    final l10n = TopkolikLocalizations.of(context);
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Column(
      children: [
        // Tab Segment Switcher
        Container(
          margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          padding: const EdgeInsets.all(4),
          decoration: BoxDecoration(
            color: isDark ? TopkolikTheme.darkSurface : const Color(0xFFE6EBE8),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Row(
            children: [
              Expanded(
                child: _buildSegmentButton(
                  title: l10n.translate('tab_analysts'),
                  icon: Icons.emoji_events_outlined,
                  isSelected: _tabIndex == 0,
                  onTap: () => setState(() => _tabIndex = 0),
                ),
              ),
              Expanded(
                child: _buildSegmentButton(
                  title: l10n.translate('tab_clubs'),
                  icon: Icons.shield_outlined,
                  isSelected: _tabIndex == 1,
                  onTap: () => setState(() => _tabIndex = 1),
                ),
              ),
            ],
          ),
        ),

        // Qualification / Info Banner for Clubs
        if (_tabIndex == 1)
          Container(
            margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            decoration: BoxDecoration(
              color: TopkolikTheme.infoBlue.withValues(alpha: isDark ? 0.15 : 0.08),
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: TopkolikTheme.infoBlue.withValues(alpha: 0.3)),
            ),
            child: Row(
              children: [
                const Icon(Icons.info_outline, size: 16, color: TopkolikTheme.infoBlue),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    l10n.translate('min_supporters_notice'),
                    style: TextStyle(
                      fontSize: 11,
                      color: isDark ? const Color(0xFF7CE4DA) : const Color(0xFF0C7C71),
                    ),
                  ),
                ),
              ],
            ),
          ),

        // List View
        Expanded(
          child: _tabIndex == 0
              ? _buildAnalystsList(isDark, l10n)
              : _buildClubsList(isDark, l10n),
        ),
      ],
    );
  }

  Widget _buildSegmentButton({
    required String title,
    required IconData icon,
    required bool isSelected,
    required VoidCallback onTap,
  }) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return GestureDetector(
      onTap: onTap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 180),
        padding: const EdgeInsets.symmetric(vertical: 8),
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: isSelected
              ? (isDark ? TopkolikTheme.darkCard : Colors.white)
              : Colors.transparent,
          borderRadius: BorderRadius.circular(10),
          boxShadow: isSelected
              ? [
                  BoxShadow(
                    color: Colors.black.withValues(alpha: 0.08),
                    blurRadius: 4,
                    offset: const Offset(0, 1),
                  )
                ]
              : null,
        ),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(
              icon,
              size: 16,
              color: isSelected
                  ? TopkolikTheme.electricAccent
                  : (isDark ? TopkolikTheme.textMutedDark : TopkolikTheme.textMutedLight),
            ),
            const SizedBox(width: 6),
            Text(
              title,
              style: TextStyle(
                fontSize: 13,
                fontWeight: isSelected ? FontWeight.w800 : FontWeight.w600,
                color: isSelected
                    ? (isDark ? TopkolikTheme.textPrimaryDark : TopkolikTheme.pitchGreen)
                    : (isDark ? TopkolikTheme.textSecondaryDark : TopkolikTheme.textSecondaryLight),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildAnalystsList(bool isDark, TopkolikLocalizations l10n) {
    if (widget.analysts.isEmpty) {
      return Center(
        child: Text(
          'Henüz analist sıralaması oluşmadı.',
          style: TextStyle(
            color: isDark ? TopkolikTheme.textMutedDark : TopkolikTheme.textMutedLight,
          ),
        ),
      );
    }

    return ListView.separated(
      physics: const BouncingScrollPhysics(),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      itemCount: widget.analysts.length,
      separatorBuilder: (_, _) => const SizedBox(height: 8),
      itemBuilder: (context, index) {
        final a = widget.analysts[index];
        final isTop3 = a.rank <= 3;

        return Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: isDark ? TopkolikTheme.darkCard : TopkolikTheme.lightCard,
            borderRadius: BorderRadius.circular(14),
            border: Border.all(
              color: isTop3
                  ? TopkolikTheme.electricAccent.withValues(alpha: 0.4)
                  : (isDark ? TopkolikTheme.darkBorder : TopkolikTheme.lightBorder),
            ),
          ),
          child: Row(
            children: [
              // Rank Medal / Number
              Container(
                width: 32,
                height: 32,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: isTop3
                      ? TopkolikTheme.electricAccent.withValues(alpha: 0.2)
                      : (isDark ? TopkolikTheme.darkSurface : TopkolikTheme.lightBg),
                  shape: BoxShape.circle,
                ),
                child: Text(
                  '${a.rank}',
                  style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w900,
                    color: isTop3
                        ? TopkolikTheme.electricAccent
                        : (isDark ? TopkolikTheme.textSecondaryDark : TopkolikTheme.textSecondaryLight),
                  ),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      a.displayName,
                      style: TextStyle(
                        fontSize: 14,
                        fontWeight: FontWeight.w800,
                        color: isDark ? TopkolikTheme.textPrimaryDark : TopkolikTheme.textPrimaryLight,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      '${a.settledPredictionsCount} Tahmin • %${a.outcomeAccuracyPct.toStringAsFixed(1)} Başarı • ${a.exactScoreHits} Tam İsabet',
                      style: TextStyle(
                        fontSize: 11,
                        color: isDark ? TopkolikTheme.textMutedDark : TopkolikTheme.textMutedLight,
                      ),
                    ),
                    if (a.badges.isNotEmpty) ...[
                      const SizedBox(height: 6),
                      Wrap(
                        spacing: 4,
                        children: a.badges.map((b) {
                          return Container(
                            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                            decoration: BoxDecoration(
                              color: TopkolikTheme.pitchGreen.withValues(alpha: 0.2),
                              borderRadius: BorderRadius.circular(6),
                            ),
                            child: Text(
                              _getBadgeLabel(b, l10n),
                              style: const TextStyle(
                                fontSize: 9,
                                fontWeight: FontWeight.bold,
                                color: TopkolikTheme.electricAccent,
                              ),
                            ),
                          );
                        }).toList(),
                      ),
                    ],
                  ],
                ),
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    '${a.totalPoints}',
                    style: const TextStyle(
                      fontSize: 18,
                      fontWeight: FontWeight.w900,
                      color: TopkolikTheme.electricAccent,
                    ),
                  ),
                  Text(
                    'Puan',
                    style: TextStyle(
                      fontSize: 10,
                      fontWeight: FontWeight.w600,
                      color: isDark ? TopkolikTheme.textMutedDark : TopkolikTheme.textMutedLight,
                    ),
                  ),
                ],
              ),
            ],
          ),
        );
      },
    );
  }

  Widget _buildClubsList(bool isDark, TopkolikLocalizations l10n) {
    if (widget.clubs.isEmpty) {
      return Center(
        child: Text(
          'Henüz kulüp sıralaması oluşmadı.',
          style: TextStyle(
            color: isDark ? TopkolikTheme.textMutedDark : TopkolikTheme.textMutedLight,
          ),
        ),
      );
    }

    return ListView.separated(
      physics: const BouncingScrollPhysics(),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      itemCount: widget.clubs.length,
      separatorBuilder: (_, _) => const SizedBox(height: 8),
      itemBuilder: (context, index) {
        final c = widget.clubs[index];
        final isQualified = c.isQualified;

        return Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: isDark ? TopkolikTheme.darkCard : TopkolikTheme.lightCard,
            borderRadius: BorderRadius.circular(14),
            border: Border.all(
              color: isDark ? TopkolikTheme.darkBorder : TopkolikTheme.lightBorder,
            ),
          ),
          child: Row(
            children: [
              Container(
                width: 32,
                height: 32,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: isQualified
                      ? TopkolikTheme.pitchGreen.withValues(alpha: 0.3)
                      : Colors.grey.withValues(alpha: 0.15),
                  shape: BoxShape.circle,
                ),
                child: Text(
                  isQualified ? '${c.rank}' : '-',
                  style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w900,
                    color: isQualified ? TopkolikTheme.electricAccent : Colors.grey,
                  ),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Text(
                          c.teamName,
                          style: TextStyle(
                            fontSize: 14,
                            fontWeight: FontWeight.w800,
                            color: isDark ? TopkolikTheme.textPrimaryDark : TopkolikTheme.textPrimaryLight,
                          ),
                        ),
                        if (!isQualified) ...[
                          const SizedBox(width: 6),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                            decoration: BoxDecoration(
                              color: Colors.grey.withValues(alpha: 0.2),
                              borderRadius: BorderRadius.circular(4),
                            ),
                            child: const Text(
                              'Aday',
                              style: TextStyle(fontSize: 9, color: Colors.grey, fontWeight: FontWeight.bold),
                            ),
                          ),
                        ],
                      ],
                    ),
                    const SizedBox(height: 2),
                    Text(
                      '${c.activeSupportersCount} Taraftar • ${c.settledPredictionsCount} Tahmin',
                      style: TextStyle(
                        fontSize: 11,
                        color: isDark ? TopkolikTheme.textMutedDark : TopkolikTheme.textMutedLight,
                      ),
                    ),
                  ],
                ),
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    c.avgPointsPerPrediction.toStringAsFixed(2),
                    style: const TextStyle(
                      fontSize: 16,
                      fontWeight: FontWeight.w900,
                      color: TopkolikTheme.electricAccent,
                    ),
                  ),
                  Text(
                    'Ort. Puan',
                    style: TextStyle(
                      fontSize: 10,
                      fontWeight: FontWeight.w600,
                      color: isDark ? TopkolikTheme.textMutedDark : TopkolikTheme.textMutedLight,
                    ),
                  ),
                ],
              ),
            ],
          ),
        );
      },
    );
  }

  String _getBadgeLabel(String badge, TopkolikLocalizations l10n) {
    switch (badge) {
      case 'exact_score_sniper':
      case 'badge_sniper':
        return l10n.translate('badge_sniper');
      case 'master_analyst':
      case 'badge_analyst':
        return l10n.translate('badge_analyst');
      case 'matchday_regular':
      case 'badge_regular':
        return l10n.translate('badge_regular');
      default:
        return badge;
    }
  }
}
