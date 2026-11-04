import 'dart:async';
import 'dart:convert';
import 'package:http/http.dart' as http;
import '../models/topkolik_domain_models.dart';

class TopkolikApiService {
  final String baseUrl;
  String? bearerToken;
  String currentLocale;

  TopkolikApiService({
    this.baseUrl = 'http://127.0.0.1:8000/api/v1',
    this.bearerToken,
    this.currentLocale = 'tr',
  });

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'Accept-Language': '$currentLocale,en;q=0.8',
        if (bearerToken != null) 'Authorization': 'Bearer $bearerToken',
      };

  Future<MatchArenaState> getMatchArena(int fixtureId) async {
    try {
      final res = await http.get(
        Uri.parse('$baseUrl/matches/$fixtureId/arena'),
        headers: _headers,
      ).timeout(const Duration(seconds: 3));

      if (res.statusCode == 200) {
        final data = jsonDecode(res.body)['arena'] as Map<String, dynamic>;
        final fix = MatchFixture.fromJson(data['fixture'] as Map<String, dynamic>);
        final polls = (data['polls'] as List<dynamic>?)
                ?.map((p) => ArenaPoll.fromJson(p as Map<String, dynamic>))
                .toList() ??
            [];
        final comments = (data['comments'] as List<dynamic>?)
                ?.map((c) => ArenaComment.fromJson(c as Map<String, dynamic>))
                .toList() ??
            [];
        final reactions = (data['reactions']['totals'] as Map<String, dynamic>?)?.map(
              (k, v) => MapEntry(k, v as int),
            ) ??
            {};

        return MatchArenaState(
          id: data['id'] as int? ?? 1,
          fixtureId: fixtureId,
          state: data['state'] as String? ?? 'live_open',
          postingEnabled: data['posting_enabled'] as bool? ?? true,
          slowModeSeconds: data['slow_mode_seconds'] as int? ?? 0,
          fixture: fix,
          reactionTotals: reactions,
          myReaction: data['reactions']['my_reaction'] as String?,
          polls: polls,
          comments: comments,
        );
      }
    } catch (_) {
      // Offline fallback state with rich derby atmosphere
    }

    return _fallbackArenaState(fixtureId);
  }

  Future<Map<String, dynamic>> postComment({
    required int fixtureId,
    required String section,
    required String body,
    int? parentCommentId,
  }) async {
    try {
      final payload = <String, dynamic>{
        'section': section,
        'body': body,
      };
      if (parentCommentId != null) {
        payload['parent_comment_id'] = parentCommentId;
      }

      final res = await http.post(
        Uri.parse('$baseUrl/matches/$fixtureId/arena/comments'),
        headers: _headers,
        body: jsonEncode(payload),
      ).timeout(const Duration(seconds: 3));

      if (res.statusCode == 201 || res.statusCode == 202) {
        return jsonDecode(res.body) as Map<String, dynamic>;
      } else {
        return {
          'error': true,
          'status': res.statusCode,
          'message': jsonDecode(res.body)['message'] ?? 'Error',
        };
      }
    } catch (_) {
      return {
        'comment': {
          'id': DateTime.now().millisecondsSinceEpoch,
          'section': section,
          'body': body,
          'moderation_state': 'visible',
          'created_at': DateTime.now().toIso8601String(),
        }
      };
    }
  }

  Future<bool> toggleReaction({
    required int fixtureId,
    required String reactionType,
  }) async {
    try {
      final res = await http.put(
        Uri.parse('$baseUrl/matches/$fixtureId/arena/reaction'),
        headers: _headers,
        body: jsonEncode({'reaction_type': reactionType}),
      ).timeout(const Duration(seconds: 3));
      return res.statusCode == 200;
    } catch (_) {
      return true;
    }
  }

  Future<bool> votePoll({
    required int fixtureId,
    required int pollId,
    required int optionId,
  }) async {
    try {
      final res = await http.post(
        Uri.parse('$baseUrl/matches/$fixtureId/arena/polls/$pollId/votes'),
        headers: _headers,
        body: jsonEncode({'option_id': optionId}),
      ).timeout(const Duration(seconds: 3));
      return res.statusCode == 201;
    } catch (_) {
      return true;
    }
  }

  Future<MatchPrediction?> getMyPrediction(int fixtureId) async {
    try {
      final res = await http.get(
        Uri.parse('$baseUrl/matches/$fixtureId/prediction'),
        headers: _headers,
      ).timeout(const Duration(seconds: 3));

      if (res.statusCode == 200) {
        final pred = jsonDecode(res.body)['prediction'];
        if (pred != null) {
          return MatchPrediction.fromJson(pred as Map<String, dynamic>);
        }
      }
    } catch (_) {
      // Offline fallback prediction
    }
    return MatchPrediction(
      id: 1,
      predictedOutcome: 'home',
      predictedHomeScore: 2,
      predictedAwayScore: 1,
      predictedGoalRange: '2_3',
      predictedFirstScoringTeam: 'home',
      submittedAt: DateTime.now().subtract(const Duration(minutes: 30)),
    );
  }

  Future<MatchPrediction> submitPrediction({
    required int fixtureId,
    required String outcome,
    required int homeScore,
    required int awayScore,
    required String goalRange,
    required String firstScorer,
  }) async {
    try {
      final res = await http.put(
        Uri.parse('$baseUrl/matches/$fixtureId/prediction'),
        headers: _headers,
        body: jsonEncode({
          'predicted_outcome': outcome,
          'predicted_home_score': homeScore,
          'predicted_away_score': awayScore,
          'predicted_goal_range': goalRange,
          'predicted_first_scoring_team': firstScorer,
        }),
      ).timeout(const Duration(seconds: 3));

      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        if (data['prediction'] != null) {
          return MatchPrediction.fromJson(data['prediction'] as Map<String, dynamic>);
        }
      }
    } catch (_) {
      // Offline fallback
    }

    return MatchPrediction(
      id: DateTime.now().millisecondsSinceEpoch,
      predictedOutcome: outcome,
      predictedHomeScore: homeScore,
      predictedAwayScore: awayScore,
      predictedGoalRange: goalRange,
      predictedFirstScoringTeam: firstScorer,
      submittedAt: DateTime.now(),
    );
  }

  Future<bool> reportComment({
    required int fixtureId,
    required int commentId,
    required String reason,
  }) async {
    try {
      final res = await http.post(
        Uri.parse('$baseUrl/matches/$fixtureId/arena/comments/$commentId/report'),
        headers: _headers,
        body: jsonEncode({'reason': reason}),
      ).timeout(const Duration(seconds: 3));
      return res.statusCode == 200 || res.statusCode == 201;
    } catch (_) {
      return true;
    }
  }

  Future<bool> blockUser({
    required int targetUserId,
  }) async {
    try {
      final res = await http.post(
        Uri.parse('$baseUrl/users/block'),
        headers: _headers,
        body: jsonEncode({'target_user_id': targetUserId}),
      ).timeout(const Duration(seconds: 3));
      return res.statusCode == 200 || res.statusCode == 201;
    } catch (_) {
      return true;
    }
  }

  Future<List<AnalystRanking>> getAnalystRankings() async {
    try {
      final res = await http.get(
        Uri.parse('$baseUrl/rankings/users'),
        headers: _headers,
      ).timeout(const Duration(seconds: 3));

      if (res.statusCode == 200) {
        final list = (jsonDecode(res.body)['rankings'] as List<dynamic>)
            .map((r) => AnalystRanking.fromJson(r as Map<String, dynamic>))
            .toList();
        return list;
      }
    } catch (_) {
      // Fallback
    }
    return const [
      AnalystRanking(
        rank: 1,
        userId: 101,
        displayName: 'Kerem Taktik',
        totalPoints: 48,
        exactScoreHits: 4,
        outcomeHits: 12,
        settledPredictionsCount: 15,
        outcomeAccuracyPct: 80.0,
        badges: ['badge_sniper', 'badge_analyst'],
      ),
      AnalystRanking(
        rank: 2,
        userId: 102,
        displayName: 'Selin Asist',
        totalPoints: 42,
        exactScoreHits: 3,
        outcomeHits: 11,
        settledPredictionsCount: 14,
        outcomeAccuracyPct: 78.5,
        badges: ['badge_analyst'],
      ),
      AnalystRanking(
        rank: 3,
        userId: 103,
        displayName: 'Burak Pres',
        totalPoints: 37,
        exactScoreHits: 2,
        outcomeHits: 10,
        settledPredictionsCount: 15,
        outcomeAccuracyPct: 66.7,
        badges: ['badge_regular'],
      ),
    ];
  }

  Future<List<ClubRanking>> getClubRankings() async {
    try {
      final res = await http.get(
        Uri.parse('$baseUrl/rankings/clubs'),
        headers: _headers,
      ).timeout(const Duration(seconds: 3));

      if (res.statusCode == 200) {
        final list = (jsonDecode(res.body)['club_rankings'] as List<dynamic>)
            .map((c) => ClubRanking.fromJson(c as Map<String, dynamic>))
            .toList();
        return list;
      }
    } catch (_) {
      // Fallback
    }
    return const [
      ClubRanking(
        rank: 1,
        teamId: 1,
        teamSlug: 'galatasaray',
        teamName: 'Galatasaray',
        activeSupportersCount: 128,
        minRequiredSupporters: 5,
        isQualified: true,
        settledPredictionsCount: 420,
        totalPoints: 2150,
        avgPointsPerPrediction: 5.12,
      ),
      ClubRanking(
        rank: 2,
        teamId: 2,
        teamSlug: 'fenerbahce',
        teamName: 'Fenerbahçe',
        activeSupportersCount: 115,
        minRequiredSupporters: 5,
        isQualified: true,
        settledPredictionsCount: 390,
        totalPoints: 1940,
        avgPointsPerPrediction: 4.97,
      ),
      ClubRanking(
        rank: 3,
        teamId: 3,
        teamSlug: 'besiktas',
        teamName: 'Beşiktaş',
        activeSupportersCount: 94,
        minRequiredSupporters: 5,
        isQualified: true,
        settledPredictionsCount: 310,
        totalPoints: 1490,
        avgPointsPerPrediction: 4.81,
      ),
      ClubRanking(
        rank: 4,
        teamId: 4,
        teamSlug: 'trabzonspor',
        teamName: 'Trabzonspor',
        activeSupportersCount: 68,
        minRequiredSupporters: 5,
        isQualified: true,
        settledPredictionsCount: 210,
        totalPoints: 980,
        avgPointsPerPrediction: 4.67,
      ),
    ];
  }

  MatchArenaState _fallbackArenaState(int fixtureId) {
    return MatchArenaState(
      id: 1,
      fixtureId: fixtureId,
      state: 'live_open',
      postingEnabled: true,
      slowModeSeconds: 15,
      fixture: MatchFixture(
        id: fixtureId,
        status: 'live',
        startsAt: DateTime.now().subtract(const Duration(minutes: 67)),
        homeTeamId: 1,
        homeTeamName: 'Galatasaray',
        awayTeamId: 2,
        awayTeamName: 'Fenerbahçe',
        homeScore: 2,
        awayScore: 1,
      ),
      reactionTotals: {
        'fire': 412,
        'hype': 823,
        'tactics': 194,
        'shock': 92,
        'sad': 87,
        'protest': 130,
      },
      myReaction: 'hype',
      polls: const [
        ArenaPoll(
          id: 1,
          questionKey: 'derby_mvp',
          questionText: 'Derbinin Kırılma Anı / En İyisi?',
          isClosed: false,
          myVoteOptionId: 1,
          options: [
            ArenaPollOption(id: 1, optionKey: 'icardi', label: 'Icardi - 64\' Aşırtma Gol', votesCount: 541),
            ArenaPollOption(id: 2, optionKey: 'dzeko', label: 'Džeko - Ceza Sahası Bitiricilik', votesCount: 320),
            ArenaPollOption(id: 3, optionKey: 'muslera', label: 'Muslera - 89\' Refleks Kurtarış', votesCount: 215),
          ],
        ),
      ],
      comments: [
        ArenaComment(
          id: 1,
          userId: 1,
          authorName: 'Emre_Aslan',
          section: 'home',
          body: 'Torreira\'nın orta sahadaki 3 top çalması maçı bize çevirdi!',
          createdAt: DateTime.now().subtract(const Duration(minutes: 2)),
        ),
        ArenaComment(
          id: 2,
          userId: 2,
          authorName: 'SarıKanarya_FB',
          section: 'away',
          body: 'Fred oyuna girdikten sonra sağ koridordan daha çok baskı kuruyoruz, beraberlik gelecek!',
          createdAt: DateTime.now().subtract(const Duration(minutes: 5)),
        ),
        ArenaComment(
          id: 3,
          userId: 3,
          authorName: 'TaktikUzmanı',
          section: 'neutral',
          body: 'Her iki takım da geçiş hücumlarında çok tempolu, Süper Lig standartlarının çok üzerinde bir derbi temposu var.',
          createdAt: DateTime.now().subtract(const Duration(minutes: 8)),
        ),
      ],
    );
  }

  Future<List<VoiceTribuneRoom>> getVoiceTribunes(int fixtureId) async {
    try {
      final res = await http.get(
        Uri.parse('$baseUrl/matches/$fixtureId/voice-tribunes'),
        headers: _headers,
      ).timeout(const Duration(seconds: 3));

      if (res.statusCode == 200) {
        final data = jsonDecode(res.body)['rooms'] as List<dynamic>;
        return data.map((r) => VoiceTribuneRoom.fromJson(r as Map<String, dynamic>)).toList();
      }
    } catch (_) {}

    return [
      const VoiceTribuneRoom(
        id: 1,
        fixtureId: 1,
        title: 'Derbi 2. Yarı Taktik Analiz ve VAR Pozisyonu',
        phaseTag: 'MAÇ ANINDA CANLI',
        listenersCount: 1420,
        clubId: 1,
        clubName: 'Galatasaray',
        hostName: 'Tribün Lideri Barış',
        isJoined: true,
        isMicMuted: true,
      ),
      const VoiceTribuneRoom(
        id: 2,
        fixtureId: 1,
        title: 'Fenerbahçe Deplasman Tribünü Canlı Tezahürat & Taktik',
        phaseTag: 'MAÇ ANINDA CANLI',
        listenersCount: 980,
        clubId: 2,
        clubName: 'Fenerbahçe',
        hostName: 'Kadıköy Ruhu',
        isJoined: false,
        isMicMuted: true,
      ),
      const VoiceTribuneRoom(
        id: 3,
        fixtureId: 1,
        title: 'Maç Sonu 3. Devre: Hakem Kararları ve Puan Durumu Değerlendirmesi',
        phaseTag: 'MAÇ SONU 3. DEVRE',
        listenersCount: 650,
        clubId: null,
        clubName: 'Tarafsız',
        hostName: 'Analiz Meydanı',
        isJoined: false,
        isMicMuted: true,
      ),
    ];
  }

  Future<VoiceTribuneRoom?> createVoiceTribune({
    required int fixtureId,
    required String title,
    required String phaseTag,
    int? clubId,
  }) async {
    try {
      final res = await http.post(
        Uri.parse('$baseUrl/matches/$fixtureId/voice-tribunes'),
        headers: _headers,
        body: jsonEncode({
          'title': title,
          'phase_tag': phaseTag,
          'club_id': clubId,
        }),
      ).timeout(const Duration(seconds: 3));

      if (res.statusCode == 201) {
        final data = jsonDecode(res.body)['room'] as Map<String, dynamic>;
        return VoiceTribuneRoom.fromJson(data);
      }
    } catch (_) {}

    return VoiceTribuneRoom(
      id: DateTime.now().millisecondsSinceEpoch,
      fixtureId: fixtureId,
      title: title,
      phaseTag: phaseTag,
      listenersCount: 1,
      clubId: clubId,
      hostName: 'Siz (Kurucu)',
      isJoined: true,
    );
  }

  Future<bool> triggerVoiceAction({
    required int roomId,
    required String action,
    String? emoji,
  }) async {
    try {
      final payload = <String, dynamic>{'action': action};
      if (emoji != null) {
        payload['emoji'] = emoji;
      }
      final res = await http.post(
        Uri.parse('$baseUrl/voice-tribunes/$roomId/actions'),
        headers: _headers,
        body: jsonEncode(payload),
      ).timeout(const Duration(seconds: 3));

      return res.statusCode == 200;
    } catch (_) {
      return true;
    }
  }

  TacticalLineup getFallbackTacticalLineup() {
    return const TacticalLineup(
      formation: '4-2-3-1',
      mentality: 'Önde Şok Baskı',
      coachName: 'Okan Buruk',
      starters: [
        TacticalPlayerSlot(slotIndex: 0, playerName: 'Muslera', shirtNumber: 1, position: 'GK', xRatio: 0.50, yRatio: 0.88, clubPrimaryHex: '#A6192E'),
        TacticalPlayerSlot(slotIndex: 1, playerName: 'Kaan Ayhan', shirtNumber: 23, position: 'DF', xRatio: 0.16, yRatio: 0.70, clubPrimaryHex: '#A6192E'),
        TacticalPlayerSlot(slotIndex: 2, playerName: 'Davinson', shirtNumber: 6, position: 'DF', xRatio: 0.38, yRatio: 0.72, clubPrimaryHex: '#A6192E'),
        TacticalPlayerSlot(slotIndex: 3, playerName: 'Abdülkerim', shirtNumber: 42, position: 'DF', xRatio: 0.62, yRatio: 0.72, clubPrimaryHex: '#A6192E'),
        TacticalPlayerSlot(slotIndex: 4, playerName: 'Jakobs', shirtNumber: 4, position: 'DF', xRatio: 0.84, yRatio: 0.70, clubPrimaryHex: '#A6192E'),
        TacticalPlayerSlot(slotIndex: 5, playerName: 'Torreira', shirtNumber: 34, position: 'MF', xRatio: 0.36, yRatio: 0.52, clubPrimaryHex: '#A6192E'),
        TacticalPlayerSlot(slotIndex: 6, playerName: 'Gabriel Sara', shirtNumber: 20, position: 'MF', xRatio: 0.64, yRatio: 0.52, clubPrimaryHex: '#A6192E'),
        TacticalPlayerSlot(slotIndex: 7, playerName: 'Barış Alper', shirtNumber: 53, position: 'FW', xRatio: 0.18, yRatio: 0.33, clubPrimaryHex: '#A6192E'),
        TacticalPlayerSlot(slotIndex: 8, playerName: 'Mertens', shirtNumber: 10, position: 'MF', xRatio: 0.50, yRatio: 0.34, clubPrimaryHex: '#A6192E'),
        TacticalPlayerSlot(slotIndex: 9, playerName: 'Yunus Akgün', shirtNumber: 11, position: 'FW', xRatio: 0.82, yRatio: 0.33, clubPrimaryHex: '#A6192E'),
        TacticalPlayerSlot(slotIndex: 10, playerName: 'Icardi', shirtNumber: 9, position: 'FW', xRatio: 0.50, yRatio: 0.15, clubPrimaryHex: '#A6192E'),
      ],
      bench: [
        TacticalPlayerSlot(slotIndex: 11, playerName: 'Batshuayi', shirtNumber: 44, position: 'FW', xRatio: 0.50, yRatio: 0.15, clubPrimaryHex: '#A6192E'),
        TacticalPlayerSlot(slotIndex: 12, playerName: 'Kerem Demirbay', shirtNumber: 8, position: 'MF', xRatio: 0.50, yRatio: 0.52, clubPrimaryHex: '#A6192E'),
        TacticalPlayerSlot(slotIndex: 13, playerName: 'Berkan Kutlu', shirtNumber: 18, position: 'MF', xRatio: 0.50, yRatio: 0.52, clubPrimaryHex: '#A6192E'),
        TacticalPlayerSlot(slotIndex: 14, playerName: 'Nelsson', shirtNumber: 25, position: 'DF', xRatio: 0.50, yRatio: 0.72, clubPrimaryHex: '#A6192E'),
        TacticalPlayerSlot(slotIndex: 15, playerName: 'Günay Güvenç', shirtNumber: 19, position: 'GK', xRatio: 0.50, yRatio: 0.88, clubPrimaryHex: '#A6192E'),
      ],
    );
  }
}
