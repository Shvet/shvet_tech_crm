<?php

namespace App\Actions\Pipeline;

use App\Models\PipelineStage;
use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin changes to the workspace's pipeline: add, rename, change outcome, (de)activate and reorder stages.
 *
 * Stages are never deleted (leads and the append-only stage history point at them); deactivating hides a stage from
 * forms and filters while the leads already in it stay visible on the board.
 *
 * Invariant kept after every change: the FIRST active stage is an "open" stage, because new leads start there.
 */
class ManagePipelineStages
{
    public const OUTCOMES = ['open', 'won', 'lost'];

    public function create(string $name, string $outcome): PipelineStage
    {
        return DB::transaction(function () use ($name, $outcome) {
            $this->lockStages();
            $stage = PipelineStage::query()->create([
                'key' => $this->uniqueKey($name),
                'name' => $name,
                'outcome' => $outcome,
                'position' => (int) PipelineStage::query()->max('position') + 1,
                'is_active' => true,
            ]);
            $this->assertInvariant();
            AuditLogger::record('pipeline.stage_created', $stage, ['name' => $name, 'outcome' => $outcome]);

            return $stage;
        });
    }

    /** @param  array{name?: string, outcome?: string, is_active?: bool}  $changes */
    public function update(PipelineStage $stage, array $changes): void
    {
        DB::transaction(function () use ($stage, $changes) {
            $this->lockStages();
            $before = $stage->only(['name', 'outcome', 'is_active']);
            $stage->fill($changes)->save();
            $this->assertInvariant();
            AuditLogger::record('pipeline.stage_updated', $stage, ['before' => $before, 'after' => $stage->only(['name', 'outcome', 'is_active'])]);
        });
    }

    /** @param  list<int>  $orderedIds  every stage id of the workspace, in the new order */
    public function reorder(array $orderedIds): void
    {
        DB::transaction(function () use ($orderedIds) {
            $stages = $this->lockStages();
            $existing = $stages->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $given = collect($orderedIds)->map(fn ($id) => (int) $id)->sort()->values()->all();
            if ($existing !== $given) {
                throw ValidationException::withMessages(['ids' => 'The stage list changed meanwhile. Reload the page and try again.']);
            }

            foreach (array_values($orderedIds) as $i => $id) {
                PipelineStage::query()->whereKey($id)->update(['position' => $i + 1]);
            }
            $this->assertInvariant();
            AuditLogger::record('pipeline.stages_reordered', null, ['order' => array_values(array_map('intval', $orderedIds))]);
        });
    }

    /** @return Collection<int, PipelineStage> */
    private function lockStages()
    {
        return PipelineStage::query()->orderBy('position')->orderBy('id')->lockForUpdate()->get();
    }

    private function assertInvariant(): void
    {
        $first = PipelineStage::query()->where('is_active', true)->orderBy('position')->orderBy('id')->first();
        if ($first === null || $first->outcome !== 'open') {
            throw ValidationException::withMessages([
                'stage' => 'The first active stage must be an open stage (new leads start there). Keep at least one active open stage at the top.',
            ]);
        }
    }

    private function uniqueKey(string $name): string
    {
        $base = Str::limit(Str::slug($name, '_'), 32, '') ?: 'stage';
        $key = $base;
        for ($i = 2; PipelineStage::query()->where('key', $key)->exists(); $i++) {
            $key = $base.'_'.$i;
        }

        return $key;
    }
}
