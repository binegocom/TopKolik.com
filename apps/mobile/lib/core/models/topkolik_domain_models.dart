class MatchFixture {
  final int id;
  final String status;
  final DateTime startsAt;
  final int homeTeamId;
  final String homeTeamName;
  final int awayTeamId;
  final String awayTeamName;
  final int? homeScore;
  final int? awayScore;

  const MatchFixture({
    required this.id,
    required this.status,
    required this.startsAt,
    required this.homeTeamId,
    required this.homeTeamName,
    required this.awayTeamId,
    required this.awayTeamName,
    this.homeScore,
    this.awayScore,
  });

  bool get isLive => status == 'live';
  bool get isFinished => status == 'finished';
  bool get isScheduled => status == 'scheduled';

  factory MatchFixture.fromJson(Map<String, dynamic> json) {
    return MatchFixture(
      id: json['id'] as int? ?? json['fixture_id'] as int? ?? 1,
      status: json['status'] as String? ?? 'live',
      startsAt: json['starts_at'] != null ? DateTime.parse(json['starts_at'] as String) : DateTime.now(),
      homeTeamId: json['home_team_id'] as int? ?? 1,
      homeTeamName: json['home_team_name'] as String? ?? 'Galatasaray',
      awayTeamId: json['away_team_id'] as int? ?? 2,
      awayTeamName: json['away_team_name'] as String? ?? 'Fenerbahçe',
      homeScore: json['home_score'] as int?,
      awayScore: json['away_score'] as int?,
    );
  }
}

class ArenaComment {
  final int id;
  final int userId;
  final String authorName;
  final int? parentCommentId;
  final String section;
  final String body;
  final String moderationState;
  final DateTime createdAt;

  const ArenaComment({
    required this.id,
    required this.userId,
    required this.authorName,
    this.parentCommentId,
    required this.section,
    required this.body,
    this.moderationState = 'visible',
    required this.createdAt,
  });

  factory ArenaComment.fromJson(Map<String, dynamic> json) {
    return ArenaComment(
      id: json['id'] as int,
      userId: json['user_id'] as int,
      authorName: json['author_name'] as String? ?? 'Taraftar',
      parentCommentId: json['parent_comment_id'] as int?,
      section: json['section'] as String? ?? 'neutral',
      body: json['body'] as String? ?? '',
      moderationState: json['moderation_state'] as String? ?? 'visible',
      createdAt: json['created_at'] != null ? DateTime.parse(json['created_at'] as String) : DateTime.now(),
    );
  }
}

class ArenaPollOption {
  final int id;
  final String optionKey;
  final String label;
  final int votesCount;

  const ArenaPollOption({
    required this.id,
    required this.optionKey,
    required this.label,
    required this.votesCount,
  });

  factory ArenaPollOption.fromJson(Map<String, dynamic> json) {
    return ArenaPollOption(
      id: json['id'] as int,
      optionKey: json['option_key'] as String,
      label: json['label'] as String,
      votesCount: json['votes_count'] as int? ?? 0,
    );
  }
}

class ArenaPoll {
  final int id;
  final String questionKey;
  final String questionText;
  final bool isClosed;
  final int? myVoteOptionId;
  final List<ArenaPollOption> options;

  const ArenaPoll({
    required this.id,
    required this.questionKey,
    required this.questionText,
    required this.isClosed,
    this.myVoteOptionId,
    required this.options,
  });

  factory ArenaPoll.fromJson(Map<String, dynamic> json) {
    final opts = (json['options'] as List<dynamic>?)
            ?.map((o) => ArenaPollOption.fromJson(o as Map<String, dynamic>))
            .toList() ??
        [];
    return ArenaPoll(
      id: json['id'] as int,
      questionKey: json['question_key'] as String? ?? '',
      questionText: json['question_text'] as String? ?? '',
      isClosed: json['is_closed'] as bool? ?? false,
      myVoteOptionId: json['my_vote_option_id'] as int?,
      options: opts,
    );
  }
}

class MatchArenaState {
  final int id;
  final int fixtureId;
  final String state;
  final bool postingEnabled;
  final int slowModeSeconds;
  final MatchFixture fixture;
  final Map<String, int> reactionTotals;
  final String? myReaction;
  final List<ArenaPoll> polls;
  final List<ArenaComment> comments;

  const MatchArenaState({
    required this.id,
    required this.fixtureId,
    required this.state,
    required this.postingEnabled,
    required this.slowModeSeconds,
    required this.fixture,
    required this.reactionTotals,
    this.myReaction,
    required this.polls,
    required this.comments,
  });
}

class MatchPrediction {
  final int id;
  final String predictedOutcome;
  final int predictedHomeScore;
  final int predictedAwayScore;
  final String predictedGoalRange;
  final String predictedFirstScoringTeam;
  final DateTime submittedAt;
  final PredictionSettlement? settlement;

  const MatchPrediction({
    required this.id,
    required this.predictedOutcome,
    required this.predictedHomeScore,
    required this.predictedAwayScore,
    required this.predictedGoalRange,
    required this.predictedFirstScoringTeam,
    required this.submittedAt,
    this.settlement,
  });

  factory MatchPrediction.fromJson(Map<String, dynamic> json) {
    return MatchPrediction(
      id: json['id'] as int? ?? 1,
      predictedOutcome: json['predicted_outcome'] as String? ?? 'home',
      predictedHomeScore: json['predicted_home_score'] as int? ?? 2,
      predictedAwayScore: json['predicted_away_score'] as int? ?? 1,
      predictedGoalRange: json['predicted_goal_range'] as String? ?? '2_3',
      predictedFirstScoringTeam: json['predicted_first_scoring_team'] as String? ?? 'home',
      submittedAt: json['submitted_at'] != null ? DateTime.parse(json['submitted_at'] as String) : DateTime.now(),
      settlement: json['settlement'] != null ? PredictionSettlement.fromJson(json['settlement'] as Map<String, dynamic>) : null,
    );
  }
}

class PredictionSettlement {
  final int outcomePoints;
  final int exactScorePoints;
  final int goalRangePoints;
  final int firstScorerPoints;
  final int totalPoints;
  final bool isExactScoreHit;
  final bool isOutcomeHit;

  const PredictionSettlement({
    required this.outcomePoints,
    required this.exactScorePoints,
    required this.goalRangePoints,
    required this.firstScorerPoints,
    required this.totalPoints,
    required this.isExactScoreHit,
    required this.isOutcomeHit,
  });

  factory PredictionSettlement.fromJson(Map<String, dynamic> json) {
    return PredictionSettlement(
      outcomePoints: json['outcome_points_awarded'] as int? ?? 0,
      exactScorePoints: json['exact_score_points_awarded'] as int? ?? 0,
      goalRangePoints: json['goal_range_points_awarded'] as int? ?? 0,
      firstScorerPoints: json['first_scorer_points_awarded'] as int? ?? 0,
      totalPoints: json['total_points'] as int? ?? 0,
      isExactScoreHit: json['is_exact_score_hit'] as bool? ?? false,
      isOutcomeHit: json['is_outcome_hit'] as bool? ?? false,
    );
  }
}

class AnalystRanking {
  final int rank;
  final int userId;
  final String displayName;
  final int totalPoints;
  final int exactScoreHits;
  final int outcomeHits;
  final int settledPredictionsCount;
  final double outcomeAccuracyPct;
  final List<String> badges;

  const AnalystRanking({
    required this.rank,
    required this.userId,
    required this.displayName,
    required this.totalPoints,
    required this.exactScoreHits,
    required this.outcomeHits,
    required this.settledPredictionsCount,
    required this.outcomeAccuracyPct,
    required this.badges,
  });

  factory AnalystRanking.fromJson(Map<String, dynamic> json) {
    return AnalystRanking(
      rank: json['rank'] as int? ?? 1,
      userId: json['user_id'] as int? ?? 1,
      displayName: json['display_name'] as String? ?? 'Analyst',
      totalPoints: json['total_points'] as int? ?? 0,
      exactScoreHits: json['exact_score_hits'] as int? ?? 0,
      outcomeHits: json['outcome_hits'] as int? ?? 0,
      settledPredictionsCount: json['settled_predictions_count'] as int? ?? 0,
      outcomeAccuracyPct: (json['outcome_accuracy_pct'] as num?)?.toDouble() ?? 0.0,
      badges: (json['badges'] as List<dynamic>?)?.map((b) => b.toString()).toList() ?? [],
    );
  }
}

class ClubRanking {
  final int rank;
  final int teamId;
  final String teamSlug;
  final String teamName;
  final int activeSupportersCount;
  final int minRequiredSupporters;
  final bool isQualified;
  final int settledPredictionsCount;
  final int totalPoints;
  final double avgPointsPerPrediction;

  const ClubRanking({
    required this.rank,
    required this.teamId,
    required this.teamSlug,
    required this.teamName,
    required this.activeSupportersCount,
    required this.minRequiredSupporters,
    required this.isQualified,
    required this.settledPredictionsCount,
    required this.totalPoints,
    required this.avgPointsPerPrediction,
  });

  factory ClubRanking.fromJson(Map<String, dynamic> json) {
    return ClubRanking(
      rank: json['rank'] as int? ?? 1,
      teamId: json['team_id'] as int? ?? 1,
      teamSlug: json['team_slug'] as String? ?? 'club',
      teamName: json['team_name'] as String? ?? 'Club',
      activeSupportersCount: json['active_supporters_count'] as int? ?? 0,
      minRequiredSupporters: json['min_required_supporters'] as int? ?? 5,
      isQualified: json['is_qualified_for_official_rank'] as bool? ?? false,
      settledPredictionsCount: json['settled_predictions_count'] as int? ?? 0,
      totalPoints: json['total_points'] as int? ?? 0,
      avgPointsPerPrediction: (json['avg_points_per_prediction'] as num?)?.toDouble() ?? 0.0,
    );
  }
}

class VoiceTribuneRoom {
  final int id;
  final int fixtureId;
  final String title;
  final String phaseTag;
  final int listenersCount;
  final int? clubId;
  final String? clubName;
  final String hostName;
  final bool isMicMuted;
  final bool isHandRaised;
  final bool isJoined;

  const VoiceTribuneRoom({
    required this.id,
    required this.fixtureId,
    required this.title,
    required this.phaseTag,
    required this.listenersCount,
    this.clubId,
    this.clubName,
    required this.hostName,
    this.isMicMuted = true,
    this.isHandRaised = false,
    this.isJoined = false,
  });

  VoiceTribuneRoom copyWith({
    int? listenersCount,
    bool? isMicMuted,
    bool? isHandRaised,
    bool? isJoined,
  }) {
    return VoiceTribuneRoom(
      id: id,
      fixtureId: fixtureId,
      title: title,
      phaseTag: phaseTag,
      listenersCount: listenersCount ?? this.listenersCount,
      clubId: clubId,
      clubName: clubName,
      hostName: hostName,
      isMicMuted: isMicMuted ?? this.isMicMuted,
      isHandRaised: isHandRaised ?? this.isHandRaised,
      isJoined: isJoined ?? this.isJoined,
    );
  }

  factory VoiceTribuneRoom.fromJson(Map<String, dynamic> json) {
    return VoiceTribuneRoom(
      id: json['id'] as int? ?? 1,
      fixtureId: json['fixture_id'] as int? ?? 1,
      title: json['title'] as String? ?? 'Canlı Taktik ve Tribün Odası',
      phaseTag: json['phase_tag'] as String? ?? 'MAÇ ANINDA CANLI',
      listenersCount: json['listeners_count'] as int? ?? 1,
      clubId: json['club_id'] as int?,
      clubName: json['club_name'] as String?,
      hostName: json['host_name'] as String? ?? 'Tribün Lideri',
    );
  }
}

class TacticalPlayerSlot {
  final int slotIndex;
  final String playerName;
  final int shirtNumber;
  final String position;
  final double xRatio;
  final double yRatio;
  final String clubPrimaryHex;

  const TacticalPlayerSlot({
    required this.slotIndex,
    required this.playerName,
    required this.shirtNumber,
    required this.position,
    required this.xRatio,
    required this.yRatio,
    required this.clubPrimaryHex,
  });
}

class TacticalLineup {
  final String formation;
  final String mentality;
  final String coachName;
  final List<TacticalPlayerSlot> starters;
  final List<TacticalPlayerSlot> bench;

  const TacticalLineup({
    required this.formation,
    required this.mentality,
    required this.coachName,
    required this.starters,
    required this.bench,
  });

  TacticalLineup copyWith({
    String? formation,
    String? mentality,
    List<TacticalPlayerSlot>? starters,
    List<TacticalPlayerSlot>? bench,
  }) {
    return TacticalLineup(
      formation: formation ?? this.formation,
      mentality: mentality ?? this.mentality,
      coachName: coachName,
      starters: starters ?? this.starters,
      bench: bench ?? this.bench,
    );
  }
}

class TimelineEvent {
  final int id;
  final int minute;
  final int? extraMinute;
  final String period;
  final String eventType;
  final String? playerName;
  final String? teamName;
  final String? detail;
  final bool isVarCancelled;

  const TimelineEvent({
    required this.id,
    required this.minute,
    this.extraMinute,
    required this.period,
    required this.eventType,
    this.playerName,
    this.teamName,
    this.detail,
    this.isVarCancelled = false,
  });

  factory TimelineEvent.fromJson(Map<String, dynamic> json) {
    return TimelineEvent(
      id: json['id'] as int? ?? 0,
      minute: json['minute'] as int? ?? 1,
      extraMinute: json['extra_minute'] as int?,
      period: json['period'] as String? ?? '1h',
      eventType: json['event_type'] as String? ?? 'goal',
      playerName: json['player_name'] as String?,
      teamName: json['team_name'] as String?,
      detail: json['detail'] as String?,
      isVarCancelled: json['is_var_cancelled'] as bool? ?? false,
    );
  }
}

class TeamStats {
  final double possessionPct;
  final int totalShots;
  final int shotsOnTarget;
  final int cornerKicks;
  final int fouls;
  final int yellowCards;
  final int redCards;
  final double passAccuracyPct;
  final double expectedGoalsXg;

  const TeamStats({
    required this.possessionPct,
    required this.totalShots,
    required this.shotsOnTarget,
    required this.cornerKicks,
    required this.fouls,
    required this.yellowCards,
    required this.redCards,
    required this.passAccuracyPct,
    required this.expectedGoalsXg,
  });

  factory TeamStats.fromJson(Map<String, dynamic> json) {
    return TeamStats(
      possessionPct: (json['possession_pct'] as num?)?.toDouble() ?? 50.0,
      totalShots: json['total_shots'] as int? ?? 0,
      shotsOnTarget: json['shots_on_target'] as int? ?? 0,
      cornerKicks: json['corner_kicks'] as int? ?? 0,
      fouls: json['fouls'] as int? ?? 0,
      yellowCards: json['yellow_cards'] as int? ?? 0,
      redCards: json['red_cards'] as int? ?? 0,
      passAccuracyPct: (json['pass_accuracy_pct'] as num?)?.toDouble() ?? 80.0,
      expectedGoalsXg: (json['expected_goals_xg'] as num?)?.toDouble() ?? 1.2,
    );
  }
}
