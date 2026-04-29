<?php

// [CUSTOM:report-channel]
// Factory for App\Models\ProjectTask used in report channel feature tests.
// dootask AbstractModel:32 already `use HasFactory`, so subclasses inherit automatically.

namespace Database\Factories;

use App\Models\ProjectTask;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectTaskFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = ProjectTask::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'parent_id'    => 0,
            'project_id'   => 1,
            'column_id'    => 1,
            'dialog_id'    => 0,
            'flow_item_id' => 0,
            'name'         => $this->faker->sentence(4),
            'desc'         => $this->faker->paragraph(),
            'userid'       => 1,
            'visibility'   => 1,
            'sort'         => 0,
        ];
    }
}
