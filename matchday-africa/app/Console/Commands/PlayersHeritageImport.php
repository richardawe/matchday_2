<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Models\PlayerHeritage;
use App\Support\AfricanScope;
use App\Support\Armies;
use App\Support\PlayerNames;
use Illuminate\Console\Command;

/**
 * Imports heritage records from a sourced roster (JSON): one row per player with name, club, country,
 * country_code (FIFA, e.g. NGA), source_url, optional source_note and to_verify. Rows flagged to_verify,
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
                    'source_note' => trim((string) ($row['source_note'] ?? '')) ?: 'Imported from the sourced players roster.',
                    'status' => $status, 'confirmed_at' => $status === 'confirmed' ? now() : null,
                ]);
            }
            $created++;
            $this->line(sprintf('%-28s %-10s %s', $player->name, $status, $nation['demonym']));
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

    private function find(string $name, string $club): ?Player
    {
        $candidates = Player::with('team')->get(['id', 'name', 'team_id'])->filter(fn ($p) => PlayerNames::same($p->name, $name));
        if ($club !== '') {
            $candidates = $candidates->filter(fn ($p) => Armies::key((string) $p->team?->name) === Armies::key($club));
        }

        return $candidates->count() === 1 ? $candidates->first() : null;
    }
}
