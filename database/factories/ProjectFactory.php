<?php

// [CUSTOM:report-channel]
// Factory for App\Models\Project used in report channel feature tests.
// dootask AbstractModel:32 already `use HasFactory`, so subclasses inherit automatically.

namespace Database\Factories;

use App\Models\Project;
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
     * @return array
     */
    public function definition()
    {
        return [
            'name'        => $this->faker->company(),
            'desc'        => $this->faker->paragraph(),
            'userid'      => 1,
            'archived_at' => null,
        ];
    }
}
