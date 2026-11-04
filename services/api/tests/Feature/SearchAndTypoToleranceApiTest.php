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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SearchAndTypoToleranceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $football = Sport::create(['slug' => 'futbol', 'name' => 'Futbol']);

        $superLig = Competition::create([
            'sport_id' => $football->id,
            'slug' => 'super-lig',
            'name' => 'Trendyol Süper Lig',
            'country_code' => 'TR',
        ]);

        $season = Season::create([
            'competition_id' => $superLig->id,
            'label' => '2026-2027',
            'starts_on' => '2026-08-10',
            'ends_on' => '2027-05-25',
            'is_current' => true,
        ]);

        $galatasaray = Team::create([
            'sport_id' => $football->id,
            'slug' => 'galatasaray',
            'name' => 'Galatasaray',
            'short_name' => 'GS',
        ]);

        $besiktas = Team::create([
            'sport_id' => $football->id,
            'slug' => 'besiktas',
            'name' => 'Beşiktaş',
            'short_name' => 'BJK',
        ]);

        $fenerbahce = Team::create([
            'sport_id' => $football->id,
            'slug' => 'fenerbahce',
            'name' => 'Fenerbahçe',
            'short_name' => 'FB',
        ]);

        Athlete::create([
            'sport_id' => $football->id,
            'current_team_id' => $galatasaray->id,
            'slug' => 'victor-osimhen',
            'name' => 'Victor Osimhen',
            'position' => 'FW',
            'shirt_number' => 45,
            'nationality' => 'Nigeria',
        ]);

        Athlete::create([
            'sport_id' => $football->id,
            'current_team_id' => $galatasaray->id,
            'slug' => 'mauro-icardi',
            'name' => 'Mauro Icardi',
            'position' => 'FW',
            'shirt_number' => 9,
            'nationality' => 'Argentina',
        ]);

        Fixture::create([
            'competition_id' => $superLig->id,
            'season_id' => $season->id,
            'home_team_id' => $galatasaray->id,
            'away_team_id' => $besiktas->id,
            'status' => 'scheduled',
            'starts_at' => now()->addDays(3),
            'source_revision' => 1,
        ]);

        $staff = StaffMember::create([
            'name' => 'Editor Person',
            'email' => 'editor-'.Str::uuid().'@topkolik.test',
            'password' => 'StaffPass123!',
            'role' => 'editor',
        ]);
        $staff->forceFill(['is_active' => true, 'email_verified_at' => now(), 'mfa_enrolled_at' => now()])->save();

        EditorialArticle::create([
            'title' => 'Derbi Öncesi Taktik Analiz ve Tribün Beklentileri',
            'body' => 'Büyük derbi için nefesler tutuldu...',
            'owner_staff_id' => $staff->id,
            'status' => 'published',
            'revision' => 1,
        ]);
    }

    public function test_short_query_returns_empty_results_gracefully(): void
    {
        $response = $this->getJson('/api/v1/search?q=a');
        $response->assertStatus(200);
        $response->assertJsonPath('total', 0);
        $response->assertJsonPath('results', []);
    }

    public function test_turkish_character_folding_matches_team_names(): void
    {
        // "besiktas" (ascii) matches "Beşiktaş" (Turkish ş)
        $response = $this->getJson('/api/v1/search?q=besiktas');
        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $response->json('total'));
        $found = collect($response->json('results'))->firstWhere('title', 'Beşiktaş');
        $this->assertNotNull($found);
        $this->assertSame('team', $found['type']);
    }

    public function test_fuzzy_prefix_matches_teams_and_athletes(): void
    {
        // "galata" matches "Galatasaray"
        $response = $this->getJson('/api/v1/search?q=galata');
        $response->assertStatus(200);
        $found = collect($response->json('results'))->firstWhere('title', 'Galatasaray');
        $this->assertNotNull($found);

        // "osimhen" matches "Victor Osimhen"
        $response2 = $this->getJson('/api/v1/search?q=osimhen');
        $response2->assertStatus(200);
        $found2 = collect($response2->json('results'))->firstWhere('title', 'Victor Osimhen');
        $this->assertNotNull($found2);
        $this->assertSame('athlete', $found2['type']);
    }

    public function test_type_filter_restricts_returned_entities(): void
    {
        // Query for competition only
        $response = $this->getJson('/api/v1/search?q=super&type=competition');
        $response->assertStatus(200);
        $results = $response->json('results');
        $this->assertNotEmpty($results);
        foreach ($results as $item) {
            $this->assertSame('competition', $item['type']);
        }
    }

    public function test_search_special_characters_and_whitespace_are_safe(): void
    {
        $response = $this->getJson('/api/v1/search?q=' . urlencode("   '' OR 1=1 -- <> ? %   "));
        $response->assertStatus(200);
        $this->assertIsArray($response->json('results'));
    }
}
