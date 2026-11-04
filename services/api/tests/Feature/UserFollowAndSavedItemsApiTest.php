<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Athlete;
use App\Models\Competition;
use App\Models\EditorialArticle;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Sport;
use App\Models\StaffMember;
use App\Models\Team;
use App\Models\User;
use App\Models\UserFollow;
use App\Models\UserSavedItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class UserFollowAndSavedItemsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $userA;
    private User $userB;
    private Team $team;
    private Competition $competition;
    private Athlete $athlete;
    private Fixture $fixture;
    private EditorialArticle $article;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::create([
            'name' => 'Ahmet Taraftar',
            'email' => 'ahmet@topkolik.test',
            'password' => bcrypt('Secret123!'),
        ]);
        $this->userA->markEmailAsVerified();

        $this->userB = User::create([
            'name' => 'Mehmet Taraftar',
            'email' => 'mehmet@topkolik.test',
            'password' => bcrypt('Secret123!'),
        ]);
        $this->userB->markEmailAsVerified();

        $football = Sport::create(['slug' => 'futbol', 'name' => 'Futbol']);

        $this->competition = Competition::create([
            'sport_id' => $football->id,
            'slug' => 'super-lig',
            'name' => 'Trendyol Süper Lig',
            'country_code' => 'TR',
        ]);

        $season = Season::create([
            'competition_id' => $this->competition->id,
            'label' => '2026-2027',
            'starts_on' => '2026-08-10',
            'ends_on' => '2027-05-25',
            'is_current' => true,
        ]);

        $this->team = Team::create([
            'sport_id' => $football->id,
            'slug' => 'galatasaray',
            'name' => 'Galatasaray',
            'short_name' => 'GS',
        ]);

        $rival = Team::create([
            'sport_id' => $football->id,
            'slug' => 'fenerbahce',
            'name' => 'Fenerbahçe',
            'short_name' => 'FB',
        ]);

        $this->athlete = Athlete::create([
            'sport_id' => $football->id,
            'current_team_id' => $this->team->id,
            'slug' => 'victor-osimhen',
            'name' => 'Victor Osimhen',
            'position' => 'FW',
            'shirt_number' => 45,
        ]);

        $this->fixture = Fixture::create([
            'competition_id' => $this->competition->id,
            'season_id' => $season->id,
            'home_team_id' => $this->team->id,
            'away_team_id' => $rival->id,
            'status' => 'scheduled',
            'starts_at' => now()->addDays(2),
            'source_revision' => 1,
        ]);

        $staff = StaffMember::create([
            'name' => 'Staff Editor',
            'email' => 'staff-'.Str::uuid().'@topkolik.test',
            'password' => 'Staff123!',
            'role' => 'editor',
        ]);
        $staff->forceFill(['is_active' => true, 'email_verified_at' => now(), 'mfa_enrolled_at' => now()])->save();

        $this->article = EditorialArticle::create([
            'title' => 'Galatasaray Derbi Hazırlıkları Başladı',
            'body' => 'Florya Metin Oktay Tesislerinde antrenman yapıldı.',
            'owner_staff_id' => $staff->id,
            'status' => 'published',
            'revision' => 1,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test', ['reader:read', 'reader:write'], now()->addHours(2))->plainTextToken;
    }

    public function test_unauthenticated_requests_are_denied_with_401(): void
    {
        $this->getJson('/api/v1/me/follows')->assertStatus(401);
        $this->postJson('/api/v1/me/follows', [])->assertStatus(401);
        $this->deleteJson('/api/v1/me/follows/team/1')->assertStatus(401);

        $this->getJson('/api/v1/me/saved')->assertStatus(401);
        $this->postJson('/api/v1/me/saved', [])->assertStatus(401);
        $this->deleteJson('/api/v1/me/saved/fixture/1')->assertStatus(401);
    }

    public function test_user_can_follow_team_competition_and_athlete(): void
    {
        $token = $this->tokenFor($this->userA);

        // 1. Follow team
        $response = $this->withToken($token)->postJson('/api/v1/me/follows', [
            'followable_type' => 'team',
            'followable_id' => $this->team->id,
            'notify_match_start' => true,
            'notify_goals' => true,
        ]);
        $response->assertStatus(201);
        $response->assertJsonPath('type', 'team');
        $response->assertJsonPath('target_id', $this->team->id);

        // 2. Follow competition
        $this->withToken($token)->postJson('/api/v1/me/follows', [
            'followable_type' => 'competition',
            'followable_id' => $this->competition->id,
        ])->assertStatus(201);

        // 3. Follow athlete
        $this->withToken($token)->postJson('/api/v1/me/follows', [
            'followable_type' => 'athlete',
            'followable_id' => $this->athlete->id,
        ])->assertStatus(201);

        // 4. List follows
        $list = $this->withToken($token)->getJson('/api/v1/me/follows');
        $list->assertStatus(200);
        $list->assertJsonCount(3, 'follows');

        // 5. Unfollow athlete
        $del = $this->withToken($token)->deleteJson("/api/v1/me/follows/athlete/{$this->athlete->id}");
        $del->assertStatus(200);
        $del->assertJsonPath('deleted', true);

        // Verify remaining count
        $listAfter = $this->withToken($token)->getJson('/api/v1/me/follows');
        $listAfter->assertJsonCount(2, 'follows');
    }

    public function test_following_non_existent_entity_returns_404(): void
    {
        $token = $this->tokenFor($this->userA);

        $response = $this->withToken($token)->postJson('/api/v1/me/follows', [
            'followable_type' => 'team',
            'followable_id' => 999999,
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Target team not found');
    }

    public function test_user_can_save_and_unsave_content(): void
    {
        $token = $this->tokenFor($this->userA);

        // 1. Save fixture
        $res = $this->withToken($token)->postJson('/api/v1/me/saved', [
            'item_type' => 'fixture',
            'item_id' => $this->fixture->id,
            'notes' => 'Derbi maçı hatırlatması',
        ]);
        $res->assertStatus(201);
        $res->assertJsonPath('item_type', 'fixture');
        $res->assertJsonPath('notes', 'Derbi maçı hatırlatması');

        // 2. Save article
        $this->withToken($token)->postJson('/api/v1/me/saved', [
            'item_type' => 'article',
            'item_id' => $this->article->id,
        ])->assertStatus(201);

        // 3. List saved items
        $list = $this->withToken($token)->getJson('/api/v1/me/saved');
        $list->assertStatus(200);
        $list->assertJsonCount(2, 'saved_items');

        // 4. Unsave article
        $del = $this->withToken($token)->deleteJson("/api/v1/me/saved/article/{$this->article->id}");
        $del->assertStatus(200);
        $del->assertJsonPath('deleted', true);

        // Verify remaining
        $listAfter = $this->withToken($token)->getJson('/api/v1/me/saved');
        $listAfter->assertJsonCount(1, 'saved_items');
    }

    public function test_user_data_isolation_between_accounts(): void
    {
        $tokenA = $this->tokenFor($this->userA);
        $tokenB = $this->tokenFor($this->userB);

        // User A follows team
        $this->withToken($tokenA)->postJson('/api/v1/me/follows', [
            'followable_type' => 'team',
            'followable_id' => $this->team->id,
        ])->assertStatus(201);

        // User B has zero follows
        app('auth')->forgetGuards();
        $resB = $this->withToken($tokenB)->getJson('/api/v1/me/follows');
        $resB->assertStatus(200);
        $resB->assertJsonCount(0, 'follows');

        // User B cannot delete User A's follow
        $delB = $this->withToken($tokenB)->deleteJson("/api/v1/me/follows/team/{$this->team->id}");
        $delB->assertStatus(200);
        $delB->assertJsonPath('deleted', false);

        // User A's follow is still intact
        app('auth')->forgetGuards();
        $resA = $this->withToken($tokenA)->getJson('/api/v1/me/follows');
        $resA->assertJsonCount(1, 'follows');
    }
}
