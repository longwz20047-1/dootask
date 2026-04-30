<?php

// [CUSTOM:report-channel]
// Sprint 6 Task 6.4: spec §3.9 给 project_task_reports 加 template_id 软引用
// （注意：v3.26 4 档优先级最高位 project_tasks.template_id 已在 Sprint 1.1.5
//   migration 100001 处理；本迁移是给 report 表加 template_id，记录该 report
//   生成时使用的模板）

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTemplateIdToProjectTaskReports extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('project_task_reports', 'template_id')) {
            return;
        }
        Schema::table('project_task_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('template_id')->nullable()->index()
                ->comment('软引用 task_report_templates.id（v3.3 后置加，记录该 report 用的模板）');
        });
    }

    public function down()
    {
        Schema::table('project_task_reports', function (Blueprint $table) {
            if (Schema::hasColumn('project_task_reports', 'template_id')) {
                $table->dropIndex(['template_id']);
                $table->dropColumn('template_id');
            }
        });
    }
}
