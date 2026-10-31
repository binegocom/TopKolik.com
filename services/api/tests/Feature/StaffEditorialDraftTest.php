<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EditorialArticle;
use App\Models\StaffMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffEditorialDraftTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'editor'): StaffMember
    {
        $member = StaffMember::create([
            'name' => 'Editorial Operator',
            'email' => 'editor-'.Str::uuid().'@example.test',
            'password' => 'Strong!StaffPassword2026',
            'role' => $role,
        ]);
        $member->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        return $member;
    }

    private function token(StaffMember $staff, int $minutes = 15): string
    {
        return $staff->createToken('staff-editor', ['staff:session'], now()->addMinutes($minutes))
            ->plainTextToken;
    }

    private function draft(StaffMember $owner, string $status = 'draft'): EditorialArticle
    {
        $draft = EditorialArticle::create([
            'owner_staff_id' => $owner->getKey(),
            'title' => 'Draft about a verified sports story',
            'body' => 'This unpublished draft is held for mandatory source review.',
        ]);
        if ($status !== 'draft') {
            $draft->forceFill(['status' => $status])->save();
        }

        return $draft;
    }

    private function data(): array
    {
        return [
            'title' => 'Real review-only editorial draft',
            'body' => 'This original content must be reviewed before publication.',
        ];
    }

    private function url(EditorialArticle $draft): string
    {
        return '/api/v1/staff/editorial/drafts/'.$draft->id;
    }

    public function test_active_editor_creates_private_encrypted_draft_with_no_publish_side_effect(): void
    {
        $staff = $this->staff();
        $response = $this->withToken($this->token($staff))
            ->postJson('/api/v1/staff/editorial/drafts', $this->data())
            ->assertCreated()
            ->assertJsonPath('draft.status', 'draft')
            ->assertJsonPath('draft.revision', 1)
            ->assertJsonMissingPath('draft.owner_staff_id');

        $draft = EditorialArticle::firstOrFail();
        $this->assertSame($staff->getKey(), $draft->owner_staff_id);
        $this->assertNull($draft->submitted_at);
        $this->assertSame($this->data()['title'], $draft->title);
        $raw = DB::table('editorial_articles')->where('id', $draft->id)->first();
        $this->assertNotSame($draft->title, $raw->title);
        $this->assertNotSame($draft->body, $raw->body);
        $this->assertStringNotContainsString('This original content', $raw->body);
        $this->assertSame($draft->id, $response->json('draft.id'));
    }

    public function test_editor_reads_only_owned_drafts_with_paginated_index(): void
    {
        $editor = $this->staff();
        $other = $this->staff();
        $owned = $this->draft($editor);
        $this->draft($other);

        $this->withToken($this->token($editor))
            ->getJson('/api/v1/staff/editorial/drafts')->assertOk()
            ->assertJsonCount(1, 'drafts')
            ->assertJsonPath('drafts.0.id', $owned->id)
            ->assertJsonMissingPath('drafts.0.body')
            ->assertJsonPath('last_page', 1);
        $this->getJson($this->url($owned))->assertOk()
            ->assertJsonPath('draft.body', $owned->body);
    }

    public function test_draft_idor_denies_cross_editor_read_write_and_submit(): void
    {
        $owner = $this->staff();
        $other = $this->staff();
        $record = $this->draft($owner);

        $this->withToken($this->token($other))
            ->getJson($this->url($record))->assertNotFound();
        $this->patchJson($this->url($record), [
            'revision' => 1, 'title' => 'Unauthorized changed title',
        ])->assertNotFound();
        $this->postJson($this->url($record).'/submit', ['revision' => 1])->assertNotFound();

        $this->assertSame(1, $record->fresh()->revision);
        $this->assertSame('draft', $record->fresh()->status);
    }

    public function test_support_ad_finance_and_reader_cannot_create_editorial_drafts(): void
    {
        foreach (['support', 'ads', 'finance'] as $role) {
            $member = $this->staff($role);
            $this->withToken($this->token($member))
                ->postJson('/api/v1/staff/editorial/drafts', $this->data())->assertForbidden();
            app('auth')->forgetGuards();
        }

        $reader = User::factory()->create();
        $this->withToken($reader->createToken('reader', ['reader:read'])->plainTextToken)
            ->postJson('/api/v1/staff/editorial/drafts', $this->data())->assertForbidden();

        $this->assertDatabaseCount('editorial_articles', 0);
    }

    public function test_draft_updates_are_revision_guarded_and_conflicts_do_not_overwrite(): void
    {
        $editor = $this->staff();
        $draft = $this->draft($editor);

        $this->withToken($this->token($editor))
            ->patchJson($this->url($draft), [
                'revision' => 1, 'title' => 'Updated editorial draft title',
            ])->assertOk()->assertJsonPath('draft.revision', 2);

        $this->patchJson($this->url($draft), [
            'revision' => 1, 'body' => 'Overwriting stale text is not allowed by the service.',
        ])->assertStatus(409);

        $this->assertSame('Updated editorial draft title', $draft->fresh()->title);
        $this->assertSame(2, $draft->fresh()->revision);
    }

    public function test_submission_only_moves_draft_to_review_and_is_not_publication(): void
    {
        $editor = $this->staff();
        $draft = $this->draft($editor);

        $response = $this->withToken($this->token($editor))
            ->postJson($this->url($draft).'/submit', ['revision' => 1])
            ->assertOk()
            ->assertJsonPath('draft.status', 'in_review')
            ->assertJsonPath('draft.revision', 2);
        $this->assertNotNull($draft->fresh()->submitted_at);
        $this->assertNull($response->json('draft.published_at'));
        $this->postJson($this->url($draft).'/submit', ['revision' => 2])->assertForbidden();
        $this->patchJson($this->url($draft), [
            'revision' => 2, 'title' => 'Unauthorized after submit',
        ])->assertForbidden();
        $this->postJson($this->url($draft).'/publish', [])->assertNotFound();
    }

    public function test_held_and_published_records_cannot_be_modified_via_draft_routes(): void
    {
        $editor = $this->staff();
        $held = $this->draft($editor, 'held');
        $published = $this->draft($editor, 'published');
        $this->withToken($this->token($editor));

        foreach ([$held, $published] as $record) {
            $this->patchJson($this->url($record), [
                'revision' => $record->revision, 'title' => 'Forbidden title mutation',
            ])->assertForbidden();
            $this->postJson($this->url($record).'/submit', [
                'revision' => $record->revision,
            ])->assertForbidden();
        }
        $this->getJson($this->url($published))->assertForbidden();
    }

    public function test_owner_and_publication_state_tampering_is_rejected(): void
    {
        $editor = $this->staff();
        $draft = $this->draft($editor);
        $this->withToken($this->token($editor));

        foreach ([
            ['owner_staff_id' => 9191],
            ['status' => 'published'],
            ['revision' => 999],
            ['submitted_at' => now()->toIso8601String()],
            ['id' => 999],
        ] as $tamper) {
            $field = array_key_first($tamper);
            $this->postJson('/api/v1/staff/editorial/drafts', [
                ...$this->data(), ...$tamper,
            ])->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $this->patchJson($this->url($draft), [
            'revision' => 1, 'status' => 'published',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson($this->url($draft).'/submit', [
            'revision' => 1, 'owner_staff_id' => 9191,
        ])->assertUnprocessable()->assertJsonValidationErrors('owner_staff_id');
        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_strong_validation_rejects_empty_update_oversized_body_and_invalid_revision(): void
    {
        $editor = $this->staff();
        $draft = $this->draft($editor);
        $this->withToken($this->token($editor));

        $this->patchJson($this->url($draft), ['revision' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->patchJson($this->url($draft), [
            'revision' => 1, 'body' => str_repeat('x', 30001),
        ])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->patchJson($this->url($draft), [
            'revision' => 0, 'title' => 'Updated draft title',
        ])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertSame(1, $draft->fresh()->revision);
    }

    public function test_deactivation_or_role_change_revokes_edit_permission_immediately(): void
    {
        $editor = $this->staff();
        $draft = $this->draft($editor);
        $token = $this->token($editor);
        StaffMember::query()->whereKey($editor->id)->update(['role' => 'support']);
        $this->withToken($token)
            ->patchJson($this->url($draft), [
                'revision' => 1, 'title' => 'Forbidden role-change update',
            ])->assertForbidden();

        StaffMember::query()->whereKey($editor->id)->update([
            'role' => 'editor', 'is_active' => false,
        ]);
        app('auth')->forgetGuards();
        $this->withToken($token)
            ->postJson('/api/v1/staff/editorial/drafts', $this->data())->assertForbidden();
        $this->assertDatabaseCount('editorial_articles', 1);
    }

    public function test_expired_staff_token_and_unauthenticated_cannot_read_draft(): void
    {
        $editor = $this->staff();
        $draft = $this->draft($editor);
        $this->getJson($this->url($draft))->assertUnauthorized();
        $this->withToken($this->token($editor, -1))
            ->getJson($this->url($draft))->assertUnauthorized();
    }
}
