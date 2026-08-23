<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $indexes = collect(Schema::getIndexes('prediction_set_matches'));

        if ($name = $this->uniqueName($indexes, ['prediction_set_id', 'match_id'])) {
            Schema::table('prediction_set_matches', fn (Blueprint $table) =>
                $table->dropUnique($name)
            );
        }

        $indexes = collect(Schema::getIndexes('prediction_set_matches'));
        if (!$this->hasUnique($indexes, ['prediction_set_id', 'match_id', 'prediction_type'])) {
            Schema::table('prediction_set_matches', fn (Blueprint $table) =>
                $table->unique(['prediction_set_id', 'match_id', 'prediction_type'], 'prediction_round_match_type_unique')
            );
        }
    }

    public function down(): void
    {
        $indexes = collect(Schema::getIndexes('prediction_set_matches'));

        if ($name = $this->uniqueName($indexes, ['prediction_set_id', 'match_id', 'prediction_type'])) {
            Schema::table('prediction_set_matches', fn (Blueprint $table) =>
                $table->dropUnique($name)
            );
        }

        $indexes = collect(Schema::getIndexes('prediction_set_matches'));
        if (!$this->hasUnique($indexes, ['prediction_set_id', 'match_id'])) {
            Schema::table('prediction_set_matches', fn (Blueprint $table) =>
                $table->unique(['prediction_set_id', 'match_id'])
            );
        }
    }

    private function hasUnique($indexes, array $columns): bool
    {
        return $indexes->contains(fn (array $index) =>
            ($index['unique'] ?? false) && array_values($index['columns'] ?? []) === $columns
        );
    }

    private function uniqueName($indexes, array $columns): ?string
    {
        $index = $indexes->first(fn (array $index) =>
            ($index['unique'] ?? false) && array_values($index['columns'] ?? []) === $columns
        );

        return $index['name'] ?? null;
    }
};
