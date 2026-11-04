<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Sport;
use App\Models\Team;
use App\Models\User;
use App\Services\TopkolikCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class ApiRateLimitingAndCachePolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $reader;
    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = User::create([
            'name' => 'Cache Tester',
            'email' => 'cache@topkolik.test',
            'password' => bcrypt('Pass123!'),
        ]);
        $this->reader->markEmailAsVerified();

        $football = Sport::create(['slug' => 'futbol', 'name' => 'Futbol']);

        $this->team = Team::create([
            'sport_id' => $football->id,
            'slug' => 'trabzonspor',
            'name' => 'Trabzonspor',
            'short_name' => 'TS',
        ]);
    }

    public function test_public_api_rate_limiter_sets_rate_limit_headers(): void
    {
        $response = $this->getJson('/api/v1/teams/trabzonspor');
        $response->assertStatus(200);

        // Rate limiter headers
        $this->assertTrue($response->headers->has('X-RateLimit-Limit') || $response->headers->has('RateLimit-Limit'));
    }

    public function test_search_api_rate_limiter_sets_rate_limit_headers(): void
    {
        $response = $this->getJson('/api/v1/search?q=trabzon');
        $response->assertStatus(200);

        $this->assertTrue($response->headers->has('X-RateLimit-Limit') || $response->headers->has('RateLimit-Limit'));
    }

    public function test_reader_api_rate_limiter_sets_rate_limit_headers(): void
    {
        $token = $this->reader->createToken('test', ['reader:read', 'reader:write'], now()->addHours(2))->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/me/follows');
        $response->assertStatus(200);

        $this->assertTrue($response->headers->has('X-RateLimit-Limit') || $response->headers->has('RateLimit-Limit'));
    }

    public function test_caching_and_conditional_revalidation_headers(): void
    {
        $response = $this->getJson('/api/v1/teams/trabzonspor');
        $response->assertStatus(200);

        $etag = $response->headers->get('ETag');
        $this->assertNotNull($etag);

        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=180', $cacheControl);

        // Matching ETag returns 304
        $revalidated = $this->withHeader('If-None-Match', $etag)->getJson('/api/v1/teams/trabzonspor');
        $revalidated->assertStatus(304);
    }

    public function test_cache_invalidation_service_clears_cached_keys(): void
    {
        $service = app(TopkolikCacheService::class);
        Cache::put('topkolik:team-trabzonspor', ['cached' => true], 300);

        $this->assertTrue(Cache::has('topkolik:team-trabzonspor'));

        $service->invalidate('team-trabzonspor');
        $this->assertFalse(Cache::has('topkolik:team-trabzonspor'));
    }
}
