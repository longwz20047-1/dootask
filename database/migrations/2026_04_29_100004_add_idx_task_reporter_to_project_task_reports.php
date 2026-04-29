<?php
// [CUSTOM:report-channel]
// v1.12 Sprint 1 spec reviewer M1 fix: add spec §3.1 v3.22 idx_task_reporter index
// Required by §11.10 MyPendingReports for LEFT JOIN reports + per_user count performance.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIdxTaskReporterToProjectTaskReports extends Migration
{
    public function up()
    {
        Schema::table('project_task_reports', function (Blueprint $table) {
            $table->index(['task_id', 'reporter_userid'], 'idx_task_reporter');
        });
    }

    public function down()
    {
        Schema::table('project_task_reports', function (Blueprint $table) {
            $table->dropIndex('idx_task_reporter');
        });
    }
}
