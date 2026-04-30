<?php

// [CUSTOM:report-channel] Sprint 7-A Task 7.3.5
// Spec §11.8.X TaskReportTemplateField 改动 → 失效对应 template 的 dashboard_cache

namespace App\Observers;

use App\Models\TaskReportTemplateField;
use App\Services\TaskReport\DashboardCacheInvalidator;

class TaskReportTemplateFieldObserver
{
    public function saved(TaskReportTemplateField $pivot): void
    {
        DashboardCacheInvalidator::flush('template_field', $pivot->template_id);
    }

    public function deleted(TaskReportTemplateField $pivot): void
    {
        DashboardCacheInvalidator::flush('template_field', $pivot->template_id);
    }
}
