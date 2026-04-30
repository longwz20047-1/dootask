<?php

// [CUSTOM:report-channel] Sprint 6 Task 6.8 V33 数据迁移命令
// Spec §23 V32 → V33 数据迁移
//
// 命令签名：php artisan migrate:report {--dry-run|--execute}
//
// 职责：把 builtin global default 模板（Sprint 6 Pass 1 Seed）关联 hours+note
// builtin 字段（Sprint 1 Pass 1 Seed）到 task_report_template_fields pivot 表
// 实现"默认模板 = hours + note 双字段"的实际语义。
//
// dry-run 模式：仅模拟输出，不动 DB
// execute 模式：真创建 pivot 行（事务包裹，幂等）

namespace App\Console\Commands;

use App\Models\TaskFieldDefinition;
use App\Models\TaskReportTemplate;
use App\Models\TaskReportTemplateField;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateReportV33 extends Command
{
    protected $signature = 'migrate:report {--dry-run : 仅模拟，不改 DB} {--execute : 真实执行}';

    protected $description = 'V32 → V33 数据迁移：将 builtin global default 关联 hours+note 字段';

    public function handle()
    {
        $dryRun  = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');

        if (!$dryRun && !$execute) {
            $this->error('必须指定 --dry-run 或 --execute');
            return 1;
        }

        if ($dryRun && $execute) {
            $this->error('不能同时指定 --dry-run 和 --execute');
            return 1;
        }

        if ($dryRun) {
            $this->info('[DRY RUN] 模拟模式 — 不会改 DB');
        } else {
            $this->info('[EXECUTE] 真实执行模式');
        }

        // Step 1: 验证 builtin global default 存在（Sprint 6 Pass 1 Seed）
        $defaultTpl = TaskReportTemplate::where('is_builtin', true)
            ->where('scope', 'global')
            ->where('is_default', true)
            ->first();
        if (!$defaultTpl) {
            $this->error('未找到 builtin global default 模板（Sprint 6 Pass 1 Seed 应已存在）');
            return 1;
        }
        $this->line("Found default template: id={$defaultTpl->id}, name={$defaultTpl->name}");

        // Step 2: 查找 hours + note builtin 字段（Sprint 1 Pass 1 Seed）
        $builtinFields = TaskFieldDefinition::where('is_builtin', true)
            ->where('scope', 'global')
            ->whereIn('code', ['hours', 'note'])
            ->get();
        $this->line(
            'Found ' . $builtinFields->count() . ' builtin fields: '
            . $builtinFields->pluck('code')->implode(', ')
        );

        if ($builtinFields->count() < 2) {
            $this->error('未找到 hours/note builtin 字段（Sprint 1 Pass 1 Seed 应已创建）');
            return 1;
        }

        // Step 3: 计算需新建 pivot 数（幂等：已存在则跳过）
        $existingPivotCount = TaskReportTemplateField::where('template_id', $defaultTpl->id)
            ->whereIn('field_id', $builtinFields->pluck('id'))
            ->count();
        $this->line("Existing pivots for default tpl: {$existingPivotCount}");

        $toCreate = $builtinFields->count() - $existingPivotCount;
        $this->line("Pivots to create: {$toCreate}");

        if ($toCreate === 0) {
            $this->info('No pivots needed. V33 already migrated.');
            return 0;
        }

        if ($dryRun) {
            foreach ($builtinFields as $field) {
                $exists = TaskReportTemplateField::where('template_id', $defaultTpl->id)
                    ->where('field_id', $field->id)
                    ->exists();
                if (!$exists) {
                    $this->line(
                        "[DRY RUN] WOULD CREATE pivot: template={$defaultTpl->id} ↔ field={$field->id} ({$field->code})"
                    );
                }
            }
            return 0;
        }

        // EXECUTE 模式：事务内真创建 pivots
        DB::transaction(function () use ($defaultTpl, $builtinFields) {
            foreach ($builtinFields as $idx => $field) {
                $exists = TaskReportTemplateField::where('template_id', $defaultTpl->id)
                    ->where('field_id', $field->id)
                    ->exists();
                if (!$exists) {
                    $pivot = TaskReportTemplateField::createInstance([
                        'template_id' => $defaultTpl->id,
                        'field_id'    => $field->id,
                        'override'    => null,
                        'sort'        => $idx + 1,
                    ]);
                    $pivot->save();
                    $this->info(
                        "CREATED pivot: template={$defaultTpl->id} ↔ field={$field->id} ({$field->code})"
                    );
                }
            }
        });

        $this->info('V33 migration completed.');
        return 0;
    }
}
