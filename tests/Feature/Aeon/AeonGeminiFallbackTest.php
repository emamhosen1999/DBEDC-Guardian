<?php

namespace Tests\Feature\Aeon;

use App\Services\Aeon\Providers\GeminiProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Aeon's fallback chain pointed at retired models (gemini-2.0-flash, 1.5-flash, 1.5-pro: all 404), so a busy primary
 * meant no answer at all. The chain must move on to a current model.
 */
class AeonGeminiFallbackTest extends TestCase
{
    public function test_a_busy_primary_falls_back_to_the_next_current_model(): void
    {
        config([
            'aeon.providers.gemini.api_key' => 'test-key',
            'aeon.providers.gemini.endpoint' => 'https://gemini.test/v1beta',
            'aeon.providers.gemini.retries' => 0,
        ]);
        Http::fake([
            'gemini.test/v1beta/models/gemini-2.5-flash:generateContent' => Http::response(['error' => ['message' => 'overloaded']], 503),
            'gemini.test/v1beta/models/gemini-2.5-flash-lite:generateContent' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'answer']]]]]]),
        ]);

        $result = (new GeminiProvider)->chat([['role' => 'user', 'content' => 'hello']]);

        $this->assertSame('answer', $result->content);
        $this->assertSame('gemini-2.5-flash-lite', $result->model);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'key='));
    }

    public function test_no_retired_model_is_configured(): void
    {
        $chain = array_merge([config('aeon.providers.gemini.model')], explode(',', (string) config('aeon.providers.gemini.fallback_models')));

        foreach ($chain as $model) {
            $this->assertDoesNotMatchRegularExpression('/^gemini-(1\.0|1\.5|2\.0)/', trim($model));
        }
        $this->assertSame('gemini-embedding-001', config('aeon.providers.gemini.embed_model'));
    }
}
