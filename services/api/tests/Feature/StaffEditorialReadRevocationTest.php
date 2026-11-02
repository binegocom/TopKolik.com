<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EditorialArticle;
use App\Models\StaffMember;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

final class StaffEditorialReadRevocationTest extends TestCase
{
    use RefreshDatabase;

    private function editor(): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Editorial Read Operator',
            'email' => Str::uuid().'@example.test',
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

    private function draft(StaffMember $owner, string $status = 'draft'): EditorialArticle
    {
        $draft = EditorialArticle::create([
            'owner_staff_id' => $owner->getKey(),
            'title' => 'Private editorial verification draft',
            'body' => 'This unpublished article requires licensed source verification.',
        ]);
        if ($status !== 'draft') {
            $draft->forceFill(['status' => $status])->save();
        }

        return $draft;
    }

    public function test_read_paths_lock_staff_and_token_before_returning_draft_data(): void
    {
        $editor = $this->editor();
        $draft = $this->draft($editor);
        $bearer = $editor->createToken('editor', ['staff:session'], now()->addMinutes(15))
            ->plainTextToken;
        $locks = ['staff' => 0, 'token' => 0];

        DB::listen(static function (QueryExecuted $query) use (&$locks): void {
            $sql = strtolower($query->sql);
            if (! str_contains($sql, 'for update')) {
                return;
            }
            if (str_contains($sql, 'staff_members')) {
                $locks['staff']++;
            }
            if (str_contains($sql, 'personal_access_tokens')) {
                $locks['token']++;
            }
        });

        $this->withToken($bearer)
            ->getJson('/api/v1/staff/editorial/drafts')
            ->assertOk()->assertJsonCount(1, 'drafts')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/staff/editorial/drafts/'.$draft->id)
            ->assertOk()->assertJsonPath('draft.id', $draft->id)
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame(['staff' => 2, 'token' => 2], $locks);
    }

    public function test_revocation_denies_index_and_detail_without_cross_editor_disclosure(): void
    {
        $editor = $this->editor();
        $other = $this->editor();
        $owned = $this->draft($editor);
        $foreign = $this->draft($other);
        $bearer = $editor->createToken('editor', ['staff:session'], now()->addMinutes(15))
            ->plainTextToken;
        $this->withToken($bearer)
            ->getJson('/api/v1/staff/editorial/drafts')
            ->assertOk()->assertJsonCount(1, 'drafts')
            ->assertJsonPath('drafts.0.id', $owned->id);
        $this->getJson('/api/v1/staff/editorial/drafts/'.$foreign->id)->assertNotFound();

        StaffMember::query()->whereKey($editor->id)->update(['role' => 'support']);
        app('auth')->forgetGuards();
        $this->withToken($bearer)
            ->getJson('/api/v1/staff/editorial/drafts')->assertForbidden();
        $this->getJson('/api/v1/staff/editorial/drafts/'.$owned->id)->assertForbidden();
        $this->getJson('/api/v1/staff/editorial/drafts/'.$foreign->id)->assertNotFound();

        StaffMember::query()->whereKey($editor->id)->update(['role' => 'editor']);
        PersonalAccessToken::findToken($bearer)->delete();
        app('auth')->forgetGuards();
        $this->withToken($bearer)
            ->getJson('/api/v1/staff/editorial/drafts')->assertUnauthorized();
        $this->getJson('/api/v1/staff/editorial/drafts/'.$owned->id)->assertUnauthorized();
        $this->assertSame(2, EditorialArticle::query()->count());
    }

    public function test_index_pagination_excludes_foreign_and_published_records(): void
    {
        $editor = $this->editor();
        $other = $this->editor();
        $this->draft($editor, 'published');
        $this->draft($other);
        for ($i = 0; $i < 21; $i++) {
            $this->draft($editor);
        }

        $bearer = $editor->createToken('editor', ['staff:session'], now()->addMinutes(15))
            ->plainTextToken;
        $this->withToken($bearer)
            ->getJson('/api/v1/staff/editorial/drafts?page=1')
            ->assertOk()->assertJsonCount(20, 'drafts')
            ->assertJsonPath('last_page', 2)
            ->assertJsonMissingPath('drafts.0.body');
        $this->getJson('/api/v1/staff/editorial/drafts?page=2')
            ->assertOk()->assertJsonCount(1, 'drafts')
            ->assertJsonPath('last_page', 2);
    }

    public function test_deactivated_editor_cannot_read_drafts_even_before_bearer_is_deleted(): void
    {
        $editor = $this->editor();
        $other = $this->editor();
        $owned = $this->draft($editor);
        $foreign = $this->draft($other);
        $bearer = $editor->createToken('editor', ['staff:session'], now()->addMinutes(15))
            ->plainTextToken;

        $this->withToken($bearer)
            ->getJson('/api/v1/staff/editorial/drafts')
            ->assertOk()->assertJsonCount(1, 'drafts');
        $this->getJson('/api/v1/staff/editorial/drafts/'.$owned->id)
            ->assertOk()->assertJsonPath('draft.id', $owned->id);

        StaffMember::query()->whereKey($editor->id)->update(['is_active' => false]);
        $this->assertNotNull(PersonalAccessToken::findToken($bearer));
        app('auth')->forgetGuards();

        $this->withToken($bearer)
            ->getJson('/api/v1/staff/editorial/drafts')
            ->assertForbidden()->assertJsonMissingPath('drafts');
        $this->getJson('/api/v1/staff/editorial/drafts/'.$owned->id)
            ->assertForbidden()->assertJsonMissingPath('draft');
        $this->getJson('/api/v1/staff/editorial/drafts/'.$foreign->id)
            ->assertForbidden()->assertJsonMissingPath('draft');

        PersonalAccessToken::findToken($bearer)->delete();
        app('auth')->forgetGuards();
        $this->withToken($bearer)
            ->getJson('/api/v1/staff/editorial/drafts')->assertUnauthorized();
        $this->getJson('/api/v1/staff/editorial/drafts/'.$owned->id)
            ->assertUnauthorized();
        $this->assertSame(2, EditorialArticle::query()->count());
        $this->assertSame(1, $owned->fresh()->revision);
        $this->assertSame(1, $foreign->fresh()->revision);
    }

}
