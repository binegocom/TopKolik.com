<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

final class HealthEndpointTest extends TestCase
{
    public function test_liveness_and_request_id(): void
    {
        $response = $this->get('/health');
        $response->assertOk();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', (string) $response->headers->get('X-Request-ID'));
    }

    public function test_api_status_uses_versioned_route(): void
    {
        $this->getJson('/api/v1/status')
            ->assertOk()
            ->assertExactJson(['service' => 'topkolik-api', 'status' => 'operational']);
    }

    public function test_ready_probe_is_healthy_with_database_and_redis(): void
    {
        $this->getJson('/ready')->assertOk()->assertExactJson(['status' => 'ready']);
    }

    public function test_ready_probe_fails_closed_without_exposing_database_errors(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->with('SELECT 1')
            ->andThrow(new RuntimeException('private database credentials'));

        $response = $this->getJson('/ready');
        $response->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
        $this->assertStringNotContainsString('credentials', (string) $response->getContent());
    }

    public function test_ready_probe_fails_closed_without_exposing_redis_errors(): void
    {
        DB::shouldReceive('select')->once()->with('SELECT 1')->andReturn([]);
        Redis::shouldReceive('connection')->once()->andThrow(new RuntimeException('private redis credentials'));

        $response = $this->getJson('/ready');
        $response->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
        $this->assertStringNotContainsString('credentials', (string) $response->getContent());
    }
}
