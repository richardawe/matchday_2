<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Models\PlayerHeritage;
use App\Support\AfricanScope;
use App\Support\Armies;
use App\Support\PlayerNames;
use Illuminate\Console\Command;

/**
 * Imports heritage records from a sourced roster (JSON, made from the owner's players_roster.md): one row
 * per player and country with name, club, country, country_code (FIFA, e.g. NGA), kind (descent, or
 * international for a sourced senior African cap), source_url, optional source_note and to_verify. Rows flagged to_verify,
 * or without a source, go in as pending; the rest as confirmed (the roster's sources were checked). An
 * existing row an editor has already confirmed or rejected is never changed. Nothing is inferred: a
 * player who can't be matched by name and club is reported and skipped.
 *
 * [{"name": "…", "club": "…", "country": "Nigeria", "country_code": "NGA", "source_url": "https://…", "to_verify": false}]
 */
class PlayersHeritageImport extends Command
{
    protected $signature = 'players:heritage-import {file=database/seeders/data/african_heritage_epl.json} {--dry-run}';

    protected $description = 'Import sourced African heritage records (to_verify rows as pending) from a JSON roster';

    public function handle(): int
    {
        if (!AfricanScope::heritageReady()) {
            $this->error('Run the migrations first.');

            return self::FAILURE;
        }
        $path = str_starts_with($this->argument('file'), '/') ? $this->argument('file') : base_path($this->argument('file'));
        $rows = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($rows)) {
            $this->error("No JSON roster at {$path}.");

            return self::FAILURE;
        }
        [$created, $kept, $missing, $bad] = [0, 0, [], []];
        foreach ($rows as $i => $row) {
            $code = strtoupper(trim((string) ($row['country_code'] ?? '')));
            $nation = AfricanScope::nation($code);
            if (empty($row['name']) || !$nation) {
                $bad[] = ($row['name'] ?? "row {$i}").' (country code '.($code ?: 'missing').')';
                continue;
            }
            $player = $this->find((string) $row['name'], (string) ($row['club'] ?? ''));
            if (!$player) {
                $missing[] = $row['name'].(!empty($row['club']) ? " ({$row['club']})" : '');
                continue;
            }
            $source = trim((string) ($row['source_url'] ?? ''));
            $status = !empty($row['to_verify']) || $source === '' ? 'pending' : 'confirmed';
            $existing = PlayerHeritage::where(['player_id' => $player->id, 'country_code' => $code])->first();
            if ($existing && ($existing->confirmed_by || $existing->status === 'rejected')) {
                $kept++;
                continue; // An editor's decision stands.
            }
            if (!$this->option('dry-run')) {
                PlayerHeritage::updateOrCreate(['player_id' => $player->id, 'country_code' => $code], [
                    'country' => $row['country'] ?? $nation['name'], 'source_url' => $source ?: null,
                    'kind' => in_array($row['kind'] ?? 'descent', PlayerHeritage::KINDS, true) ? ($row['kind'] ?? 'descent') : 'descent',
                    'source_note' => trim((string) ($row['source_note'] ?? '')) ?: 'Imported from the sourced players roster.',
                    'status' => $status, 'confirmed_at' => $status === 'confirmed' ? now() : null,
                ]);
            }
            $created++;
            $this->line(sprintf('%-28s %-10s %-14s %s', $player->name, $status, $row['kind'] ?? 'descent', $nation['name']));
        }
        $this->info("Heritage: {$created} imported".($this->option('dry-run') ? ' (dry run)' : '').", {$kept} left as editors set them.");
        foreach ($missing as $name) {
            $this->warn("Not matched to a player in our squads: {$name}");
        }
        foreach ($bad as $name) {
            $this->warn("Skipped: {$name}");
        }

        return self::SUCCESS;
    }

    /**
     * The one player with this name at this club; failing that (a transfer the squads haven't caught up
     * with), the one player with this name anywhere, reported. Two or more of the same name: none.
     */
    private function find(string $name, string $club): ?Player
    {
        $this->players ??= Player::with('team')->get(['id', 'name', 'team_id']);
        $named = $this->players->filter(fn ($p) => PlayerNames::same($p->name, $name))->values();
        $atClub = $club === '' ? $named : $named->filter(fn ($p) => Armies::key((string) $p->team?->name) === Armies::key($club))->values();
        if ($atClub->count() === 1) {
            return $atClub->first();
        }
        // Across clubs the loose name rule ("Nicolas" = "Nicolas Jackson") is too loose: need two or more names
        // with the same surname and first initial, and only one such player in the whole database.
        $strict = $named->filter(fn ($p) => self::strictlySame($p->name, $name))->values();
        if ($atClub->isEmpty() && $strict->count() === 1) {
            $this->warn("{$name}: listed at {$club}, matched to the only {$strict->first()->name} we have (at ".($strict->first()->team?->name ?? 'no club').').');

            return $strict->first();
        }

        return null;
    }

    private ?\Illuminate\Support\Collection $players = null;

    private static function strictlySame(string $a, string $b): bool
    {
        [$a, $b] = [explode(' ', PlayerNames::normalize($a)), explode(' ', PlayerNames::normalize($b))];

        return count($a) > 1 && count($b) > 1 && end($a) === end($b) && $a[0][0] === $b[0][0];
    }
}
