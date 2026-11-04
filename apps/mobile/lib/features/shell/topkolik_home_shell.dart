import 'package:flutter/material.dart';
import '../../core/theme/topkolik_theme.dart';
import '../../core/localization/topkolik_localizations.dart';
import '../../core/models/topkolik_domain_models.dart';
import '../../core/services/topkolik_api_service.dart';
import '../match_arena/presentation/widgets/reaction_strip.dart';
import '../match_arena/presentation/widgets/arena_poll_card.dart';
import '../match_arena/presentation/widgets/segmented_tribune_view.dart';
import '../predictions/presentation/widgets/prediction_slip_card.dart';
import '../rankings/presentation/widgets/rankings_tab_view.dart';
import '../voice_tribunes/presentation/widgets/voice_tribune_view.dart';
import '../tactical_board/presentation/widgets/tactical_pitch_board_view.dart';

class TopkolikHomeShell extends StatefulWidget {
  final ValueChanged<Locale>? onLocaleChanged;

  const TopkolikHomeShell({
    super.key,
    this.onLocaleChanged,
  });

  @override
  State<TopkolikHomeShell> createState() => _TopkolikHomeShellState();
}

class _TopkolikHomeShellState extends State<TopkolikHomeShell> with SingleTickerProviderStateMixin {
  int _currentIndex = 0;
  final TopkolikApiService _apiService = TopkolikApiService();

  late MatchArenaState _arenaState;
  MatchPrediction? _myPrediction;
  List<AnalystRanking> _analysts = [];
  List<ClubRanking> _clubs = [];
  List<VoiceTribuneRoom> _voiceRooms = [];
  late TacticalLineup _tacticalLineup;
  bool _isLoading = true;

  late AnimationController _pulseController;
  late Animation<double> _pulseAnimation;

  @override
  void initState() {
    super.initState();
    _pulseController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1200),
    )..repeat(reverse: true);
    _pulseAnimation = Tween<double>(begin: 0.4, end: 1.0).animate(
      CurvedAnimation(parent: _pulseController, curve: Curves.easeInOut),
    );

    _loadInitialData();
  }

  @override
  void dispose() {
    _pulseController.dispose();
    super.dispose();
  }

  Future<void> _loadInitialData() async {
    setState(() => _isLoading = true);
    final arena = await _apiService.getMatchArena(1);
    final prediction = await _apiService.getMyPrediction(1);
    final analysts = await _apiService.getAnalystRankings();
    final clubs = await _apiService.getClubRankings();
    final voiceRooms = await _apiService.getVoiceTribunes(1);
    final tacticalLineup = _apiService.getFallbackTacticalLineup();

    if (mounted) {
      setState(() {
        _arenaState = arena;
        _myPrediction = prediction;
        _analysts = analysts;
        _clubs = clubs;
        _voiceRooms = voiceRooms;
        _tacticalLineup = tacticalLineup;
        _isLoading = false;
      });
    }
  }

  void _handleCreateVoiceRoom(String title, String phaseTag) async {
    final newRoom = await _apiService.createVoiceTribune(
      fixtureId: _arenaState.fixtureId,
      title: title,
      phaseTag: phaseTag,
    );
    if (newRoom != null) {
      setState(() {
        _voiceRooms = [newRoom, ..._voiceRooms];
      });
    }
  }

  void _handleTriggerVoiceAction(int roomId, String action, String? emoji) async {
    setState(() {
      _voiceRooms = _voiceRooms.map((r) {
        if (r.id == roomId) {
          if (action == 'toggle-mic') {
            return r.copyWith(isMicMuted: !r.isMicMuted);
          } else if (action == 'toggle-hand') {
            return r.copyWith(isHandRaised: !r.isHandRaised);
          } else if (action == 'toggle-join') {
            return r.copyWith(isJoined: !r.isJoined, listenersCount: r.isJoined ? r.listenersCount - 1 : r.listenersCount + 1);
          }
        }
        return r;
      }).toList();
    });

    await _apiService.triggerVoiceAction(
      roomId: roomId,
      action: action,
      emoji: emoji,
    );
  }

  void _handleFormationChanged(String formation) {
    setState(() {
      _tacticalLineup = _tacticalLineup.copyWith(formation: formation);
    });
  }

  void _handleMentalityChanged(String mentality) {
    setState(() {
      _tacticalLineup = _tacticalLineup.copyWith(mentality: mentality);
    });
  }

  void _handlePlayerSubstituted(int starterIdx, int benchIdx) {
    setState(() {
      final sList = List<TacticalPlayerSlot>.from(_tacticalLineup.starters);
      final bList = List<TacticalPlayerSlot>.from(_tacticalLineup.bench);

      final sPos = sList.indexWhere((p) => p.slotIndex == starterIdx);
      final bPos = bList.indexWhere((p) => p.slotIndex == benchIdx);

      if (sPos != -1 && bPos != -1) {
        final starter = sList[sPos];
        final bench = bList[bPos];

        sList[sPos] = TacticalPlayerSlot(
          slotIndex: bench.slotIndex,
          playerName: bench.playerName,
          shirtNumber: bench.shirtNumber,
          position: bench.position,
          xRatio: starter.xRatio,
          yRatio: starter.yRatio,
          clubPrimaryHex: starter.clubPrimaryHex,
        );

        bList[bPos] = TacticalPlayerSlot(
          slotIndex: starter.slotIndex,
          playerName: starter.playerName,
          shirtNumber: starter.shirtNumber,
          position: starter.position,
          xRatio: bench.xRatio,
          yRatio: bench.yRatio,
          clubPrimaryHex: bench.clubPrimaryHex,
        );

        _tacticalLineup = _tacticalLineup.copyWith(starters: sList, bench: bList);
      }
    });
  }

  void _handleReaction(String reactionType) async {
    final current = _arenaState.myReaction;
    final newReaction = current == reactionType ? null : reactionType;
    final updatedTotals = Map<String, int>.from(_arenaState.reactionTotals);

    if (current != null && updatedTotals.containsKey(current)) {
      updatedTotals[current] = (updatedTotals[current]! - 1).clamp(0, 999999);
    }
    if (newReaction != null) {
      updatedTotals[newReaction] = (updatedTotals[newReaction] ?? 0) + 1;
    }

    setState(() {
      _arenaState = MatchArenaState(
        id: _arenaState.id,
        fixtureId: _arenaState.fixtureId,
        state: _arenaState.state,
        postingEnabled: _arenaState.postingEnabled,
        slowModeSeconds: _arenaState.slowModeSeconds,
        fixture: _arenaState.fixture,
        reactionTotals: updatedTotals,
        myReaction: newReaction,
        polls: _arenaState.polls,
        comments: _arenaState.comments,
      );
    });

    await _apiService.toggleReaction(
      fixtureId: _arenaState.fixtureId,
      reactionType: reactionType,
    );
  }

  void _handleVote(int pollId, int optionId) async {
    final updatedPolls = _arenaState.polls.map((p) {
      if (p.id == pollId) {
        final updatedOpts = p.options.map((o) {
          if (o.id == optionId) {
            return ArenaPollOption(
              id: o.id,
              optionKey: o.optionKey,
              label: o.label,
              votesCount: o.votesCount + 1,
            );
          }
          return o;
        }).toList();

        return ArenaPoll(
          id: p.id,
          questionKey: p.questionKey,
          questionText: p.questionText,
          isClosed: p.isClosed,
          myVoteOptionId: optionId,
          options: updatedOpts,
        );
      }
      return p;
    }).toList();

    setState(() {
      _arenaState = MatchArenaState(
        id: _arenaState.id,
        fixtureId: _arenaState.fixtureId,
        state: _arenaState.state,
        postingEnabled: _arenaState.postingEnabled,
        slowModeSeconds: _arenaState.slowModeSeconds,
        fixture: _arenaState.fixture,
        reactionTotals: _arenaState.reactionTotals,
        myReaction: _arenaState.myReaction,
        polls: updatedPolls,
        comments: _arenaState.comments,
      );
    });

    await _apiService.votePoll(
      fixtureId: _arenaState.fixtureId,
      pollId: pollId,
      optionId: optionId,
    );
  }

  void _handlePostComment(String section, String body, int? parentCommentId) async {
    final newComment = ArenaComment(
      id: DateTime.now().millisecondsSinceEpoch,
      userId: 42,
      authorName: 'Siz (Analist)',
      section: section,
      body: body,
      parentCommentId: parentCommentId,
      moderationState: 'visible',
      createdAt: DateTime.now(),
    );

    setState(() {
      _arenaState = MatchArenaState(
        id: _arenaState.id,
        fixtureId: _arenaState.fixtureId,
        state: _arenaState.state,
        postingEnabled: _arenaState.postingEnabled,
        slowModeSeconds: _arenaState.slowModeSeconds,
        fixture: _arenaState.fixture,
        reactionTotals: _arenaState.reactionTotals,
        myReaction: _arenaState.myReaction,
        polls: _arenaState.polls,
        comments: [newComment, ..._arenaState.comments],
      );
    });

    await _apiService.postComment(
      fixtureId: _arenaState.fixtureId,
      section: section,
      body: body,
      parentCommentId: parentCommentId,
    );
  }

  void _handleReportComment(int commentId, String reason) async {
    await _apiService.reportComment(
      fixtureId: _arenaState.fixtureId,
      commentId: commentId,
      reason: reason,
    );
  }

  void _handleBlockUser(int targetUserId) async {
    await _apiService.blockUser(targetUserId: targetUserId);
    setState(() {
      final updatedComments = _arenaState.comments.where((c) => c.userId != targetUserId).toList();
      _arenaState = MatchArenaState(
        id: _arenaState.id,
        fixtureId: _arenaState.fixtureId,
        state: _arenaState.state,
        postingEnabled: _arenaState.postingEnabled,
        slowModeSeconds: _arenaState.slowModeSeconds,
        fixture: _arenaState.fixture,
        reactionTotals: _arenaState.reactionTotals,
        myReaction: _arenaState.myReaction,
        polls: _arenaState.polls,
        comments: updatedComments,
      );
    });
  }

  void _handleSubmitPrediction({
    required String outcome,
    required int homeScore,
    required int awayScore,
    required String goalRange,
    required String firstScorer,
  }) async {
    final pred = await _apiService.submitPrediction(
      fixtureId: _arenaState.fixtureId,
      outcome: outcome,
      homeScore: homeScore,
      awayScore: awayScore,
      goalRange: goalRange,
      firstScorer: firstScorer,
    );
    setState(() {
      _myPrediction = pred;
    });
  }

  void _showLanguageSelector() {
    final l10n = TopkolikLocalizations.of(context);
    final languages = [
      {'code': 'tr', 'label': 'Türkçe', 'flag': '🇹🇷'},
      {'code': 'en', 'label': 'English', 'flag': '🇬🇧'},
      {'code': 'de', 'label': 'Deutsch', 'flag': '🇩🇪'},
      {'code': 'fr', 'label': 'Français', 'flag': '🇫🇷'},
      {'code': 'it', 'label': 'Italiano', 'flag': '🇮🇹'},
    ];

    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) {
        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 16),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  l10n.translate('language_select'),
                  style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                ),
                const SizedBox(height: 12),
                ...languages.map((l) {
                  final code = l['code']!;
                  final isCurrent = l10n.locale.languageCode == code;
                  return ListTile(
                    leading: Text(l['flag']!, style: const TextStyle(fontSize: 22)),
                    title: Text(l['label']!, style: TextStyle(fontWeight: isCurrent ? FontWeight.w800 : FontWeight.w500)),
                    trailing: isCurrent ? const Icon(Icons.check_circle_rounded, color: TopkolikTheme.electricAccent) : null,
                    onTap: () {
                      Navigator.of(ctx).pop();
                      widget.onLocaleChanged?.call(Locale(code));
                    },
                  );
                }),
              ],
            ),
          ),
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    final l10n = TopkolikLocalizations.of(context);
    final isDark = Theme.of(context).brightness == Brightness.dark;

    if (_isLoading) {
      return Scaffold(
        body: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const CircularProgressIndicator(color: TopkolikTheme.electricAccent),
              const SizedBox(height: 16),
              Text(
                'Topkolik.com Tribünü Yükleniyor...',
                style: TextStyle(
                  color: isDark ? TopkolikTheme.textSecondaryDark : TopkolikTheme.textSecondaryLight,
                ),
              ),
            ],
          ),
        ),
      );
    }

    return Scaffold(
      appBar: AppBar(
        title: Row(
          children: [
            Container(
              width: 8,
              height: 8,
              decoration: BoxDecoration(
                color: TopkolikTheme.electricAccent,
                shape: BoxShape.circle,
                boxShadow: [
                  BoxShadow(
                    color: TopkolikTheme.electricAccent.withValues(alpha: 0.6),
                    blurRadius: 6,
                    spreadRadius: 2,
                  ),
                ],
              ),
            ),
            const SizedBox(width: 8),
            const Text(
              'TOPKOLİK',
              style: TextStyle(
                fontSize: 18,
                fontWeight: FontWeight.w900,
                letterSpacing: 0.8,
                color: TopkolikTheme.electricAccent,
              ),
            ),
            const Text(
              '.COM',
              style: TextStyle(
                fontSize: 18,
                fontWeight: FontWeight.w500,
                letterSpacing: 0.8,
              ),
            ),
          ],
        ),
        actions: [
          IconButton(
            icon: Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
              decoration: BoxDecoration(
                color: isDark ? TopkolikTheme.darkCard : TopkolikTheme.lightCard,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(
                  color: isDark ? TopkolikTheme.darkBorder : TopkolikTheme.lightBorder,
                ),
              ),
              child: Text(
                l10n.locale.languageCode.toUpperCase(),
                style: const TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w800,
                  color: TopkolikTheme.electricAccent,
                ),
              ),
            ),
            onPressed: _showLanguageSelector,
          ),
          const SizedBox(width: 8),
        ],
      ),
      body: SafeArea(
        child: Column(
          children: [
            // Live Match Scoreboard Banner
            _buildLiveMatchBanner(isDark, l10n),

            // Tab Content
            Expanded(
              child: IndexedStack(
                index: _currentIndex,
                children: [
                  // Tab 0: Match Arena
                  _buildArenaTab(),

                  // Tab 1: Live Voice Tribunes (Social Fan Audio Rooms)
                  VoiceTribuneView(
                    rooms: _voiceRooms,
                    onCreateRoom: _handleCreateVoiceRoom,
                    onTriggerAction: _handleTriggerVoiceAction,
                  ),

                  // Tab 2: Interactive 11 vs 11 Pitch Tactical Board
                  TacticalPitchBoardView(
                    lineup: _tacticalLineup,
                    onFormationChanged: _handleFormationChanged,
                    onMentalityChanged: _handleMentalityChanged,
                    onPlayerSubstituted: _handlePlayerSubstituted,
                  ),

                  // Tab 3: Predictions
                  _buildPredictionsTab(),

                  // Tab 4: Fan League Rankings
                  RankingsTabView(
                    analysts: _analysts,
                    clubs: _clubs,
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
      bottomNavigationBar: Container(
        decoration: BoxDecoration(
          border: Border(
            top: BorderSide(
              color: isDark ? TopkolikTheme.darkBorder : TopkolikTheme.lightBorder,
            ),
          ),
        ),
        child: SafeArea(
          top: false,
          child: FittedBox(
            fit: BoxFit.scaleDown,
            child: SizedBox(
              width: MediaQuery.of(context).size.width,
              child: BottomNavigationBar(
                currentIndex: _currentIndex,
                onTap: (idx) => setState(() => _currentIndex = idx),
                items: [
                  BottomNavigationBarItem(
                    icon: const Icon(Icons.stadium_outlined),
                    activeIcon: const Icon(Icons.stadium_rounded),
                    label: l10n.translate('tab_arena'),
                  ),
                  BottomNavigationBarItem(
                    icon: const Icon(Icons.mic_none_rounded),
                    activeIcon: const Icon(Icons.mic_rounded),
                    label: l10n.translate('tab_voice'),
                  ),
                  BottomNavigationBarItem(
                    icon: const Icon(Icons.sports_soccer_outlined),
                    activeIcon: const Icon(Icons.sports_soccer_rounded),
                    label: l10n.translate('tab_tactics'),
                  ),
                  BottomNavigationBarItem(
                    icon: const Icon(Icons.fact_check_outlined),
                    activeIcon: const Icon(Icons.fact_check_rounded),
                    label: l10n.translate('tab_predictions'),
                  ),
                  BottomNavigationBarItem(
                    icon: const Icon(Icons.leaderboard_outlined),
                    activeIcon: const Icon(Icons.leaderboard_rounded),
                    label: l10n.translate('tab_rankings'),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildLiveMatchBanner(bool isDark, TopkolikLocalizations l10n) {
    final fix = _arenaState.fixture;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
      decoration: BoxDecoration(
        color: TopkolikTheme.pitchGreenDark,
        border: Border(
          bottom: BorderSide(
            color: isDark ? TopkolikTheme.darkBorder : TopkolikTheme.lightBorder,
          ),
        ),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          // Home Team
          Expanded(
            child: Row(
              children: [
                const CircleAvatar(
                  radius: 12,
                  backgroundColor: Color(0xFFA6192E), // GS Red
                  child: Text('GS', style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: Colors.white)),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    fix.homeTeamName,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w800,
                      color: Colors.white,
                    ),
                  ),
                ),
              ],
            ),
          ),

          // Live Score & Minute
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
            decoration: BoxDecoration(
              color: Colors.black.withValues(alpha: 0.35),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                FadeTransition(
                  opacity: _pulseAnimation,
                  child: Container(
                    width: 6,
                    height: 6,
                    decoration: const BoxDecoration(
                      color: TopkolikTheme.dangerRed,
                      shape: BoxShape.circle,
                    ),
                  ),
                ),
                const SizedBox(width: 6),
                Text(
                  '${fix.homeScore ?? 2} - ${fix.awayScore ?? 1}',
                  style: const TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.w900,
                    letterSpacing: 1.2,
                    color: TopkolikTheme.electricAccent,
                  ),
                ),
                const SizedBox(width: 6),
                const Text(
                  "74'",
                  style: TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.w700,
                    color: Colors.white70,
                  ),
                ),
              ],
            ),
          ),

          // Away Team
          Expanded(
            child: Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                Expanded(
                  child: Text(
                    fix.awayTeamName,
                    textAlign: TextAlign.end,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w800,
                      color: Colors.white,
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                const CircleAvatar(
                  radius: 12,
                  backgroundColor: Color(0xFF002D72), // FB Navy
                  child: Text('FB', style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: Colors.yellow)),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildArenaTab() {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 14),
      child: Column(
        children: [
          const SizedBox(height: 8),
          // Reaction Mood Strip
          ReactionStrip(
            reactionTotals: _arenaState.reactionTotals,
            activeReaction: _arenaState.myReaction,
            onSelectReaction: _handleReaction,
          ),

          // Active Poll (if available)
          if (_arenaState.polls.isNotEmpty)
            ArenaPollCard(
              poll: _arenaState.polls.first,
              onVote: (optId) => _handleVote(_arenaState.polls.first.id, optId),
            ),

          // Segmented Tribune and Comments
          Expanded(
            child: SegmentedTribuneView(
              fixture: _arenaState.fixture,
              comments: _arenaState.comments,
              postingEnabled: _arenaState.postingEnabled,
              slowModeSeconds: _arenaState.slowModeSeconds,
              userFavoriteClub: 'Galatasaray',
              onPostComment: _handlePostComment,
              onReportComment: _handleReportComment,
              onBlockUser: _handleBlockUser,
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildPredictionsTab() {
    return SingleChildScrollView(
      physics: const BouncingScrollPhysics(),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
      child: Column(
        children: [
          PredictionSlipCard(
            fixture: _arenaState.fixture,
            currentPrediction: _myPrediction,
            isLocked: false,
            onSubmit: _handleSubmitPrediction,
          ),
          const SizedBox(height: 12),
          // Rules & Transparency Card per ADR-016
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: TopkolikTheme.pitchGreenDark.withValues(alpha: 0.25),
              borderRadius: BorderRadius.circular(14),
              border: Border.all(
                color: TopkolikTheme.electricAccent.withValues(alpha: 0.2),
              ),
            ),
            child: const Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Icon(Icons.verified_user_outlined, size: 16, color: TopkolikTheme.electricAccent),
                    SizedBox(width: 8),
                    Text(
                      'Puanlama ve Şeffaflık Sistemi',
                      style: TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w800,
                        color: TopkolikTheme.electricAccent,
                      ),
                    ),
                  ],
                ),
                SizedBox(height: 8),
                Text(
                  '• Maç Sonucu (1-X-2): 3 Puan\n'
                  '• Tam Skor İsabeti: 5 Puan\n'
                  '• Toplam Gol Aralığı: 2 Puan\n'
                  '• İlk Golü Atan Takım: 2 Puan\n\n'
                  'Tahminler başlama vuruşunda kilitlenir. VAR iptalleri ve resmi hakem raporlarına göre kesinleşir. Gerçek para veya kumar kesinlikle yer almaz.',
                  style: TextStyle(fontSize: 12, height: 1.45, color: Colors.white70),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
