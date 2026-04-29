<?php

// [CUSTOM:report-channel]
// Spec §3.9bis：在 project_tasks 加 template_id 列（v3.26 4-tier priority 顶层槽位）

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTemplateIdToProjectTasks extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('project_tasks', 'template_id')) {
            return;
        }
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('template_id')->nullable()->index()
                ->comment('v3.26 软引用 task_report_templates.id（最高优先级，留空则按 flow_item resolve）');
        });
    }

    public function down()
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->dropIndex(['template_id']);
            $table->dropColumn('template_id');
        });
    }
}
