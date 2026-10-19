<?php

namespace App\Services;

use App\Support\BufferUsage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Buffer's GraphQL API (api.buffer.com/graphql), ported from ngr.ltd's BufferClient, itself
 * ported from housedata.uk where it runs in production. What that history taught:
 *
 *   - Personal API key as a Bearer token; everything hangs off organizations → channels.
 *   - Send values as GraphQL variables, never spliced into the query string.
 *   - `assets` is non-null: [] for text, [{image: {url}}] or [{video: {url}}] for media.
 *   - Instagram's post type lives at metadata.instagram.type and is required; it is read from
 *     the live schema (which has changed twice) rather than guessed, and cached for a day.
 *   - createPost answers 200 with a MutationError for refusals; that message is the diagnosis.
 *
 * Every request is counted (BufferUsage). Methods return arrays and never throw, so callers
 * can record Buffer's own words on the post.
 */
class BufferClient
{
    /** Values for required platform metadata fields, only where the right value is unambiguous. */
    public const METADATA_DEFAULTS = ['shouldShareToFeed' => true];

    private const CHANNEL_BASE_FIELDS = ['id', 'name', 'service'];

    private const CHANNEL_HEALTH_PATTERN = '/disconnect|locked|expire|reconnect|paused|suspended/i';

    private const SCHEMA_TTL_SECONDS = 86400;

    /** @var array<string, array> */
    private static array $typeCache = [];

    public static function flushSchemaCache(): void
    {
        foreach (array_keys(self::$typeCache) as $name) {
            Cache::forget("buffer:schema:{$name}");
        }
        self::$typeCache = [];
    }

    /** @return array{data?: array, error?: string, raw?: mixed} */
    public function query(string $query, array $variables = []): array
    {
        if (!config('buffer.key')) {
            return ['error' => 'BUFFER_API_KEY is not set'];
        }

        // Counted before sending: a request that times out still counts on Buffer's side.
        BufferUsage::record();

        try {
            $response = Http::withToken(config('buffer.key'))->acceptJson()->timeout(30)
                ->post(config('buffer.endpoint'), ['query' => $query, 'variables' => $variables ?: (object) []]);
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        $data = $response->json() ?? [];
        $errors = is_array($data['errors'] ?? null) ? $data['errors'] : [];
        $message = $errors ? implode('; ', array_map(fn ($e) => (string) ($e['message'] ?? 'Unknown error'), $errors)) : null;

        if (!$response->successful() || $message !== null) {
            return ['error' => $message ?? "Buffer HTTP {$response->status()}", 'raw' => $data];
        }

        return ['data' => $data['data'] ?? [], 'raw' => $data];
    }

    /**
     * Introspect a named type. NON_NULL is kept as `required`: GraphQL rejects the whole
     * mutation for a missing `Type!` field.
     */
    public function introspectType(string $typeName): array
    {
        if (isset(self::$typeCache[$typeName])) {
            return self::$typeCache[$typeName];
        }
        if (is_array($cached = Cache::get("buffer:schema:{$typeName}"))) {
            return self::$typeCache[$typeName] = $cached;
        }

        $r = $this->query(<<<'GQL'
            query IntrospectType($name: String!) {
              __type(name: $name) {
                name
                kind
                fields { name type { name kind ofType { name kind ofType { name kind } } } }
                inputFields { name type { name kind ofType { name kind ofType { name kind } } } }
                enumValues { name }
              }
            }
            GQL, ['name' => $typeName]);

        if (isset($r['error'])) {
            return ['error' => $r['error']];
        }
        if (!$type = $r['data']['__type'] ?? null) {
            return ['error' => "Type {$typeName} not found in Buffer schema"];
        }

        $out = [
            'name' => $type['name'],
            'kind' => $type['kind'],
            'fields' => array_map(fn (array $f) => ['name' => $f['name']] + self::unwrap($f['type'] ?? null), $type['fields'] ?? $type['inputFields'] ?? []),
            'enumValues' => array_map(fn ($v) => $v['name'], $type['enumValues'] ?? []),
        ];
        Cache::put("buffer:schema:{$typeName}", $out, self::SCHEMA_TTL_SECONDS);

        return self::$typeCache[$typeName] = $out;
    }

    /**
     * The organizations the key can see. Buffer moved this under `account` (October 2026, the
     * top-level field started demanding an input), so that comes first; the old shape stays as a
     * fallback for the schema housedata.uk and ngr.ltd were built on.
     *
     * @return array{organizations?: array, error?: string}
     */
    public function listOrganizations(): array
    {
        $r = $this->query('query GetOrganizations { account { organizations { id name } } }');
        if (!isset($r['error'])) {
            return ['organizations' => $r['data']['account']['organizations'] ?? []];
        }
        $fallback = $this->query('query GetOrganizations { organizations { id name } }');

        return isset($fallback['error']) ? ['error' => "{$r['error']} / {$fallback['error']}"] : ['organizations' => $fallback['data']['organizations'] ?? []];
    }

    /**
     * Channels on an organization, with any health fields the schema offers (so a disconnected
     * X account shows up instead of posts silently failing). Falls back to the base fields.
     *
     * @return array{channels?: array, error?: string}
     */
    public function listChannels(string $organizationId): array
    {
        $type = $this->introspectType('Channel');
        $health = isset($type['error']) ? [] : collect($type['fields'])
            ->filter(fn ($f) => in_array($f['kind'], ['SCALAR', 'ENUM'], true) && preg_match(self::CHANNEL_HEALTH_PATTERN, $f['name']))
            ->pluck('name')->diff(self::CHANNEL_BASE_FIELDS)->values()->all();

        $run = fn (array $fields) => $this->query(
            'query GetChannels($organizationId: OrganizationId!) { channels(input: { organizationId: $organizationId }) { '.implode(' ', $fields).' } }',
            ['organizationId' => $organizationId]);

        $r = $run(array_merge(self::CHANNEL_BASE_FIELDS, $health));
        if (isset($r['error']) && $health) {
            $r = $run(self::CHANNEL_BASE_FIELDS);
        }

        return isset($r['error']) ? ['error' => $r['error']] : ['channels' => $r['data']['channels'] ?? []];
    }

    /**
     * Create one post. `$mode` is shareNow, addToQueue or customScheduled (with `$dueAt`).
     *
     * @return array{ok?: bool, id?: ?string, error?: string, request?: array}
     */
    public function createPost(string $channelId, string $platform, string $text, ?string $image = null, ?string $video = null, string $mode = 'shareNow', ?string $dueAt = null): array
    {
        $input = [
            'channelId' => $channelId,
            'text' => $text,
            'schedulingType' => 'automatic',
            'mode' => $mode,
            'assets' => $video ? [['video' => ['url' => $video]]] : ($image ? [['image' => ['url' => $image]]] : []),
        ];
        if ($dueAt) {
            $input['dueAt'] = $dueAt;
        }

        if ($platform === 'instagram') {
            $resolved = $this->resolvePostMetadata('instagram', $video ? 'reel' : 'post');
            if ($resolved['metadata'] === null) {
                return ['error' => "Could not build Instagram metadata from Buffer's schema: {$resolved['reason']}", 'request' => $input];
            }
            if ($resolved['missing']) {
                return ['error' => 'Buffer requires Instagram metadata we have no value for: '.implode(', ', $resolved['missing']), 'request' => $input];
            }
            $input['metadata'] = $resolved['metadata'];
        }

        $r = $this->query(<<<'GQL'
            mutation CreatePost($input: CreatePostInput!) {
              createPost(input: $input) {
                __typename
                ... on PostActionSuccess { post { id } }
                ... on MutationError { message }
              }
            }
            GQL, ['input' => $input]);

        if (isset($r['error'])) {
            return ['error' => $r['error'], 'request' => $input];
        }
        $result = $r['data']['createPost'] ?? null;
        if (!$result) {
            return ['error' => 'No createPost response from Buffer', 'request' => $input];
        }
        if (($result['__typename'] ?? null) === 'MutationError' || isset($result['message']) || !data_get($result, 'post.id')) {
            return ['error' => $result['message'] ?? 'Unknown Buffer response', 'request' => $input];
        }

        return ['ok' => true, 'id' => data_get($result, 'post.id'), 'request' => $input];
    }

    /** Walk CreatePostInput → metadata → <platform> input → PostType in the live schema. */
    public function resolvePostMetadata(string $platform, string $want): array
    {
        $fail = fn (string $reason) => ['metadata' => null, 'missing' => [], 'reason' => $reason];

        $create = $this->introspectType('CreatePostInput');
        $metaType = data_get(collect($create['fields'] ?? [])->firstWhere('name', 'metadata'), 'type');
        if (isset($create['error']) || !$metaType) {
            return $fail($create['error'] ?? 'CreatePostInput has no metadata field');
        }
        $meta = $this->introspectType($metaType);
        $platformType = data_get(collect($meta['fields'] ?? [])->firstWhere('name', $platform), 'type');
        if (isset($meta['error']) || !$platformType) {
            return $fail($meta['error'] ?? "{$metaType} has no {$platform} field");
        }
        $platformInput = $this->introspectType($platformType);
        $postTypeName = data_get(collect($platformInput['fields'] ?? [])->firstWhere('name', 'type'), 'type');
        if (isset($platformInput['error']) || !$postTypeName) {
            return $fail($platformInput['error'] ?? "{$platform} metadata input has no type field");
        }
        $postType = $this->introspectType($postTypeName);
        if (isset($postType['error'])) {
            return $fail($postType['error']);
        }

        return self::buildPostMetadata(
            array_column($create['fields'], 'name'),
            array_column($meta['fields'], 'name'),
            $platformInput['fields'],
            $postType['enumValues'] ?? [],
            $platform,
            $want,
        );
    }

    /**
     * Decide `metadata` from the schema's shape. Pure, so it is testable without the API.
     * Any missing link means the field is omitted rather than sent with a guessed value.
     */
    public static function buildPostMetadata(array $createPostFields, array $postMetaFields, array $platformFields, array $postTypeValues, string $platform, string $want): array
    {
        $no = fn (string $reason) => ['metadata' => null, 'missing' => [], 'reason' => $reason];

        if (!in_array('metadata', $createPostFields, true)) {
            return $no('CreatePostInput has no metadata field');
        }
        if (!in_array($platform, $postMetaFields, true)) {
            return $no("PostInputMetaData has no {$platform} field");
        }
        $fields = [];
        foreach ($platformFields as $f) {
            $f = is_string($f) ? ['name' => $f] : $f;
            $fields[$f['name']] = (bool) ($f['required'] ?? false);
        }
        if (!array_key_exists('type', $fields)) {
            return $no("{$platform} metadata input has no type field");
        }
        // Buffer has shipped both "post" and "POST".
        $value = collect($postTypeValues)->first(fn ($v) => strtolower((string) $v) === strtolower($want));
        if ($value === null) {
            return $no("PostType has no value matching \"{$want}\"");
        }

        $payload = ['type' => $value] + array_intersect_key(self::METADATA_DEFAULTS, $fields);
        $missing = array_keys(array_filter($fields, fn ($required, $name) => $required && !array_key_exists($name, $payload), ARRAY_FILTER_USE_BOTH));

        return ['metadata' => [$platform => $payload], 'missing' => array_values($missing), 'reason' => null];
    }

    /** @return array{type: ?string, kind: ?string, required: bool} */
    private static function unwrap(?array $type): array
    {
        $required = ($type['kind'] ?? null) === 'NON_NULL';
        while ($type && empty($type['name']) && !empty($type['ofType'])) {
            $type = $type['ofType'];
        }

        return ['type' => $type['name'] ?? null, 'kind' => $type['kind'] ?? null, 'required' => $required];
    }
}
