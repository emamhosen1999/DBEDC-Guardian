<?php

declare(strict_types=1);

namespace App\Services\Aeon\RagService;

namespace App\Services\Aeon;

use App\Contracts\Ai\AiProvider;
use App\Models\Aeon\Embedding;
use App\Models\User;
use App\Services\Aeon\Data\RouteAccess;
use Illuminate\Support\Collection;

/**
 * Vector similarity search and context grounding for Aeon in DBEDC Guardian.
 * Calculates cosine similarity in pure PHP without external vector DB dependencies.
 */
class RagService
{
    public function __construct(private AiProvider $provider) {}

    /**
     * Retrieve the most relevant knowledge chunks for a user prompt.
     *
     * @return array<int, array{source_type: string, source_ref: string, title: string, chunk_text: string, score: float}>
     */
    public function search(string $query, int $limit = 6, ?User $actor = null, float $minScore = 0.40): array
    {
        // Fail closed: retrieval is always per user.
        if ($actor === null || trim($query) === '') {
            return [];
        }

        try {
            $embeddings = $this->provider->embed([$query]);
            $queryVector = $embeddings[0] ?? [];
            if (empty($queryVector)) {
                return [];
            }

            /** @var Collection<int, Embedding> $rows */
            $rows = Embedding::query()->select(['id', 'source_type', 'source_ref', 'title', 'chunk_text', 'vector'])->get();
            if ($rows->isEmpty()) {
                return [];
            }

            $scored = [];
            foreach ($rows as $row) {
                if (! $this->visibleTo($row, $actor)) {
                    continue;
                }

                $vector = $row->vector;
                if (! is_array($vector) || empty($vector)) {
                    continue;
                }

                $score = $this->cosineSimilarity($queryVector, $vector);
                if ($score >= $minScore) {
                    $scored[] = [
                        'source_type' => $row->source_type,
                        'source_ref' => $row->source_ref,
                        'title' => (string) ($row->title ?? ''),
                        'chunk_text' => (string) $row->chunk_text,
                        'score' => $score,
                    ];
                }
            }

            usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);

            return array_slice($scored, 0, $limit);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Retrieval filter. Only module chunks are indexed (schema chunks are never retrieved: the model
     * gets a per-user schema from AeonAccess instead), and a module chunk is visible only if the user may
     * open the module's page. Any other source type is excluded.
     */
    private function visibleTo(Embedding $row, User $actor): bool
    {
        if ($row->source_type !== 'module') {
            return false;
        }

        $module = config('modules.'.$row->source_ref);

        return is_array($module) && RouteAccess::canOpen((string) ($module['route'] ?? '/'), $actor);
    }

    /**
     * Compute cosine similarity between two numeric vectors.
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $count = min(count($a), count($b));
        if ($count === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $valA = (float) $a[$i];
            $valB = (float) $b[$i];
            $dot += $valA * $valB;
            $normA += $valA * $valA;
            $normB += $valB * $valB;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
