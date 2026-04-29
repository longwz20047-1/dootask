<?php

// [CUSTOM:report-channel]
// Factory for App\Models\TaskReport used in Sprint 1+ feature tests.

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\TaskReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskReportFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = TaskReport::class;

    /**
     * Define the model's default state.
     *
     * 注：task / project / reporter 均用 Factory 关联，调用方可覆盖单个
     * （如 ['task_id' => $existingTask->id]）来共享上层实体。
     *
     * @return array
     */
    public function definition()
    {
        return [
            'task_id'         => ProjectTask::factory(),
            'parent_id'       => 0,
            'project_id'      => Project::factory(),
            'reporter_userid' => User::factory(),
            'work_date'       => $this->faker->date(),
            'values'          => ['hours' => $this->faker->randomFloat(2, 0.5, 12)],
            'cascade_deleted' => false,
        ];
    }
}
