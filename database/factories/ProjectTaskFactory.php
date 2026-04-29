<?php

// [CUSTOM:report-channel]
// Factory for App\Models\ProjectTask used in report channel feature tests.
// dootask AbstractModel:32 already `use HasFactory`, so subclasses inherit automatically.

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
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
     * Sprint 1 Pass 1 R1 fix:
     * - project_id 1 → Project::factory() （避免 FK 找不到项目）
     * - column_id 1 → 0 （factory 不建 column 关联，调用方按需覆盖）
     * - userid 1 → User::factory() （避免 FK 找不到用户）
     *
     * @return array
     */
    public function definition()
    {
        return [
            'parent_id'    => 0,
            'project_id'   => Project::factory(),
            'column_id'    => 0,    // factory 不建 column 关联，调用方按需覆盖
            'dialog_id'    => 0,
            'flow_item_id' => 0,
            'name'         => $this->faker->sentence(4),
            'desc'         => $this->faker->paragraph(),
            'userid'       => User::factory(),
            'visibility'   => 1,
            'sort'         => 0,
        ];
    }
}
