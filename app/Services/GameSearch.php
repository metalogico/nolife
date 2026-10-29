<?php

namespace App\Services;

use App\Enums\GameFormat;
use App\Enums\GameStatus;
use App\Models\Game;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GameSearch
{
    public const SORTS = ['title', 'rating', 'duration_minutes', 'total_played_minutes', 'release_year', 'created_at'];

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{
     *     games: list<array<string, mixed>>,
     *     meta: array{current_page: int, last_page: int, next_page: ?int, per_page: int,
     *         total: int, matching_total: int, missing_sort_values: int, sort_by: string, sort_direction: string}
     * }
     */
    public function search(array $arguments): array
    {
        $rules = [
            'query' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(GameStatus::class)],
            'platform' => ['sometimes', 'string', 'max:80'],
            'format' => ['sometimes', Rule::enum(GameFormat::class)],
            'genre' => ['sometimes', 'string', 'max:255'],
            'sort_by' => ['sometimes', Rule::in(self::SORTS)],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
        if (array_diff(array_keys($arguments), array_keys($rules)) !== []) {
            throw ValidationException::withMessages(['arguments' => 'Unsupported search fields.']);
        }
        $filters = Validator::make($arguments, $rules)->validate();
        $query = $this->filteredQuery($filters);
        $sort = $filters['sort_by'] ?? 'title';
        $direction = $filters['sort_direction'] ?? ($sort === 'title' ? 'asc' : 'desc');
        $matchingTotal = (clone $query)->count();
        $missing = 0;
        if (in_array($sort, ['rating', 'duration_minutes', 'total_played_minutes', 'release_year'], true)) {
            $missing = (clone $query)->whereNull($sort)->count();
            $query->whereNotNull($sort);
        }
        if ($sort === 'title') {
            $query->orderByRaw('title COLLATE NOCASE '.$direction);
        } else {
            $query->orderBy($sort, $direction)->orderByRaw('title COLLATE NOCASE');
        }
        $results = $query->orderBy('id')->paginate($filters['per_page'] ?? 25, ['*'], 'page', $filters['page'] ?? 1);

        return [
            'games' => $results->getCollection()->map(fn (Game $game): array => [
                'id' => $game->id,
                'title' => $game->title,
                'platform' => $game->platform,
                'format' => $game->format->value,
                'status' => $game->status->value,
                'rating' => $game->rating,
                'duration_minutes' => $game->duration_minutes,
                'total_played_minutes' => $game->total_played_minutes,
                'release_year' => $game->release_year,
                'genres' => $game->genres ?? [],
                'igdb_id' => $game->igdb_id,
            ])->all(),
            'meta' => [
                'current_page' => $results->currentPage(),
                'last_page' => $results->lastPage(),
                'next_page' => $results->hasMorePages() ? $results->currentPage() + 1 : null,
                'per_page' => $results->perPage(),
                'total' => $results->total(),
                'matching_total' => $matchingTotal,
                'missing_sort_values' => $missing,
                'sort_by' => $sort,
                'sort_direction' => $direction,
            ],
        ];
    }

    private function filteredQuery(array $filters): Builder
    {
        $query = Game::query();
        if (filled($filters['query'] ?? null)) {
            // SQLite's built-in LIKE only folds ASCII; normalize Unicode without storing a second title.
            $query->getConnection()->getPdo()->sqliteCreateFunction('nolife_search_title', $this->normalizeTitle(...), 1);
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->normalizeTitle(trim($filters['query'])));
            $query->whereRaw("nolife_search_title(title) LIKE ? ESCAPE '\\'", ['%'.$search.'%']);
        }
        foreach (['status', 'platform', 'format'] as $filter) {
            if (isset($filters[$filter])) {
                $query->where($filter, $filters[$filter]);
            }
        }
        if (isset($filters['genre'])) {
            $query->whereJsonContains('genres', $filters['genre']);
        }

        return $query;
    }

    private function normalizeTitle(string $title): string
    {
        return mb_strtolower(str_replace(['’', '‘', 'ʼ'], "'", $title));
    }
}
