/**
 * Topkolik.com Type-Safe API Client & DTOs
 * Grounded in OpenAPI 3.1 contract (docs/api/openapi.yaml)
 */

export type LocaleCode = "tr" | "en" | "de" | "fr" | "it";

export type TribuneSection = "home" | "away" | "neutral";
export type ArenaState = "pre_match_open" | "live_open" | "post_match_open" | "archived" | "suspended";
export type MoodReactionType = "fire" | "hype" | "tactics" | "shock" | "sad" | "protest";

export interface ApiStatus {
  app: string;
  version: string;
  supported_locales: LocaleCode[];
}

export interface MatchFixture {
  id: number;
  status: "scheduled" | "live" | "finished";
  home_team_id: number;
  home_team_name: string;
  away_team_id: number;
  away_team_name: string;
  home_score?: number | null;
  away_score?: number | null;
  starts_at: string;
}

export interface ArenaPollOption {
  id: number;
  option_key: string;
  label: string;
  votes_count: number;
}

export interface ArenaPoll {
  id: number;
  question_text: string;
  is_closed: boolean;
  my_vote_option_id?: number | null;
  options: ArenaPollOption[];
}

export interface ArenaComment {
  id: number;
  user_id: number;
  author_name: string;
  section: TribuneSection;
  body: string;
  moderation_state: "visible" | "quarantined" | "hidden" | "removed";
  created_at: string;
}

export interface MatchArena {
  id: number;
  state: ArenaState;
  posting_enabled: boolean;
  slow_mode_seconds: number;
  fixture: MatchFixture;
  reactions: {
    totals: Record<MoodReactionType, number>;
    my_reaction?: MoodReactionType | null;
  };
  polls: ArenaPoll[];
  comments: ArenaComment[];
}

export interface PredictionSettlement {
  total_points: number;
  outcome_points_awarded: number;
  exact_score_points_awarded: number;
  goal_range_points_awarded: number;
  first_scorer_points_awarded: number;
  is_outcome_hit: boolean;
  is_exact_score_hit: boolean;
}

export interface MatchPrediction {
  id: number;
  predicted_outcome: "home" | "draw" | "away";
  predicted_home_score: number;
  predicted_away_score: number;
  predicted_goal_range: "0_1" | "2_3" | "4_plus";
  predicted_first_scoring_team: "home" | "away" | "none";
  submitted_at: string;
  settlement?: PredictionSettlement | null;
}

export interface AnalystRanking {
  rank: number;
  user_id: number;
  display_name: string;
  total_points: number;
  exact_score_hits: number;
  outcome_hits: number;
  settled_predictions_count: number;
  outcome_accuracy_pct: number;
  badges: string[];
}

export interface ClubRanking {
  rank: number;
  team_id: number;
  team_slug: string;
  team_name: string;
  active_supporters_count: number;
  min_required_supporters: number;
  is_qualified_for_official_rank: boolean;
  settled_predictions_count: number;
  total_points: number;
  avg_points_per_prediction: number;
}

export class TopkolikApiClient {
  private baseUrl: string;
  private bearerToken?: string;
  private locale: LocaleCode;

  constructor(options?: { baseUrl?: string; bearerToken?: string; locale?: LocaleCode }) {
    this.baseUrl = options?.baseUrl || "http://127.0.0.1:8000/api/v1";
    this.bearerToken = options?.bearerToken;
    this.locale = options?.locale || "tr";
  }

  private get headers(): HeadersInit {
    return {
      "Content-Type": "application/json",
      Accept: "application/json",
      "Accept-Language": `${this.locale},en;q=0.8`,
      ...(this.bearerToken ? { Authorization: `Bearer ${this.bearerToken}` } : {}),
    };
  }

  async getMatchArena(fixtureId: number): Promise<MatchArena> {
    const res = await fetch(`${this.baseUrl}/matches/${fixtureId}/arena`, {
      headers: this.headers,
    });
    if (!res.ok) throw new Error(`Failed to fetch arena: ${res.statusText}`);
    const data = await res.json();
    return data.arena;
  }

  async postComment(fixtureId: number, section: TribuneSection, body: string, parentCommentId?: number): Promise<ArenaComment> {
    const res = await fetch(`${this.baseUrl}/matches/${fixtureId}/arena/comments`, {
      method: "POST",
      headers: this.headers,
      body: JSON.stringify({ section, body, parent_comment_id: parentCommentId }),
    });
    if (!res.ok) throw new Error(`Failed to post comment: ${res.statusText}`);
    const data = await res.json();
    return data.comment;
  }

  async submitPrediction(fixtureId: number, prediction: {
    predicted_outcome: "home" | "draw" | "away";
    predicted_home_score: number;
    predicted_away_score: number;
    predicted_goal_range: "0_1" | "2_3" | "4_plus";
    predicted_first_scoring_team: "home" | "away" | "none";
  }): Promise<MatchPrediction> {
    const res = await fetch(`${this.baseUrl}/matches/${fixtureId}/prediction`, {
      method: "PUT",
      headers: this.headers,
      body: JSON.stringify(prediction),
    });
    if (!res.ok) throw new Error(`Failed to submit prediction: ${res.statusText}`);
    const data = await res.json();
    return data.prediction;
  }

  async getAnalystRankings(): Promise<AnalystRanking[]> {
    const res = await fetch(`${this.baseUrl}/rankings/users`, {
      headers: this.headers,
    });
    if (!res.ok) throw new Error(`Failed to fetch analyst rankings: ${res.statusText}`);
    const data = await res.json();
    return data.rankings;
  }

  async getClubRankings(): Promise<ClubRanking[]> {
    const res = await fetch(`${this.baseUrl}/rankings/clubs`, {
      headers: this.headers,
    });
    if (!res.ok) throw new Error(`Failed to fetch club rankings: ${res.statusText}`);
    const data = await res.json();
    return data.club_rankings;
  }
}
