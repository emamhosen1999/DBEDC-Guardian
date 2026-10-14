<?php

namespace Tests\Feature\Aeon;

use App\Contracts\Ai\AiChatResult;
use App\Contracts\Ai\AiProvider;
use App\Models\Aeon\Embedding;
use App\Services\Aeon\IndexingService;
use App\Services\Aeon\Providers\GeminiProvider;
use App\Services\Aeon\RagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Feature\Access\Concerns\BuildsDepartmentAdminWorld;
use Tests\TestCase;

/**
 * Production's knowledge base stayed empty because text-embedding-004 was retired and every embed call came back
 * 404 as a silent empty vector. These pin the embedding contract: current model, retrieval task types, requested
 * size, header-only auth, and a logged failure.
 */
class AeonEmbeddingTest extends TestCase
{
    use BuildsDepartmentAdminWorld;
    use RefreshDatabase;

    private function gemini(): GeminiProvider
    {
        config(['aeon.providers.gemini.api_key' => 'test-key', 'aeon.providers.gemini.endpoint' => 'https://gemini.test/v1beta']);

        return new GeminiProvider;
    }

    /** A provider that records the options of every embed call and returns $vector. */
    private function recorder(array $vector): AiProvider
    {
        return new class($vector) implements AiProvider
        {
            public array $calls = [];

            public function __construct(private array $vector) {}

            public function chat(array $messages, array $tools = [], array $options = []): AiChatResult
            {
                return new AiChatResult(content: '');
            }

            public function embed(array $texts, array $options = []): array
            {
                $this->calls[] = $options;

                return array_fill(0, count($texts), $this->vector);
            }

            public function isAvailable(): bool
            {
                return true;
            }
        };
    }

    public function test_gemini_embeds_with_the_current_model_task_type_and_size_and_keeps_the_key_out_of_the_url(): void
    {
        Http::fake(['gemini.test/*' => Http::response(['embedding' => ['values' => [0.1, 0.2]]])]);

        $this->assertSame([[0.1, 0.2]], $this->gemini()->embed(['where is the roster'], ['task' => 'query']));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/models/gemini-embedding-001:embedContent')
            && ! str_contains($r->url(), 'key=')
            && $r->hasHeader('x-goog-api-key', 'test-key')
            && $r['taskType'] === 'RETRIEVAL_QUERY'
            && $r['outputDimensionality'] === 768);
    }

    public function test_a_failed_embed_call_is_logged_with_its_status(): void
    {
        Http::fake(['gemini.test/*' => Http::response(['error' => ['message' => 'models/x is not found for API version v1beta']], 404)]);
        Log::spy();

        $this->assertSame([[]], $this->gemini()->embed(['text'], ['task' => 'document']));

        Log::shouldHaveReceived('warning')->with('Aeon Gemini embed call failed', Mockery::on(
            fn (array $context) => $context['status'] === 404 && str_contains($context['error'], 'not found')
        ));
    }

    public function test_indexing_embeds_documents_and_search_embeds_the_query(): void
    {
        $this->buildWorld();
        $provider = $this->recorder([1.0, 0.0]);

        (new IndexingService($provider))->index();
        (new RagService($provider))->search('anything', 5, $this->e1);

        $tasks = array_column($provider->calls, 'task');
        $this->assertNotEmpty($tasks);
        $this->assertSame('query', array_pop($tasks));
        $this->assertSame(['document'], array_values(array_unique($tasks)));
    }

    public function test_search_skips_vectors_of_another_size(): void
    {
        $this->buildWorld();
        $rag = new RagService($this->recorder([1.0, 0.0, 0.0]));
        $row = Embedding::create(['source_type' => 'module', 'source_ref' => 'dashboard', 'title' => 'Dashboard', 'chunk_text' => 'dashboard chunk', 'vector' => [1.0, 0.0], 'dims' => 2, 'checksum' => sha1('a')]);

        $this->assertSame([], $rag->search('anything', 5, $this->e1));

        $row->update(['vector' => [1.0, 0.0, 0.0], 'dims' => 3]);
        $this->assertSame(['dashboard'], array_column($rag->search('anything', 5, $this->e1), 'source_ref'));
    }
}
