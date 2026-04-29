<?php

// [CUSTOM:report-channel]
// Factory for App\Models\Project used in report channel feature tests.
// dootask AbstractModel:32 already `use HasFactory`, so subclasses inherit automatically.

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Project::class;

    /**
     * Define the model's default state.
     *
     * Sprint 1 Pass 1 R1 fix: hardcoded userid=1 fails when no user exists in test DB.
     * Use User::factory() so each Project comes paired with a real owner.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'name'        => $this->faker->company(),
            'desc'        => $this->faker->paragraph(),
            'userid'      => User::factory(),
            'archived_at' => null,
        ];
    }

    /**
     * Sprint 1 Pass 1 R3 fix: archived state for upcoming Sprint 2 lifecycle tests.
     */
    public function archived()
    {
        return $this->state(fn () => ['archived_at' => now()]);
    }
}
