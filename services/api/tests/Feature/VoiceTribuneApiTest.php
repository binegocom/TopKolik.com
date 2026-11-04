<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sport;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VoiceTribuneApiTest extends TestCase
{
    use RefreshDatabase;

    private function reader(string $name = 'Voice Fan'): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => 'voice-'.Str::uuid().'@example.test',
            'email_verified_at' => now(),
        ]);
    }

    private function readerToken(User $reader, array $abilities = ['reader:read', 'reader:write']): string
    {
        return $reader->createToken('mobile', $abilities, now()->addHours(2))->plainTextToken;
    }

    private function createMatchFixture(): array
    {
        $sport = Sport::create(['slug' => 'football-'.Str::random(6), 'name' => 'Football']);
        $homeTeam = Team::create(['sport_id' => $sport->id, 'slug' => 'galatasaray-'.Str::random(6), 'name' => 'Galatasaray']);
        $awayTeam = Team::create(['sport_id' => $sport->id, 'slug' => 'fenerbahce-'.Str::random(6), 'name' => 'Fenerbahçe']);

        $now = now();
        $competitionId = DB::table('competitions')->insertGetId([
            'sport_id' => $sport->id,
            'slug' => 'super-lig-'.Str::random(6),
            'name' => 'Trendyol Süper Lig',
            'country_code' => 'TR',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $seasonId = DB::table('seasons')->insertGetId([
            'competition_id' => $competitionId,
            'label' => '2026-2027',
            'starts_on' => $now->toDateString(),
            'ends_on' => $now->copy()->addMonths(9)->toDateString(),
            'is_current' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fixtureId = DB::table('fixtures')->insertGetId([
            'competition_id' => $competitionId,
            'season_id' => $seasonId,
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'status' => 'live',
            'starts_at' => $now->copy()->subMinutes(30),
            'home_score' => 1,
            'away_score' => 0,
            'first_scoring_team' => 'home',
            'source_revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'fixtureId' => $fixtureId,
            'homeTeam' => $homeTeam,
            'awayTeam' => $awayTeam,
        ];
    }

    public function test_can_list_voice_tribune_rooms_for_match(): void
    {
        $data = $this->createMatchFixture();
        $fixtureId = $data['fixtureId'];
        $homeTeam = $data['homeTeam'];

        $host = $this->reader('Tribün Lideri');
        $now = now();

        DB::table('voice_tribune_rooms')->insert([
            'fixture_id' => $fixtureId,
            'club_id' => $homeTeam->id,
            'created_by_user_id' => $host->id,
            'title' => 'Derbi 2. Yarı Taktik Analiz ve VAR Değerlendirmesi',
            'phase_tag' => 'MAÇ ANINDA CANLI',
            'listeners_count' => 1420,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->getJson("/api/v1/matches/{$fixtureId}/voice-tribunes");

        $response->assertOk();
        $this->assertStringContainsString('max-age=10', (string) $response->headers->get('Cache-Control'));

        $rooms = $response->json('rooms');
        $this->assertCount(1, $rooms);
        $this->assertSame('Derbi 2. Yarı Taktik Analiz ve VAR Değerlendirmesi', $rooms[0]['title']);
        $this->assertSame('MAÇ ANINDA CANLI', $rooms[0]['phase_tag']);
        $this->assertSame(1420, $rooms[0]['listeners_count']);
        $this->assertSame('Tribün Lideri', $rooms[0]['host_name']);
    }

    public function test_authenticated_user_can_create_voice_tribune_room_and_earn_xp(): void
    {
        $data = $this->createMatchFixture();
        $fixtureId = $data['fixtureId'];
        $homeTeam = $data['homeTeam'];

        $user = $this->reader('Oda Sahibi');
        $token = $this->readerToken($user);

        $response = $this->withToken($token)
            ->withSession(['auth_role' => 'reader'])
            ->postJson("/api/v1/matches/{$fixtureId}/voice-tribunes", [
                'title' => 'Maç Önü Kadro ve Isınma Canlı Yayını',
                'phase_tag' => 'MAÇ ÖNÜ ISINMA',
                'club_id' => $homeTeam->id,
            ]);

        $response->assertCreated();
        $this->assertSame(40, $response->json('xp_awarded'));
        $this->assertSame('Maç Önü Kadro ve Isınma Canlı Yayını', $response->json('room.title'));
        $this->assertSame('MAÇ ÖNÜ ISINMA', $response->json('room.phase_tag'));

        $this->assertDatabaseHas('voice_tribune_rooms', [
            'fixture_id' => $fixtureId,
            'title' => 'Maç Önü Kadro ve Isınma Canlı Yayını',
            'phase_tag' => 'MAÇ ÖNÜ ISINMA',
            'created_by_user_id' => $user->id,
        ]);
    }

    public function test_interactive_voice_actions_work_correctly(): void
    {
        $data = $this->createMatchFixture();
        $fixtureId = $data['fixtureId'];
        $user = $this->reader('Dinleyici');
        $token = $this->readerToken($user);

        $now = now();
        $roomId = DB::table('voice_tribune_rooms')->insertGetId([
            'fixture_id' => $fixtureId,
            'club_id' => null,
            'created_by_user_id' => $user->id,
            'title' => 'Genel Maç Sonu 3. Devre Tartışması',
            'phase_tag' => 'MAÇ SONU 3. DEVRE',
            'listeners_count' => 10,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Toggle join increments listener count
        $responseJoin = $this->withToken($token)
            ->withSession(['auth_role' => 'reader'])
            ->postJson("/api/v1/voice-tribunes/{$roomId}/actions", [
                'action' => 'toggle-join',
            ]);
        $responseJoin->assertOk();
        $this->assertSame('toggle-join', $responseJoin->json('action'));

        $this->assertDatabaseHas('voice_tribune_rooms', [
            'id' => $roomId,
            'listeners_count' => 11,
        ]);

        // Emoji reaction
        $responseReact = $this->withToken($token)
            ->withSession(['auth_role' => 'reader'])
            ->postJson("/api/v1/voice-tribunes/{$roomId}/actions", [
                'action' => 'react',
                'emoji' => '🔥',
            ]);
        $responseReact->assertOk();
        $this->assertSame('🔥', $responseReact->json('emoji'));
    }
}
