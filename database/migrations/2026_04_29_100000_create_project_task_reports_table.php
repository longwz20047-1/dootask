<?php

// [CUSTOM:report-channel]
// Spec §3.1 主表 project_task_reports
// hours_v STORED 虚拟列必须 raw SQL（Blueprint 不支持 generated column · v3.1 守门人 P1-1）

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class CreateProjectTaskReportsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('project_task_reports')) {
            return;
        }
        Schema::create('project_task_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id')->index()->comment('关联 project_tasks.id');
            $table->unsignedBigInteger('parent_id')->default(0)->index()
                ->comment('冗余 project_tasks.parent_id');
            $table->unsignedInteger('project_id')->index()
                ->comment('冗余 project_id，moveTask 时级联更新');
            $table->unsignedInteger('reporter_userid')
                ->comment('登记人快照（NF3 离职不漂移）');
            $table->date('work_date')->comment('PRC 时区下的归属日期');
            $table->json('values')->nullable()
                ->comment('字段值 JSON，结构由 task_field_definitions 定义');
            $table->boolean('cascade_deleted')->default(false)
                ->comment('true=随任务软删；false=用户主动删');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['reporter_userid', 'work_date'], 'idx_reporter_date');
            $table->index(['project_id', 'work_date'], 'idx_project_date');
        });

        // STORED 虚拟列暴露 hours 给索引（F53 + F55 必须 raw SQL · v3.1 修 · 守门人 P1-1）
        $pre = DB::connection()->getTablePrefix();
        DB::statement("ALTER TABLE {$pre}project_task_reports
            ADD COLUMN hours_v DECIMAL(6,2)
              AS (CAST(JSON_VALUE(`values`, '$.hours') AS DECIMAL(6,2))) STORED");
        DB::statement("CREATE INDEX idx_hours_v ON {$pre}project_task_reports (hours_v)");
    }

    public function down()
    {
        Schema::dropIfExists('project_task_reports');
    }
}
