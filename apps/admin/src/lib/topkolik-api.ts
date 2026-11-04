/**
 * Topkolik.com Operations API Client & DTOs
 * Grounded in OpenAPI 3.1 contract (docs/api/openapi.yaml)
 */

export interface StaffArenaControlPayload {
  state?: "pre_match_open" | "live_open" | "post_match_open" | "archived" | "suspended";
  posting_enabled?: boolean;
  slow_mode_seconds?: number;
  lock_reason?: string | null;
}

export class TopkolikAdminApiClient {
  private baseUrl: string;
  private staffToken?: string;
  private mfaStepUpToken?: string;

  constructor(options?: { baseUrl?: string; staffToken?: string; mfaStepUpToken?: string }) {
    this.baseUrl = options?.baseUrl || "http://127.0.0.1:8000/api/v1";
    this.staffToken = options?.staffToken;
    this.mfaStepUpToken = options?.mfaStepUpToken;
  }

  private get headers(): HeadersInit {
    return {
      "Content-Type": "application/json",
      Accept: "application/json",
      ...(this.staffToken ? { Authorization: `Bearer ${this.staffToken}` } : {}),
      ...(this.mfaStepUpToken ? { "X-Staff-Step-Up-Token": this.mfaStepUpToken } : {}),
    };
  }

  async updateArenaControl(arenaId: number, payload: StaffArenaControlPayload): Promise<{ success: boolean }> {
    const res = await fetch(`${this.baseUrl}/staff/moderation/arenas/${arenaId}/control`, {
      method: "PATCH",
      headers: this.headers,
      body: JSON.stringify(payload),
    });
    if (!res.ok) throw new Error(`Staff control operation failed: ${res.statusText}`);
    return { success: true };
  }
}
