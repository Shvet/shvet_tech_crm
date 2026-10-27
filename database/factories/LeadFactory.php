<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\PipelineStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Must be used inside a tenant context (Tenancy::runAs): source and stage come from the current tenant.
 *
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'phone_e164' => '+91'.fake()->numerify('9#########'),
            'email' => fake()->unique()->safeEmail(),
            'lead_source_id' => fn () => LeadSource::query()->where('key', 'manual')->value('id'),
            'pipeline_stage_id' => fn () => PipelineStage::query()->where('key', 'new')->value('id'),
        ];
    }
}
