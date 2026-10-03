<?php

declare(strict_types=1);

namespace App\Services\Aeon;

use App\Contracts\Ai\AiProvider;
use App\Models\Aeon\Embedding;
use Illuminate\Support\Facades\Log;

/**
 * Builds and updates the vector knowledge index for DBEDC Guardian.
 * Embeds registered modules and curated documentation (never live data or schema).
 */
class IndexingService
{
    public function __construct(
        private AiProvider $provider
    ) {}

    /**
     * Run the knowledge base indexing process.
     *
     * @return array{sources: int, indexed: int, skipped: int}
     */
    public function index(bool $fresh = false): array
    {
        if ($fresh) {
            Embedding::truncate();
        }

        $chunks = $this->gatherChunks();
        $indexed = 0;
        $skipped = 0;

        foreach ($chunks as $chunk) {
            $checksum = sha1($chunk['text']);
            $existing = Embedding::where('source_type', $chunk['type'])
                ->where('source_ref', $chunk['ref'])
                ->first();

            if ($existing && $existing->checksum === $checksum) {
                $skipped++;

                continue;
            }

            $vectors = $this->provider->embed([$chunk['text']]);
            $vector = $vectors[0] ?? [];

            if (empty($vector)) {
                Log::warning('Aeon Indexing failed to embed chunk', ['ref' => $chunk['ref']]);

                continue;
            }

            Embedding::updateOrCreate(
                [
                    'source_type' => $chunk['type'],
                    'source_ref' => $chunk['ref'],
                ],
                [
                    'title' => $chunk['title'],
                    'chunk_text' => $chunk['text'],
                    'vector' => $vector,
                    'dims' => count($vector),
                    'checksum' => $checksum,
                ]
            );

            $indexed++;
        }

        return [
            'sources' => count($chunks),
            'indexed' => $indexed,
            'skipped' => $skipped,
        ];
    }

    /**
     * Gather knowledge chunks (module registry). Chunks are shared across users, so nothing user-specific or data-bearing may be indexed here.
     *
     * @return array<int, array{type: string, ref: string, title: string, text: string}>
     */
    private function gatherChunks(): array
    {
        $chunks = [];

        // 1. Registered Guardian Modules
        $modules = (array) config('modules', []);
        foreach ($modules as $code => $mod) {
            $name = (string) ($mod['name'] ?? $code);
            $desc = (string) ($mod['description'] ?? '');
            $route = (string) ($mod['route'] ?? '');
            $keywords = implode(', ', (array) ($mod['keywords'] ?? []));

            $text = "Module: {$name}\nRoute: {$route}\nDescription: {$desc}\nKeywords: {$keywords}";
            $chunks[] = [
                'type' => 'module',
                'ref' => (string) $code,
                'title' => $name,
                'text' => $text,
            ];
        }

        // The live database schema is deliberately NOT indexed: the embedding store is shared by
        // every user, and the schema the model sees is built per user by AeonAccess::promptSchema().

        return $chunks;
    }
}
