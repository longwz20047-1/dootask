<?php

// [CUSTOM:report-channel]
// Sprint 6 Task 6.3: spec §3.8 task_report_trigger_log 触发去重日志

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTaskReportTriggerLogTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('task_report_trigger_log')) {
            return;
        }
        Schema::create('task_report_trigger_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id')->index();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->unsignedInteger('rule_idx')->default(0);
            $table->string('event', 32);
            $table->string('mode', 16);
            $table->dateTime('triggered_at');
            $table->unsignedInteger('user_id');
            $table->timestamps();

            $table->unique(
                ['task_id', 'rule_idx', 'triggered_at', 'event', 'mode'],
                'uniq_trigger_dedup'
            );
        });
    }

    public function down()
    {
        Schema::dropIfExists('task_report_trigger_log');
    }
}
