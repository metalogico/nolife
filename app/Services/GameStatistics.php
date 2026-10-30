<?php

namespace App\Services;

use App\Enums\GameFormat;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameImage;
use Illuminate\Support\Collection;

class GameStatistics
{
    public function overview(): array
    {
        $games = Game::query()->get([
            'id', 'title', 'platform', 'format', 'status', 'rating',
            'duration_minutes', 'total_played_minutes', 'genres',
        ]);
        $summary = $this->summarize($games);
        $planned = $games->where('status', GameStatus::Planned);
        $rated = $games->whereNotNull('rating');
        $played = $this->rank($games->where('total_played_minutes', '>', 0), 'total_played_minutes');
        $short = $this->rank($planned->where('duration_minutes', '>', 0), 'duration_minutes', false);
        $favorites = $this->rank($rated, 'rating');
        $rankedIds = $played->merge($short)->merge($favorites)->pluck('id')->unique();
        $covers = GameImage::query()->whereIn('game_id', $rankedIds)->where('kind', 'cover')
            ->orderBy('position')->orderBy('id')->get()->unique('game_id')->keyBy('game_id');
        $genreGroups = $games->groupBy(fn (Game $game): array => $this->genres($game));
        $startedCount = $games->count() - $planned->count();
        $knownDurations = $planned->whereNotNull('duration_minutes');

        return [
            'collection' => [
                'total' => $games->count(),
                'statuses' => array_map(fn (GameStatus $status): array => [
                    'status' => $status->value,
                    'count' => $games->where('status', $status)->count(),
                    'percentage' => $this->percentage($games->where('status', $status)->count(), $games->count()),
                ], GameStatus::cases()),
                'completed_percentage' => $summary['completed_percentage'],
                'started_count' => $startedCount,
                'started_completed_percentage' => $this->percentage($summary['completed_count'], $startedCount),
            ],
            'playtime' => [
                'total_minutes' => $summary['played_minutes'],
                'recorded_count' => $summary['played_count'],
                'top_games' => $this->gameReferences($played, $covers),
            ],
            'backlog' => [
                'count' => $planned->count(),
                'known_duration_count' => $knownDurations->count(),
                'expected_minutes' => $knownDurations->isEmpty() ? null : $knownDurations->sum('duration_minutes'),
                'short_games' => $this->gameReferences($short, $covers),
            ],
            'genres' => [
                'classified_count' => $games->filter(fn (Game $game): bool => $this->genres($game) !== [])->count(),
                'groups' => $this->groups($genreGroups),
            ],
            'platforms' => $this->groups($games->groupBy('platform')),
            'formats' => array_map(fn (GameFormat $format): array => [
                'format' => $format->value,
                'count' => $games->where('format', $format)->count(),
                'percentage' => $this->percentage($games->where('format', $format)->count(), $games->count()),
            ], GameFormat::cases()),
            'ratings' => [
                'average' => $summary['average_rating'],
                'rated_count' => $rated->count(),
                'distribution' => array_map(fn (int $rating): array => [
                    'rating' => $rating,
                    'count' => $rated->where('rating', $rating)->count(),
                    'percentage' => $this->percentage($rated->where('rating', $rating)->count(), $rated->count()),
                ], range(1, 10)),
                'favorites' => $this->gameReferences($favorites, $covers),
            ],
        ];
    }

    /**
     * @param  Collection<int, Game>  $games
     * @return array{count: int, played_minutes: ?int, played_count: int, completed_count: int, completed_percentage: ?float, average_rating: ?float, rated_count: int}
     */
    private function summarize(Collection $games): array
    {
        $played = $games->whereNotNull('total_played_minutes');
        $rated = $games->whereNotNull('rating');
        $completedCount = $games->where('status', GameStatus::Completed)->count();

        return [
            'count' => $games->count(),
            'played_minutes' => $played->isEmpty() ? null : $played->sum('total_played_minutes'),
            'played_count' => $played->count(),
            'completed_count' => $completedCount,
            'completed_percentage' => $this->percentage($completedCount, $games->count()),
            'average_rating' => $rated->isEmpty() ? null : round($rated->avg('rating'), 1),
            'rated_count' => $rated->count(),
        ];
    }

    /**
     * @return list<string>
     */
    private function genres(Game $game): array
    {
        return array_values(array_unique(array_filter(
            $game->genres ?? [],
            fn (mixed $genre): bool => is_string($genre) && trim($genre) !== '',
        )));
    }

    /**
     * @param  Collection<array-key, Collection<int, Game>>  $groups
     * @return list<array{name: string, count: int, played_minutes: ?int, played_count: int, completed_count: int, completed_percentage: ?float, average_rating: ?float, rated_count: int}>
     */
    private function groups(Collection $groups): array
    {
        return $groups->map(fn (Collection $games, string|int $name): array => [
            'name' => (string) $name,
            ...$this->summarize($games),
        ])->sort(fn (array $first, array $second): int => ($second['count'] <=> $first['count'])
            ?: strcasecmp($first['name'], $second['name']))->values()->all();
    }

    /**
     * @param  Collection<int, Game>  $games
     * @return Collection<int, Game>
     */
    private function rank(Collection $games, string $field, bool $descending = true): Collection
    {
        return $games->sort(fn (Game $first, Game $second): int => ($descending ? $second->{$field} <=> $first->{$field} : $first->{$field} <=> $second->{$field})
            ?: strcasecmp($first->title, $second->title)
            ?: ($first->id <=> $second->id)
        )->take(5)->values();
    }

    /**
     * @param  Collection<int, Game>  $games
     * @param  Collection<int, GameImage>  $covers
     * @return list<array{id: int, title: string, platform: string, format: string, thumbnail_url: ?string, total_played_minutes: ?int, duration_minutes: ?int, rating: ?int}>
     */
    private function gameReferences(Collection $games, Collection $covers): array
    {
        return $games->map(fn (Game $game): array => [
            'id' => $game->id,
            'title' => $game->title,
            'platform' => $game->platform,
            'format' => $game->format->value,
            'thumbnail_url' => $covers->get($game->id)?->url('thumbnail'),
            'total_played_minutes' => $game->total_played_minutes,
            'duration_minutes' => $game->duration_minutes,
            'rating' => $game->rating,
        ])->values()->all();
    }

    private function percentage(int $count, int $total): ?float
    {
        return $total === 0 ? null : round($count / $total * 100, 1);
    }
}
