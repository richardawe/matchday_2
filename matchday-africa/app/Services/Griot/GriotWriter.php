<?php

namespace App\Services\Griot;

use App\Models\FootballMatch;
use App\Models\GriotPost;
use App\Support\Armies;

/**
 * The small writer model's input and fact check. Mirrors griot-ml/writer_data.py (writer_input, prompt,
 * check): the model is trained on exactly this input, and a caption that uses a number or a name the
 * facts do not contain, or runs too long, is rejected so the Griot templates speak instead.
 */
class GriotWriter
{
    private const COMMON = ['The', 'A', 'An', 'And', 'But', 'Still', 'Full', 'Half', 'Time', 'Half-time', 'Full-time', 'No', 'One', 'Two', 'Three',
        'VAR', 'Hear', 'Let', 'Listen', 'What', 'So', 'Now', 'Then', 'Yet', 'Even', 'Again', 'All', 'It', 'He', 'His', 'They',
        'Their', 'I', 'Not', 'Wood', 'Denied', 'Off', 'On', 'In', 'At', 'From', 'For', 'To', 'Of', 'With', 'By', 'As', 'This', 'That'];

    public function input(GriotPost $post, FootballMatch $match): array
    {
        $club = fn ($team) => trim(preg_replace('/\s+(FC|AFC)$/i', '', (string) $team?->name));
        $home = $club($match->homeTeam);
        $away = $club($match->awayTeam);
        $armies = ['home' => Armies::for($match->homeTeam)['army'] ?? $home, 'away' => Armies::for($match->awayTeam)['army'] ?? $away];
        $facts = array_filter($post->facts ?? [], fn ($v) => !in_array($v, [null, '', false, []], true));
        $input = [
            'kind' => $post->kind,
            'army' => $post->side ? $armies[$post->side] : '',
            'opp_army' => $post->side ? $armies[$post->side === 'home' ? 'away' : 'home'] : '',
            'home' => $home, 'away' => $away,
            'clock' => $post->clock ?? '',
            'score' => $post->home_score.'–'.$post->away_score,
            'facts' => $facts,
        ];

        return $input;
    }

    /** Same text as Python's prompt(): keys sorted at every level, non-ASCII kept. */
    public function prompt(array $input): string
    {
        return 'Write the Griot\'s caption as JSON {"headline","line"} for this moment:'."\n".$this->json($input);
    }

    /** @return string[] reasons the caption fails; empty when it passes */
    public function check(array $input, mixed $out, int $maxHeadline = 8, int $maxLine = 26): array
    {
        if (!is_array($out) || !is_string($out['headline'] ?? null) || !is_string($out['line'] ?? null)) {
            return ['not a {headline, line} object'];
        }
        $problems = [];
        [$head, $line] = [trim($out['headline']), trim($out['line'])];
        if ($head === '' || $line === '') {
            $problems[] = 'empty';
        }
        if (count(preg_split('/\s+/u', $head)) > $maxHeadline || count(preg_split('/\s+/u', $line)) > $maxLine) {
            $problems[] = 'too long';
        }
        $source = $this->json($input);
        preg_match_all('/\d+/', $source, $m);
        $numbers = array_flip($m[0]);
        preg_match_all('/\d+/', $head.' '.$line, $m);
        foreach ($m[0] as $n) {
            if (!isset($numbers[$n])) {
                $problems[] = "number {$n} not in the facts";
            }
        }
        preg_match_all("/[A-Za-zÀ-ÿ'’-]+/u", $source, $m);
        $words = array_flip($m[0]);
        $common = array_flip(self::COMMON);
        foreach ([$head, $line] as $text) {
            preg_match_all("/[A-Za-zÀ-ÿ'’-]+|[.!?:]/u", $text, $m);
            $tokens = $m[0];
            foreach ($tokens as $i => $w) {
                if (!preg_match('/^\p{Lu}/u', $w) || isset($words[$w]) || isset($common[$w])) {
                    continue;
                }
                $starts = $i === 0 || str_contains('.!?:', $tokens[$i - 1]);
                $follows = $tokens[$i + 1] ?? '';
                if ($starts && !preg_match('/^\p{Lu}/u', $follows)) {
                    continue;
                }
                $problems[] = "name {$w} not in the facts";
            }
        }

        return $problems;
    }

    /** Parse the model's reply; null unless it is a caption that passes the check. */
    public function accept(array $input, string $reply): ?array
    {
        $out = json_decode($reply, true);

        return $this->check($input, $out) === [] ? ['headline' => trim($out['headline']), 'line' => trim($out['line'])] : null;
    }

    private function json(array $value): string
    {
        $sort = function ($v) use (&$sort) {
            if (!is_array($v)) {
                return $v;
            }
            if (!array_is_list($v)) {
                ksort($v);
            }

            return array_map($sort, $v);
        };

        // Python's json.dumps(ensure_ascii=False, sort_keys=True) separators: ", " and ": ".
        $json = json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        return $this->spaced($json);
    }

    /** Add Python's spaces after , and : outside strings. */
    private function spaced(string $json): string
    {
        $out = '';
        $inString = false;
        $escape = false;
        foreach (mb_str_split($json) as $ch) {
            $out .= $ch;
            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === '"') {
                    $inString = false;
                }
            } elseif ($ch === '"') {
                $inString = true;
            } elseif ($ch === ',' || $ch === ':') {
                $out .= ' ';
            }
        }

        return $out;
    }
}
