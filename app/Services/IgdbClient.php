<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class IgdbClient
{
    private const FIELDS = 'name,url,summary,'
        .'first_release_date,cover.image_id,artworks.image_id,'
        .'artworks.image_type.name,artworks.alpha_channel,artworks.width,'
        .'artworks.height,screenshots.image_id,platforms.name,'
        .'platforms.abbreviation,involved_companies.company.name,involved_companies.developer,'
        .'involved_companies.publisher,genres.name';

    public function configured(): bool
    {
        return filled(config('nolife.igdb_client_id')) && filled(config('nolife.igdb_client_secret'));
    }

    public function search(string $query, string $by = 'title'): array
    {
        $this->ensureConfigured();
        $query = trim($query);
        if ($by === 'id') {
            return [$this->game((int) $query)];
        }

        return Cache::remember('nolife:igdb:v4:search:'.hash('sha256', $query), 120, function () use ($query): array {
            $encodedQuery = json_encode($query, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $body = 'search '.$encodedQuery.'; fields '.self::FIELDS.'; limit 24;';

            $games = $this->request($body);
            usort($games, function (array $left, array $right): int {
                $leftRelease = $left['first_release_date'] ?? PHP_INT_MIN;
                $rightRelease = $right['first_release_date'] ?? PHP_INT_MIN;

                return $rightRelease <=> $leftRelease;
            });

            return array_map($this->normalize(...), $games);
        });
    }

    public function game(int $id): array
    {
        $this->ensureConfigured();

        return Cache::remember('nolife:igdb:v3:game:'.$id, 300, function () use ($id): array {
            $results = $this->request('fields '.self::FIELDS.'; where id = '.$id.'; limit 1;');
            abort_if(empty($results), 404, 'No matching game was found on IGDB.');

            return $this->normalize($results[0]);
        });
    }

    private function ensureConfigured(): void
    {
        abort_unless($this->configured(), 503, 'IGDB is not configured yet. You can still enter the game manually.');
    }

    private function token(): string
    {
        $key = 'nolife:igdb:token:'.hash('sha256', config('nolife.igdb_client_id'));

        return Cache::get($key) ?? Cache::lock($key.':lock', 15)->block(5, function () use ($key): string {
            if ($cached = Cache::get($key)) {
                return $cached;
            }

            try {
                $response = Http::asForm()->connectTimeout(3)->timeout(8)->post('https://id.twitch.tv/oauth2/token', [
                    'client_id' => config('nolife.igdb_client_id'),
                    'client_secret' => config('nolife.igdb_client_secret'),
                    'grant_type' => 'client_credentials',
                ]);
            } catch (ConnectionException) {
                throw new HttpException(502, 'IGDB could not be reached. Please try again.');
            }

            abort_unless($response->successful() && $response->json('access_token'), 502, 'IGDB credentials could not be verified.');
            $token = (string) $response->json('access_token');
            Cache::put($key, $token, max(1, (int) $response->json('expires_in', 3600) - 60));

            return $token;
        });
    }

    private function request(string $body): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            abort_if(RateLimiter::tooManyAttempts('nolife:igdb:quota', 4), 429, 'IGDB is busy. Please try again in a moment.');
            RateLimiter::hit('nolife:igdb:quota', 1);

            try {
                $response = Http::withHeaders([
                    'Client-ID' => config('nolife.igdb_client_id'),
                    'Authorization' => 'Bearer '.$this->token(),
                ])->withBody($body, 'text/plain')->connectTimeout(3)->timeout(10)
                    ->post('https://api.igdb.com/v4/games');
            } catch (ConnectionException) {
                if ($attempt === 0) {
                    continue;
                }
                throw new HttpException(502, 'IGDB could not be reached. Please try again.');
            }

            if ($response->status() === 401 && $attempt === 0) {
                Cache::forget('nolife:igdb:token:'.hash('sha256', config('nolife.igdb_client_id')));

                continue;
            }
            if ($response->serverError() && $attempt === 0) {
                continue;
            }

            abort_if($response->status() === 429, 429, 'IGDB is busy. Please try again in a moment.');
            abort_unless($response->successful(), 502, 'IGDB could not complete the search. Please try again.');
            $data = $response->json();
            abort_unless(is_array($data) && array_is_list($data), 502, 'IGDB returned an unexpected response.');

            return $data;
        }

        throw new HttpException(502, 'IGDB could not complete the request.');
    }

    public static function imageUrl(string $sourceId, string $size = '720p'): string
    {
        return 'https://images.igdb.com/igdb/image/upload/t_'.$size.'/'.rawurlencode($sourceId).'.jpg';
    }

    private function isUnsuitableBackground(array $image): bool
    {
        $imageType = Str::lower(data_get($image, 'image_type.name', ''));

        return Str::contains($imageType, ['logo', 'icon'])
            || ($image['alpha_channel'] ?? false)
            || (isset($image['width'], $image['height']) && $image['width'] < $image['height'] * 1.3);
    }

    private function normalize(array $game): array
    {
        $images = [];
        if ($id = data_get($game, 'cover.image_id')) {
            $images[] = ['kind' => 'cover', 'source_id' => $id, 'url' => self::imageUrl($id, 'cover_big')];
        }
        $artworks = collect($game['artworks'] ?? [])
            ->reject($this->isUnsuitableBackground(...))
            ->take(2);
        foreach ($artworks as $image) {
            $images[] = ['kind' => 'artwork', 'source_id' => $image['image_id'], 'url' => self::imageUrl($image['image_id'])];
        }
        foreach (array_slice($game['screenshots'] ?? [], 0, 8) as $image) {
            $images[] = ['kind' => 'screenshot', 'source_id' => $image['image_id'], 'url' => self::imageUrl($image['image_id'])];
        }
        $companies = collect($game['involved_companies'] ?? []);

        return [
            'id' => (int) $game['id'],
            'title' => $game['name'],
            'summary' => Str::limit($game['summary'] ?? '', 240, '…', preserveWords: true),
            'release_year' => isset($game['first_release_date']) ? (int) gmdate('Y', $game['first_release_date']) : null,
            'developer' => $companies->where('developer', true)->pluck('company.name')->filter()->unique()->implode(', ') ?: null,
            'publisher' => $companies->where('publisher', true)->pluck('company.name')->filter()->unique()->implode(', ') ?: null,
            'genres' => array_column($game['genres'] ?? [], 'name'),
            'url' => $game['url'] ?? null,
            'platforms' => array_map(fn (array $platform): array => [
                'id' => $platform['id'],
                'name' => $platform['name'],
                'label' => $platform['abbreviation'] ?? $platform['name'],
            ], $game['platforms'] ?? []),
            'cover_url' => isset($game['cover']['image_id']) ? self::imageUrl($game['cover']['image_id'], 'cover_big') : null,
            'images' => $images,
        ];
    }
}
