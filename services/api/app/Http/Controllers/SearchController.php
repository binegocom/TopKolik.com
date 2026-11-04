<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\Competition;
use App\Models\EditorialArticle;
use App\Models\Fixture;
use App\Models\Team;
use App\Services\TopkolikCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class SearchController extends Controller
{
    public function __construct(
        private readonly TopkolikCacheService $cacheService,
    ) {}

    public function search(Request $request): JsonResponse|Response
    {
        $rawQuery = (string) $request->query('q', '');
        $type = (string) $request->query('type', '');
        $cleanQuery = trim($rawQuery);

        if (mb_strlen($cleanQuery) < 2) {
            return $this->cacheService->makeCachedResponse($request, [
                'query' => $rawQuery,
                'total' => 0,
                'results' => [],
            ], 60, true);
        }

        // Turkish character folding for typo-tolerance and accent insensitivity
        $folded = $this->foldTurkishChars($cleanQuery);
        $wildcardOriginal = '%' . $cleanQuery . '%';
        $wildcardFolded = '%' . $folded . '%';

        $results = [];

        // 1. Teams
        if ($type === '' || $type === 'team') {
            $teams = Team::query()
                ->where(function ($q) use ($wildcardOriginal, $wildcardFolded) {
                    $q->where('name', 'ILIKE', $wildcardOriginal)
                        ->orWhere('slug', 'ILIKE', $wildcardFolded)
                        ->orWhere('short_name', 'ILIKE', $wildcardOriginal);
                })
                ->with('sport')
                ->limit(8)
                ->get();

            foreach ($teams as $t) {
                $results[] = [
                    'type' => 'team',
                    'id' => $t->id,
                    'title' => $t->name,
                    'subtitle' => $t->sport?->name ?? 'Futbol',
                    'slug' => $t->slug,
                    'image_url' => $t->logo_url,
                    'url' => "/teams/{$t->slug}",
                ];
            }
        }

        // 2. Competitions
        if ($type === '' || $type === 'competition') {
            $competitions = Competition::query()
                ->where(function ($q) use ($wildcardOriginal, $wildcardFolded) {
                    $q->where('name', 'ILIKE', $wildcardOriginal)
                        ->orWhere('slug', 'ILIKE', $wildcardFolded);
                })
                ->with('sport')
                ->limit(6)
                ->get();

            foreach ($competitions as $c) {
                $results[] = [
                    'type' => 'competition',
                    'id' => $c->id,
                    'title' => $c->name,
                    'subtitle' => $c->sport?->name ?? 'Lig',
                    'slug' => $c->slug,
                    'image_url' => null,
                    'url' => "/competitions/{$c->slug}",
                ];
            }
        }

        // 3. Athletes
        if ($type === '' || $type === 'athlete') {
            $athletes = Athlete::query()
                ->where(function ($q) use ($wildcardOriginal, $wildcardFolded) {
                    $q->where('name', 'ILIKE', $wildcardOriginal)
                        ->orWhere('slug', 'ILIKE', $wildcardFolded)
                        ->orWhere('first_name', 'ILIKE', $wildcardOriginal)
                        ->orWhere('last_name', 'ILIKE', $wildcardOriginal);
                })
                ->with('currentTeam')
                ->limit(8)
                ->get();

            foreach ($athletes as $a) {
                $results[] = [
                    'type' => 'athlete',
                    'id' => $a->id,
                    'title' => $a->name,
                    'subtitle' => ($a->currentTeam?->name ?? 'Oyuncu') . " • {$a->position}",
                    'slug' => $a->slug,
                    'image_url' => $a->photo_url,
                    'url' => "/athletes/{$a->slug}",
                ];
            }
        }

        // 4. Fixtures
        if ($type === '' || $type === 'fixture') {
            $fixtures = Fixture::query()
                ->whereHas('homeTeam', fn ($q) => $q->where('name', 'ILIKE', $wildcardOriginal)->orWhere('slug', 'ILIKE', $wildcardFolded))
                ->orWhereHas('awayTeam', fn ($q) => $q->where('name', 'ILIKE', $wildcardOriginal)->orWhere('slug', 'ILIKE', $wildcardFolded))
                ->with(['competition', 'homeTeam', 'awayTeam'])
                ->orderByDesc('starts_at')
                ->limit(6)
                ->get();

            foreach ($fixtures as $f) {
                $home = $f->homeTeam?->name ?? 'Ev';
                $away = $f->awayTeam?->name ?? 'Dep';
                $results[] = [
                    'type' => 'fixture',
                    'id' => $f->id,
                    'title' => "{$home} vs {$away}",
                    'subtitle' => ($f->competition?->name ?? 'Maç') . ' • ' . ($f->starts_at?->format('d.m.Y H:i') ?? ''),
                    'slug' => "fixture-{$f->id}",
                    'image_url' => null,
                    'url' => "/matches/{$f->id}",
                ];
            }
        }

        // 5. Editorial Articles
        if ($type === '' || $type === 'article') {
            $articles = EditorialArticle::query()
                ->where('status', 'published')
                ->where(function ($q) use ($wildcardOriginal) {
                    $q->where('title', 'ILIKE', $wildcardOriginal)
                        ->orWhere('body', 'ILIKE', $wildcardOriginal);
                })
                ->limit(6)
                ->get();

            foreach ($articles as $art) {
                $results[] = [
                    'type' => 'article',
                    'id' => $art->id,
                    'title' => $art->title,
                    'subtitle' => 'Haber • ' . ($art->created_at?->format('d.m.Y') ?? ''),
                    'slug' => "article-{$art->id}",
                    'image_url' => null,
                    'url' => "/news/{$art->id}",
                ];
            }
        }

        return $this->cacheService->makeCachedResponse($request, [
            'query' => $rawQuery,
            'total' => count($results),
            'results' => $results,
        ], 60, true);
    }

    private function foldTurkishChars(string $str): string
    {
        $map = [
            'ç' => 'c', 'Ç' => 'c',
            'ğ' => 'g', 'Ğ' => 'g',
            'ı' => 'i', 'I' => 'i',
            'i' => 'i', 'İ' => 'i',
            'ö' => 'o', 'Ö' => 'o',
            'ş' => 's', 'Ş' => 's',
            'ü' => 'u', 'Ü' => 'u',
        ];

        return mb_strtolower(strtr($str, $map));
    }
}
