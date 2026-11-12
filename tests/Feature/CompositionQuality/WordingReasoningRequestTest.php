<?php

use App\Exceptions\ResumeEmptyOutputException;
use App\Support\ResumeVariant\Providers\OllamaResumeWordingClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * What the wording client asks of Ollama. qwen3.8:27b reports one capability, `thinking`, with no levels, so "low" is the
 * same as the default (on); only "none" changes anything. The retry after an unusable reply therefore asks for none.
 */
function rrReply(string $content, string $finish = 'stop'): array
{
    return ['model' => 'qwen3.8:27b', 'choices' => [['finish_reason' => $finish, 'message' => ['content' => $content, 'reasoning' => str_repeat('r', 400)]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30]];
}

function rrEfforts(): array
{
    return Http::recorded()->map(fn (array $pair) => $pair[0]->data()['reasoning_effort'] ?? '(none sent)')->all();
}

it('sends no reasoning field on the first request, then asks for thinking off after an unusable reply', function () {
    Http::fakeSequence()->push(rrReply('', 'length'))->push(rrReply('{"summary":"x"}'))->push(rrReply('{"summary":"y"}'));
    $client = new OllamaResumeWordingClient('http://ollama.test', 'qwen3.8:27b', 30);

    expect(fn () => $client->generate('s', 'u', ['type' => 'object']))->toThrow(ResumeEmptyOutputException::class);
    expect($client->generate('s', 'u', ['type' => 'object'])->structuredContent)->toBe(['summary' => 'x']);
    $client->generate('s', 'u', ['type' => 'object']);

    // The retry is the only request that asks; once a reply is usable the next request goes back to the default.
    expect(rrEfforts())->toBe(['(none sent)', 'none', '(none sent)']);
});

it('sends a configured effort on every request, and a configured retry effort after an unusable reply', function () {
    Http::fakeSequence()->push(rrReply('', 'length'))->push(rrReply('{"summary":"x"}'));
    $client = new OllamaResumeWordingClient('http://ollama.test', 'qwen3.8:27b', 30, reasoningEffort: 'none', retryReasoningEffort: 'low');

    expect(fn () => $client->generate('s', 'u', ['type' => 'object']))->toThrow(ResumeEmptyOutputException::class);
    $client->generate('s', 'u', ['type' => 'object']);

    expect(rrEfforts())->toBe(['none', 'low']);
});

it('records how much of a reply was thinking', function () {
    Http::fake(['*' => Http::response(rrReply('{"summary":"x"}'))]);
    Log::spy();

    (new OllamaResumeWordingClient('http://ollama.test', 'qwen3.8:27b', 30))->generate('s', 'u', ['type' => 'object']);

    Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $ctx = []) => str_contains($m, 'Ollama usage') && ($ctx['reasoning_field_chars'] ?? null) === 400)->once();
});
