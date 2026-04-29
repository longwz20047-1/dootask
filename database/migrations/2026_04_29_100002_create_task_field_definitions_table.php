<?php

// [CUSTOM:report-channel]
// Spec §3.2 task_field_definitions：字段定义表 + 内建 hours/note 种子

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class CreateTaskFieldDefinitionsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('task_field_definitions')) {
            return;
        }
        Schema::create('task_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20)->comment('global/project/flow_item');
            $table->unsignedInteger('project_id')->default(0)->comment('scope=project 时关联');
            $table->unsignedBigInteger('flow_item_id')->default(0)->comment('scope=flow_item 时关联');
            $table->string('code', 64)->comment('字段标识符');
            $table->string('name', 128)->comment('字段名称（i18n key）');
            $table->string('type', 32)->comment('text/textarea/number/date/select/multi_select/attachment/json');
            $table->json('options')->nullable()->comment('类型相关配置');
            $table->json('default_value')->nullable();
            $table->boolean('required')->default(false);
            $table->integer('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->boolean('is_builtin')->default(false)->comment('内建字段拒删');
            $table->boolean('aggregatable')->default(false)->comment('v3.18 是否可聚合');
            $table->string('aggregate_strategy', 32)->default('')->comment('sum/avg/count/distinct');
            $table->boolean('has_index')->default(false)->comment('v3.18 是否已建虚拟列索引');
            $table->timestamps();

            $table->unique(['scope', 'project_id', 'code'], 'uniq_scope_project_code');
        });

        // Seed builtin: hours + note
        DB::table('task_field_definitions')->insert([
            [
                'scope'              => 'global',
                'project_id'         => 0,
                'flow_item_id'       => 0,
                'code'               => 'hours',
                'name'               => '工时',
                'type'               => 'number',
                'options'            => json_encode(['min' => 0, 'max' => 24, 'step' => 0.5, 'unit' => 'h']),
                'default_value'      => null,
                'required'           => true,
                'sort'               => 1,
                'enabled'            => true,
                'is_builtin'         => true,
                'aggregatable'       => true,
                'aggregate_strategy' => 'sum',
                'has_index'          => true,
                'created_at'         => now(),
                'updated_at'         => now(),
            ],
            [
                'scope'              => 'global',
                'project_id'         => 0,
                'flow_item_id'       => 0,
                'code'               => 'note',
                'name'               => '备注',
                'type'               => 'textarea',
                'options'            => json_encode(['max_length' => 2000]),
                'default_value'      => null,
                'required'           => false,
                'sort'               => 99,
                'enabled'            => true,
                'is_builtin'         => true,
                'aggregatable'       => false,
                'aggregate_strategy' => '',
                'has_index'          => false,
                'created_at'         => now(),
                'updated_at'         => now(),
            ],
        ]);
    }

    public function down()
    {
        Schema::dropIfExists('task_field_definitions');
    }
}
