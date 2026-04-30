<?php

// [CUSTOM:report-channel] Sprint 6 Task 6.7
// Spec §3.6.1 TaskReportTemplate Observer
//
// 职责：
//   1. deleting：拒删 builtin global default（系统种子，删了 4 档优先级失效）
//   2. saving：
//      - 拒改 builtin 关键字段（name / scope / scope_id / is_builtin / is_default）
//      - validateTriggerRules 84 状态机校验（spec §3.6.1）：
//        7 events × 3 modes × 4 constraint 约束矩阵
//
// 84 状态机矩阵（spec §3.6.1）：
//   events:  on_complete, on_start, on_status_change, on_flow_change, daily, weekly, manual
//   modes:   block, modal, remind
//   targets: reporter, collaborators, assignees
//   constraint allowed keys × mode：
//     block  → [min_count, time_window]            （硬阻断，不允许 max_count / frequency_limit）
//     modal  → [_hint]                             （仅显示提示）
//     remind → [frequency_limit_min, time_window, _hint]

namespace App\Observers;

use App\Exceptions\ApiException;
use App\Models\TaskReportTemplate;

class TaskReportTemplateObserver
{
    /**
     * 拒删 builtin global default（系统种子）
     */
    public function deleting(TaskReportTemplate $template): void
    {
        if ($template->is_builtin && $template->scope === 'global' && $template->is_default) {
            throw new ApiException(
                '内建默认模板不可删除（系统种子，删除将导致 4 档优先级失效）',
                [],
                -1
            );
        }
    }

    /**
     * 拒改 builtin 关键字段 + validateTriggerRules
     */
    public function saving(TaskReportTemplate $template): void
    {
        // 1. 已存在 + 原始为 builtin → 拒改关键字段
        if ($template->exists && $template->getOriginal('is_builtin')) {
            $immutable = ['name', 'scope', 'scope_id', 'is_builtin', 'is_default'];
            foreach ($immutable as $field) {
                if ($template->isDirty($field)) {
                    throw new ApiException("内建模板不允许修改 {$field}", [], -1);
                }
            }
        }

        // 2. trigger_rules 84 状态机校验（dirty 时才校验，避免重复 cost）
        if ($template->isDirty('trigger_rules')) {
            $rules = $template->trigger_rules;
            // null / 空数组合法；非数组拒
            if ($rules === null || $rules === []) {
                return;
            }
            if (!is_array($rules)) {
                throw new ApiException('trigger_rules 必须是数组', [], -1);
            }
            self::validateTriggerRules($rules);
        }
    }

    /**
     * 84 状态机校验：每条 rule 必须满足 event/mode/target/constraint 4 维合法组合
     * (spec §3.6.1)
     *
     * @param  array  $rules  trigger_rules 数组
     * @throws ApiException
     */
    public static function validateTriggerRules(array $rules): void
    {
        $validEvents = [
            'on_complete', 'on_start', 'on_status_change', 'on_flow_change',
            'daily', 'weekly', 'manual',
        ];
        $validModes   = ['block', 'modal', 'remind'];
        $validTargets = ['reporter', 'collaborators', 'assignees'];

        foreach ($rules as $idx => $rule) {
            if (!is_array($rule)) {
                throw new ApiException("规则 #{$idx} 必须是对象", [], -1);
            }

            // event 必填 + 合法
            if (!isset($rule['event']) || !in_array($rule['event'], $validEvents, true)) {
                throw new ApiException(
                    "规则 #{$idx} event 非法（需为 " . implode('/', $validEvents) . '）',
                    [],
                    -1
                );
            }

            // mode 必填 + 合法
            if (!isset($rule['mode']) || !in_array($rule['mode'], $validModes, true)) {
                throw new ApiException(
                    "规则 #{$idx} mode 非法（需为 block/modal/remind）",
                    [],
                    -1
                );
            }

            // target 可选 + 合法（默认 reporter）
            $target = $rule['target'] ?? 'reporter';
            if (!in_array($target, $validTargets, true)) {
                throw new ApiException(
                    "规则 #{$idx} target 非法（需为 reporter/collaborators/assignees）",
                    [],
                    -1
                );
            }

            // constraint 按 mode 限制
            $constraint = $rule['constraint'] ?? [];
            if (!is_array($constraint)) {
                throw new ApiException("规则 #{$idx} constraint 必须是对象", [], -1);
            }

            $allowedKeys = self::allowedConstraintKeysForMode($rule['mode']);
            foreach (array_keys($constraint) as $key) {
                if (!in_array($key, $allowedKeys, true)) {
                    throw new ApiException(
                        "规则 #{$idx} mode={$rule['mode']} 不允许 constraint.{$key}（仅允许："
                            . implode(',', $allowedKeys) . '）',
                        [],
                        -1
                    );
                }
            }

            // 数值字段非负校验
            foreach (['min_count', 'max_count', 'time_window', 'frequency_limit_min'] as $numKey) {
                if (isset($constraint[$numKey])
                    && (!is_numeric($constraint[$numKey]) || $constraint[$numKey] < 0)
                ) {
                    throw new ApiException(
                        "规则 #{$idx} constraint.{$numKey} 必须是非负数字",
                        [],
                        -1
                    );
                }
            }
        }
    }

    /**
     * 按 mode 返回 constraint 允许的 keys（spec §3.6.1 矩阵）
     */
    public static function allowedConstraintKeysForMode(string $mode): array
    {
        switch ($mode) {
            case 'block':
                // 硬阻断：仅 min_count / time_window（不允许 max_count / frequency_limit）
                return ['min_count', 'time_window'];
            case 'modal':
                // 弹窗：仅 _hint 提示
                return ['_hint'];
            case 'remind':
                // 软提醒：frequency_limit_min / time_window / _hint
                return ['frequency_limit_min', 'time_window', '_hint'];
            default:
                return [];
        }
    }
}
