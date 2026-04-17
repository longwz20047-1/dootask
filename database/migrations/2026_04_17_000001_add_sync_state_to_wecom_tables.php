<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSyncStateToWecomTables extends Migration
{
    public function up()
    {
        Schema::table('wecom_department_mappings', function (Blueprint $table) {
            $table->timestamp('lost_at')->nullable()->comment('企微已删除部门的软标记时间');
            $table->index('lost_at', 'idx_wdm_lost_at');
        });

        Schema::table('user_wecom_bindings', function (Blueprint $table) {
            $table->timestamp('unbind_at')->nullable()->comment('企微已离职解绑时间');
            $table->index('unbind_at', 'idx_uwb_unbind_at');
        });
    }

    public function down()
    {
        Schema::table('wecom_department_mappings', function (Blueprint $table) {
            $table->dropIndex('idx_wdm_lost_at');
            $table->dropColumn('lost_at');
        });

        Schema::table('user_wecom_bindings', function (Blueprint $table) {
            $table->dropIndex('idx_uwb_unbind_at');
            $table->dropColumn('unbind_at');
        });
    }
}
