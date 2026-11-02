<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EditorialArticle;
use App\Models\StaffMember;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

final class StaffEditorialMutationTokenLockTest extends TestCase
{
    use RefreshDatabase;

    private function editor(): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Editorial Authorization Operator',
            'email' => 'editor-lock-'.Str::uuid().'@example.test',
            'password' => 'Strong!StaffPassword2026',
            'role' => 'editor',
        ]);
        $staff->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        return $staff;
    }

    private function token(StaffMember $staff): string
    {
        return $staff->createToken(
            'staff-editor',
            ['staff:session'],
            now()->addMinutes(15),
        )->plainTextToken;
    }

    private function draft(StaffMember $staff): EditorialArticle
    {
        return EditorialArticle::create([
            'owner_staff_id' => $staff->getKey(),
            'title' => 'Review-only editorial draft',
            'body' => 'This draft requires source verification before publication.',
        ]);
    }

    public function test_all_editorial_mutations_lock_staff_then_bearer_then_draft(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $staff = $this->editor();
        $this->withToken($this->token($staff));
        $locks = [];

        DB::listen(static function (QueryExecuted $query) use (&$locks): void {
            $sql = strtolower($query->sql);
            if (! str_contains($sql, 'for update')) {
                return;
            }
            if (str_contains($sql, 'from "staff_members"')) {
                $locks[] = 'staff';
            } elseif (str_contains($sql, 'from "personal_access_tokens"')) {
                $locks[] = 'token';
            } elseif (str_contains($sql, 'from "editorial_articles"')) {
                $locks[] = 'draft';
            }
        });

        $created = $this->postJson('/api/v1/staff/editorial/drafts', [
            'title' => 'Draft pending verification',
            'body' => 'This original report is awaiting an evidence-based editorial review.',
        ])->assertCreated();
        $this->assertSame(['staff', 'token'], array_slice($locks, 0, 2));

        $url = '/api/v1/staff/editorial/drafts/'.$created->json('draft.id');
        $locks = [];
        $this->patchJson($url, [
            'revision' => 1,
            'title' => 'Revised pending verification',
        ])->assertOk();
        $this->assertSame(['staff', 'token', 'draft'], array_slice($locks, 0, 3));

        $locks = [];
        $this->postJson($url.'/submit', ['revision' => 2])->assertOk();
        $this->assertSame(['staff', 'token', 'draft'], array_slice($locks, 0, 3));
        $this->assertDatabaseCount('editorial_articles', 1);
    }

    public function test_bearer_revoked_after_middleware_read_cannot_create_edit_or_submit(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $staff = $this->editor();
        $draft = $this->draft($staff);
        $targetTokenId = null;
        $revocationObserved = false;

        // The staff row lock occurs after the controller captures the bearer.
        // Simulate a revocation at that boundary: authorization must re-read
        // the bearer rather than trusting its previously loaded model.
        DB::listen(static function (QueryExecuted $query) use (&$targetTokenId, &$revocationObserved): void {
            if ($targetTokenId === null || ! str_contains(strtolower($query->sql), 'from "staff_members"')
                || ! str_contains(strtolower($query->sql), 'for update')) {
                return;
            }

            DB::table('personal_access_tokens')->where('id', $targetTokenId)->delete();
            $targetTokenId = null;
            $revocationObserved = true;
        });

        $operations = [
            ['post', '/api/v1/staff/editorial/drafts', [
                'title' => 'Attempted revoked-token creation',
                'body' => 'This creation must be rejected after token revocation.',
            ]],
            ['patch', '/api/v1/staff/editorial/drafts/'.$draft->id, [
                'revision' => 1,
                'title' => 'Attempted revoked-token update',
            ]],
            ['post', '/api/v1/staff/editorial/drafts/'.$draft->id.'/submit', [
                'revision' => 1,
            ]],
        ];

        foreach ($operations as [$method, $url, $payload]) {
            $bearer = $this->token($staff);
            $targetTokenId = PersonalAccessToken::findToken($bearer)->getKey();
            $revocationObserved = false;
            app('auth')->forgetGuards();
            $this->withToken($bearer);
            $this->json(strtoupper($method), $url, $payload)->assertUnauthorized();
            $this->assertTrue($revocationObserved, 'The revocation boundary must be exercised.');
        }

        $this->assertDatabaseCount('editorial_articles', 1);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame(1, $draft->fresh()->revision);
        $this->assertSame('Review-only editorial draft', $draft->fresh()->title);
    }
}
