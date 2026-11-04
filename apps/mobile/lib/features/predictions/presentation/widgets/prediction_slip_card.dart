import 'package:flutter/material.dart';
import '../../../../core/theme/topkolik_theme.dart';
import '../../../../core/models/topkolik_domain_models.dart';
import '../../../../core/localization/topkolik_localizations.dart';

class PredictionSlipCard extends StatefulWidget {
  final MatchFixture fixture;
  final MatchPrediction? currentPrediction;
  final bool isLocked;
  final Function({
    required String outcome,
    required int homeScore,
    required int awayScore,
    required String goalRange,
    required String firstScorer,
  }) onSubmit;

  const PredictionSlipCard({
    super.key,
    required this.fixture,
    this.currentPrediction,
    required this.isLocked,
    required this.onSubmit,
  });

  @override
  State<PredictionSlipCard> createState() => _PredictionSlipCardState();
}

class _PredictionSlipCardState extends State<PredictionSlipCard> {
  late String _outcome; // 'home', 'draw', 'away'
  late int _homeScore;
  late int _awayScore;
  late String _goalRange; // '0_1', '2_3', '4_plus'
  late String _firstScorer; // 'home', 'away', 'none'
  bool _hasSaved = false;

  @override
  void initState() {
    super.initState();
    final p = widget.currentPrediction;
    _outcome = p?.predictedOutcome ?? 'home';
    _homeScore = p?.predictedHomeScore ?? 2;
    _awayScore = p?.predictedAwayScore ?? 1;
    _goalRange = p?.predictedGoalRange ?? '2_3';
    _firstScorer = p?.predictedFirstScoringTeam ?? 'home';
    _hasSaved = p != null;
  }

  void _syncGoalRange() {
    final total = _homeScore + _awayScore;
    if (total <= 1) {
      _goalRange = '0_1';
    } else if (total <= 3) {
      _goalRange = '2_3';
    } else {
      _goalRange = '4_plus';
    }
  }

  void _syncOutcome() {
    if (_homeScore > _awayScore) {
      _outcome = 'home';
    } else if (_homeScore == _awayScore) {
      _outcome = 'draw';
    } else {
      _outcome = 'away';
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = TopkolikLocalizations.of(context);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final settlement = widget.currentPrediction?.settlement;

    return Container(
      margin: const EdgeInsets.symmetric(vertical: 8),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: isDark ? TopkolikTheme.darkCard : TopkolikTheme.lightCard,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
          color: widget.isLocked
              ? Colors.grey.withValues(alpha: 0.4)
              : (isDark ? TopkolikTheme.darkBorder : TopkolikTheme.lightBorder),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Header + Lock Status
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      l10n.translate('prediction_title'),
                      style: TextStyle(
                        fontSize: 16,
                        fontWeight: FontWeight.w800,
                        color: isDark ? TopkolikTheme.textPrimaryDark : TopkolikTheme.textPrimaryLight,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      l10n.translate('prediction_subtitle'),
                      style: TextStyle(
                        fontSize: 11,
                        color: isDark ? TopkolikTheme.textMutedDark : TopkolikTheme.textMutedLight,
                      ),
                    ),
                  ],
                ),
              ),
              if (widget.isLocked)
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: Colors.grey.withValues(alpha: 0.2),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(Icons.lock_rounded, size: 12, color: Colors.grey),
                      const SizedBox(width: 4),
                      Text(
                        l10n.translate('scheduled'),
                        style: const TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: Colors.grey),
                      ),
                    ],
                  ),
                ),
            ],
          ),

          const SizedBox(height: 16),

          // Exact Score Pickers
          Text(
            l10n.translate('predict_exact_score'),
            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 8),
          Container(
            padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 16),
            decoration: BoxDecoration(
              color: isDark ? TopkolikTheme.darkSurface : TopkolikTheme.lightBg,
              borderRadius: BorderRadius.circular(12),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceAround,
              children: [
                _buildTeamScoreSelector(
                  teamName: widget.fixture.homeTeamName,
                  score: _homeScore,
                  onChanged: widget.isLocked
                      ? null
                      : (newVal) {
                          setState(() {
                            _homeScore = newVal;
                            _syncGoalRange();
                            _syncOutcome();
                          });
                        },
                ),
                Text(
                  ':',
                  style: TextStyle(
                    fontSize: 24,
                    fontWeight: FontWeight.w900,
                    color: isDark ? TopkolikTheme.textMutedDark : TopkolikTheme.textMutedLight,
                  ),
                ),
                _buildTeamScoreSelector(
                  teamName: widget.fixture.awayTeamName,
                  score: _awayScore,
                  onChanged: widget.isLocked
                      ? null
                      : (newVal) {
                          setState(() {
                            _awayScore = newVal;
                            _syncGoalRange();
                            _syncOutcome();
                          });
                        },
                ),
              ],
            ),
          ),

          const SizedBox(height: 14),

          // 1-X-2 Outcome Chips
          Text(
            l10n.translate('predict_outcome'),
            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              _buildChoiceChip(
                label: '1 (${widget.fixture.homeTeamName})',
                isSelected: _outcome == 'home',
                onTap: widget.isLocked ? null : () => setState(() => _outcome = 'home'),
              ),
              const SizedBox(width: 8),
              _buildChoiceChip(
                label: 'X (Beraberlik)',
                isSelected: _outcome == 'draw',
                onTap: widget.isLocked ? null : () => setState(() => _outcome = 'draw'),
              ),
              const SizedBox(width: 8),
              _buildChoiceChip(
                label: '2 (${widget.fixture.awayTeamName})',
                isSelected: _outcome == 'away',
                onTap: widget.isLocked ? null : () => setState(() => _outcome = 'away'),
              ),
            ],
          ),

          const SizedBox(height: 14),

          // Goal Range & First Scorer
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      l10n.translate('predict_goal_range'),
                      style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700),
                    ),
                    const SizedBox(height: 6),
                    Wrap(
                      spacing: 4,
                      children: ['0_1', '2_3', '4_plus'].map((r) {
                        final label = r == '0_1' ? '0-1' : (r == '2_3' ? '2-3' : '4+');
                        return _buildSmallChip(
                          label: label,
                          isSelected: _goalRange == r,
                          onTap: widget.isLocked ? null : () => setState(() => _goalRange = r),
                        );
                      }).toList(),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      l10n.translate('predict_first_scorer'),
                      style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700),
                    ),
                    const SizedBox(height: 6),
                    Wrap(
                      spacing: 4,
                      children: [
                        _buildSmallChip(
                          label: 'Ev',
                          isSelected: _firstScorer == 'home',
                          onTap: widget.isLocked ? null : () => setState(() => _firstScorer = 'home'),
                        ),
                        _buildSmallChip(
                          label: 'Dep',
                          isSelected: _firstScorer == 'away',
                          onTap: widget.isLocked ? null : () => setState(() => _firstScorer = 'away'),
                        ),
                        _buildSmallChip(
                          label: 'Yok',
                          isSelected: _firstScorer == 'none',
                          onTap: widget.isLocked ? null : () => setState(() => _firstScorer = 'none'),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),

          // Settlement Results Display
          if (settlement != null) ...[
            const SizedBox(height: 16),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: TopkolikTheme.pitchGreen.withValues(alpha: isDark ? 0.2 : 0.1),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: TopkolikTheme.electricAccent.withValues(alpha: 0.5)),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        l10n.translate('prediction_settled'),
                        style: const TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.w800,
                          color: TopkolikTheme.electricAccent,
                        ),
                      ),
                      Text(
                        '+${settlement.totalPoints} Puan',
                        style: const TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.w900,
                          color: TopkolikTheme.electricAccent,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 6),
                  Text(
                    'Sonuç: ${settlement.outcomePoints}p | Skor: ${settlement.exactScorePoints}p | Aralık: ${settlement.goalRangePoints}p | İlk Gol: ${settlement.firstScorerPoints}p',
                    style: TextStyle(
                      fontSize: 11,
                      color: isDark ? TopkolikTheme.textSecondaryDark : TopkolikTheme.textSecondaryLight,
                    ),
                  ),
                ],
              ),
            ),
          ],

          const SizedBox(height: 16),

          // Submit Button
          if (!widget.isLocked)
            SizedBox(
              width: double.infinity,
              height: 44,
              child: ElevatedButton(
                onPressed: () {
                  widget.onSubmit(
                    outcome: _outcome,
                    homeScore: _homeScore,
                    awayScore: _awayScore,
                    goalRange: _goalRange,
                    firstScorer: _firstScorer,
                  );
                  setState(() => _hasSaved = true);
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('Tahmininiz başarıyla kaydedildi!')),
                  );
                },
                style: ElevatedButton.styleFrom(
                  backgroundColor: TopkolikTheme.electricAccent,
                  foregroundColor: const Color(0xFF072B1A),
                  elevation: 0,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                ),
                child: Text(
                  _hasSaved ? 'Tahmini Güncelle' : l10n.translate('submit_prediction'),
                  style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w800),
                ),
              ),
            ),
        ],
      ),
    );
  }

  Widget _buildTeamScoreSelector({
    required String teamName,
    required int score,
    required ValueChanged<int>? onChanged,
  }) {
    return Column(
      children: [
        SizedBox(
          width: 100,
          child: Text(
            teamName,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700),
          ),
        ),
        const SizedBox(height: 6),
        Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            IconButton(
              icon: const Icon(Icons.remove_circle_outline, size: 20),
              onPressed: (onChanged != null && score > 0) ? () => onChanged(score - 1) : null,
            ),
            Text(
              '$score',
              style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w900),
            ),
            IconButton(
              icon: const Icon(Icons.add_circle_outline, size: 20),
              onPressed: (onChanged != null && score < 9) ? () => onChanged(score + 1) : null,
            ),
          ],
        ),
      ],
    );
  }

  Widget _buildChoiceChip({
    required String label,
    required bool isSelected,
    required VoidCallback? onTap,
  }) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Expanded(
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(10),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 160),
          padding: const EdgeInsets.symmetric(vertical: 8),
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: isSelected
                ? TopkolikTheme.pitchGreen
                : (isDark ? TopkolikTheme.darkSurface : TopkolikTheme.lightBg),
            borderRadius: BorderRadius.circular(10),
            border: Border.all(
              color: isSelected
                  ? TopkolikTheme.electricAccent
                  : (isDark ? TopkolikTheme.darkBorder : TopkolikTheme.lightBorder),
            ),
          ),
          child: Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              fontSize: 11,
              fontWeight: isSelected ? FontWeight.w800 : FontWeight.w600,
              color: isSelected
                  ? Colors.white
                  : (isDark ? TopkolikTheme.textSecondaryDark : TopkolikTheme.textSecondaryLight),
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildSmallChip({
    required String label,
    required bool isSelected,
    required VoidCallback? onTap,
  }) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(8),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
        decoration: BoxDecoration(
          color: isSelected
              ? TopkolikTheme.electricAccent.withValues(alpha: 0.2)
              : (isDark ? TopkolikTheme.darkSurface : TopkolikTheme.lightBg),
          borderRadius: BorderRadius.circular(8),
          border: Border.all(
            color: isSelected ? TopkolikTheme.electricAccent : Colors.transparent,
          ),
        ),
        child: Text(
          label,
          style: TextStyle(
            fontSize: 11,
            fontWeight: isSelected ? FontWeight.w800 : FontWeight.w600,
            color: isSelected
                ? TopkolikTheme.electricAccent
                : (isDark ? TopkolikTheme.textPrimaryDark : TopkolikTheme.textPrimaryLight),
          ),
        ),
      ),
    );
  }
}
