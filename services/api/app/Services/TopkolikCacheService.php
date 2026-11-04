<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

final class TopkolikCacheService
{
    /**
     * Create a cached JSON response with ETag and Cache-Control.
     * Supports conditional HTTP 304 Not Modified.
     */
    public function makeCachedResponse(Request $request, array $data, int $ttlSeconds = 180, bool $isPublic = true): JsonResponse|Response
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $etag = '"' . sha1($json) . '"';

        $cacheControl = $isPublic
            ? "public, max-age={$ttlSeconds}, stale-while-revalidate=" . min(60, $ttlSeconds)
            : 'private, no-cache, no-store, must-revalidate';

        $ifNoneMatch = $request->header('If-None-Match');
        if ($ifNoneMatch !== null && (trim($ifNoneMatch) === $etag || trim($ifNoneMatch) === '*')) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => $cacheControl,
            ]);
        }

        return response()->json($data, 200, [
            'ETag' => $etag,
            'Cache-Control' => $cacheControl,
        ]);
    }

    /**
     * Invalidate cached keys by tag or pattern.
     */
    public function invalidate(string $tag): void
    {
        Cache::forget("topkolik:{$tag}");
    }
}

