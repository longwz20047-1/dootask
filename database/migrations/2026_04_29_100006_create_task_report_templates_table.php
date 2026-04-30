<?php

// [CUSTOM:report-channel]
// Sprint 6 Task 6.1: spec §3.6 task_report_templates 主表
// 含 1 行 global default builtin seed（spec §6.5 护栏 1）

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class CreateTaskReportTemplatesTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('task_report_templates')) {
            return;
        }
        Schema::create('task_report_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('scope', 20)->comment('global / project / flow_item');
            $table->unsignedInteger('scope_id')->default(0)
                ->comment('scope=project 时关联 project_id, scope=flow_item 时关联 flow_item_id');
            $table->boolean('is_default')->default(false)
                ->comment('仅 scope=global 内允许 1 个 is_default=true');
            $table->boolean('is_builtin')->default(false)->comment('内建拒删');
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('trigger_rules')->nullable()
                ->comment('数组结构 [{event, mode, target, constraint, _hint?}, ...]');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['scope', 'scope_id', 'name'], 'uniq_scope_id_name');
        });

        // Seed 1 行 global default builtin（Pass 2 Observer 拒删护栏才生效）
        DB::table('task_report_templates')->insert([
            'name'          => '默认模板',
            'scope'         => 'global',
            'scope_id'      => 0,
            'is_default'    => true,
            'is_builtin'    => true,
            'description'   => '系统内建默认上报模板（关联 hours + note builtin 字段）',
            'enabled'       => true,
            'trigger_rules' => json_encode([]),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    public function down()
    {
        Schema::dropIfExists('task_report_templates');
    }
}
