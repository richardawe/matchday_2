<?php

namespace App\Services;

use App\Design\CopyRules;
use App\Models\BreakingNews;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Writes breaking news items for the agent API and the admin page. Headlines up to 90 characters,
 * an optional body up to 280, an optional link (an article, match page, video or the source) and an
 * optional https image that must carry its licence credit. Items go live at once and expire after
 * expires_in_hours (default 6, at most 48). The copy follows the design language: emoji arrows and
 * sirens are stripped and banned phrasing is refused.
 */
class BreakingNewsDesk
{
    public const DEFAULT_HOURS = 6;

    public const MAX_HOURS = 48;

    public function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'headline' => [$req, 'string', 'max:90'],
            'body' => ['sometimes', 'nullable', 'string', 'max:280'],
            'link_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'image_url' => ['sometimes', 'nullable', 'url:https', 'max:2048'],
            // On an edit, a new image without a credit keeps the stored credit (checked in update()).
            'image_credit' => $partial ? ['sometimes', 'nullable', 'string', 'max:200'] : ['nullable', 'string', 'max:200', 'required_with:image_url'],
            'expires_in_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_HOURS],
        ];
    }

    public function create(array $input, User $by, string $source): BreakingNews
    {
        $data = $this->validated($input);
        $item = BreakingNews::create($this->fields($data) + [
            'source' => $source, 'created_by' => $by->id, 'updated_by' => $by->id,
            'expires_at' => now()->addHours((int) ($data['expires_in_hours'] ?? self::DEFAULT_HOURS)),
        ]);
        BreakingNews::forget();

        return $item->fresh(['author', 'editor', 'ender']);
    }

    public function update(BreakingNews $item, array $input, User $by): BreakingNews
    {
        if ($item->ended_at) {
            throw ValidationException::withMessages(['id' => 'This item has ended; post a new one instead.']);
        }
        $data = $this->validated($input, true);
        if (array_key_exists('image_url', $data) && $data['image_url'] && !array_key_exists('image_credit', $data) && !$item->image_credit) {
            throw ValidationException::withMessages(['image_credit' => 'An image needs its licence credit.']);
        }
        $fields = $this->fields($data);
        if (array_key_exists('image_url', $data) && !$data['image_url']) {
            $fields['image_credit'] = null;
        }
        if (!empty($data['expires_in_hours'])) {
            $fields['expires_at'] = now()->addHours((int) $data['expires_in_hours']);
        }
        $item->update($fields + ['updated_by' => $by->id]);
        BreakingNews::forget();

        return $item->fresh(['author', 'editor', 'ender']);
    }

    public function end(BreakingNews $item, User $by): BreakingNews
    {
        if (!$item->ended_at) {
            $item->update(['ended_at' => now(), 'ended_by' => $by->id]);
            BreakingNews::forget();
        }

        return $item->fresh(['author', 'editor', 'ender']);
    }

    private function validated(array $input, bool $partial = false): array
    {
        foreach (['headline', 'body', 'image_credit'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $input[$key] = CopyRules::clean(trim((string) preg_replace('/\s+/u', ' ', $input[$key])));
            }
        }
        $data = Validator::make($input, $this->rules($partial))->validate();
        foreach (['headline', 'body'] as $key) {
            if (!empty($data[$key]) && ($bad = CopyRules::violations($data[$key]))) {
                throw ValidationException::withMessages([$key => 'Banned copy for the design language: '.implode(', ', $bad).'.']);
            }
        }

        return $data;
    }

    private function fields(array $data): array
    {
        return collect($data)->only(['headline', 'body', 'link_url', 'image_url', 'image_credit'])
            ->map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v)->all();
    }
}
