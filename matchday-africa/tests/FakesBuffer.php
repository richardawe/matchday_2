<?php

namespace Tests;

use App\Services\BufferClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A stand-in for Buffer's GraphQL API: answers schema introspection with the shape Buffer
 * serves (Instagram's post type under metadata.instagram.type), and createPost with the
 * given responses in turn (the last one repeats).
 */
trait FakesBuffer
{
    protected function fakeBuffer(array ...$createPost): void
    {
        BufferClient::flushSchemaCache();
        $createPost = $createPost ?: [['post' => ['id' => 'p1']]];
        $i = 0;
        $named = fn (string $name, string $kind = 'INPUT_OBJECT') => ['name' => $name, 'kind' => $kind, 'ofType' => null];
        $required = fn (array $type) => ['name' => null, 'kind' => 'NON_NULL', 'ofType' => $type];
        $schema = [
            'CreatePostInput' => ['inputFields' => [
                ['name' => 'text', 'type' => $named('String', 'SCALAR')],
                ['name' => 'metadata', 'type' => $named('PostInputMetaData')],
            ]],
            'PostInputMetaData' => ['inputFields' => [['name' => 'instagram', 'type' => $named('InstagramPostMetadataInput')]]],
            'InstagramPostMetadataInput' => ['inputFields' => [
                ['name' => 'type', 'type' => $required($named('PostType', 'ENUM'))],
                ['name' => 'shouldShareToFeed', 'type' => $required($named('Boolean', 'SCALAR'))],
            ]],
            'PostType' => ['kind' => 'ENUM', 'enumValues' => [['name' => 'post'], ['name' => 'story'], ['name' => 'reel']]],
        ];

        Http::fake(['api.buffer.com/*' => function (Request $request) use ($schema, $createPost, &$i) {
            if (str_contains($request['query'], 'IntrospectType')) {
                $name = $request['variables']['name'];

                return Http::response(['data' => ['__type' => isset($schema[$name]) ? ['name' => $name, 'kind' => 'INPUT_OBJECT'] + $schema[$name] : null]]);
            }
            $answer = $createPost[min($i++, count($createPost) - 1)];

            return Http::response(['data' => ['createPost' => ['__typename' => isset($answer['message']) ? 'MutationError' : 'PostActionSuccess'] + $answer]]);
        }]);
    }

    /** createPost calls Buffer received, as their `input` variables. */
    protected function bufferPosts(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => str_contains($r->url(), 'api.buffer.com') && str_contains($r['query'], 'createPost'))
            ->map(fn (Request $r) => $r['variables']['input'])->values()->all();
    }
}
