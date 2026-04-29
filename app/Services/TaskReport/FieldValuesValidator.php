<?php

// [CUSTOM:report-channel]
// Spec §6 FieldValuesValidator Service（v3.0 新核心 · v3.2 修 P0-2 + P1-1 + P1-8）
//
// 命名空间：App\Services\TaskReport（plan v1.12 Sprint 1 Pass 2 统一规则；
// spec §6 line 1735 写 App\Services\TaskReport，与本 plan 一致）。
//
// attachment 分支依赖 TaskFieldAttachment model（Sprint 5a Task 5a.4 才建），
// Pass 2 用 class_exists 兜底跳过；调用方 Pass 2 不传 attachment 类型字段。

namespace App\Services\TaskReport;

use App\Models\Project;
use App\Models\User;

class FieldValuesValidator
{
    /**
     * 按当前启用字段定义动态校验 values。
     *
     * @param array  $values     用户传入的 values JSON
     * @param array  $fieldDefs  当前启用字段（从 cache / DB），每条至少含 code/name/type/required/options
     * @param int    $reportId   关联 report_id（防 attachment 跨 report 越权）
     * @param int    $projectId  关联 project_id（user 字段做项目成员交集校验）
     * @param string $mode       'save' | 'read'
     *
     * @return array ['errors' => [{code, kind?, reason}], 'sanitized' => array, 'deprecated' => array]
     */
    public function validate(
        array $values,
        array $fieldDefs,
        int $reportId = 0,
        int $projectId = 0,
        string $mode = 'save'
    ): array {
        $errors = [];
        $sanitized = [];
        $deprecated = [];

        // v3.2 P0-2：预取项目成员列表（仅 save + 含 user 字段时计算）
        $projectUserids = null;
        if ($mode === 'save' && $projectId > 0) {
            $hasUserField = false;
            foreach ($fieldDefs as $f) {
                if (($f['type'] ?? null) === 'user') {
                    $hasUserField = true;
                    break;
                }
            }
            if ($hasUserField) {
                $project = Project::find($projectId);
                if ($project) {
                    $projectUserids = $project->relationUserids()->toArray();
                }
            }
        }

        foreach ($fieldDefs as $field) {
            $code = $field['code'];
            $value = $values[$code] ?? null;

            // required 校验
            if (!empty($field['required']) && ($value === null || $value === '')) {
                $errors[] = [
                    'code'   => $code,
                    'kind'   => 'missing',
                    'reason' => "{$field['name']} 必填",
                ];
                continue;
            }
            if ($value === null) {
                continue;
            }

            // type 分支校验
            switch ($field['type']) {
                case 'number':
                    if (!is_numeric($value)) {
                        $errors[] = [
                            'code'   => $code,
                            'kind'   => 'invalid',
                            'reason' => "{$field['name']} 必须是数字",
                        ];
                        break;
                    }
                    $opts = $field['options'] ?? [];
                    if (isset($opts['min']) && $value < $opts['min']) {
                        $errors[] = [
                            'code'   => $code,
                            'kind'   => 'invalid',
                            'reason' => "{$field['name']} 不能小于 {$opts['min']}",
                        ];
                    }
                    if (isset($opts['max']) && $value > $opts['max']) {
                        $errors[] = [
                            'code'   => $code,
                            'kind'   => 'invalid',
                            'reason' => "{$field['name']} 不能大于 {$opts['max']}",
                        ];
                    }
                    $sanitized[$code] = (float) $value;
                    break;

                case 'select':
                    $allowed = array_column($field['options'] ?? [], 'value');
                    if (!in_array($value, $allowed, true)) {
                        $errors[] = [
                            'code'   => $code,
                            'kind'   => 'invalid',
                            'reason' => "{$field['name']} 取值非法",
                        ];
                        break;
                    }
                    $sanitized[$code] = $value;
                    break;

                case 'multi_select':
                    if (!is_array($value)) {
                        $errors[] = [
                            'code'   => $code,
                            'kind'   => 'invalid',
                            'reason' => "{$field['name']} 必须是数组",
                        ];
                        break;
                    }
                    $allowed = array_column($field['options'] ?? [], 'value');
                    $invalid = array_diff($value, $allowed);
                    if ($invalid) {
                        $errors[] = [
                            'code'   => $code,
                            'kind'   => 'invalid',
                            'reason' => "{$field['name']} 包含非法值",
                        ];
                    }
                    $sanitized[$code] = array_values(array_intersect($value, $allowed));
                    break;

                case 'date':
                    // v3.1 守门人 P1-2：正则只验格式，加 Carbon 验日期合法性
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
                        $errors[] = [
                            'code'   => $code,
                            'kind'   => 'invalid',
                            'reason' => "{$field['name']} 格式必须 YYYY-MM-DD",
                        ];
                        break;
                    }
                    try {
                        \Carbon\Carbon::createFromFormat('Y-m-d', $value, 'PRC');
                    } catch (\Throwable $e) {
                        $errors[] = [
                            'code'   => $code,
                            'kind'   => 'invalid',
                            'reason' => "{$field['name']} 不是合法日期",
                        ];
                        break;
                    }
                    $sanitized[$code] = $value;
                    break;

                case 'user':
                    // 兼容字符串 "1,2,3"
                    if (is_string($value)) {
                        $value = array_filter(explode(',', $value), 'strlen');
                    } elseif (!is_array($value)) {
                        $value = [$value];
                    }
                    $value = array_map('intval', $value);

                    // v3.2 P0-2：User 存在性 + 未禁用 + 项目成员交集
                    $existing = User::whereIn('userid', $value)
                        ->whereNull('disable_at')
                        ->pluck('userid')
                        ->toArray();
                    if ($projectUserids !== null) {
                        $valid = array_values(array_intersect($existing, $projectUserids));
                    } else {
                        $valid = $existing;
                    }
                    if (count($valid) !== count(array_unique($value))) {
                        $errors[] = [
                            'code'   => $code,
                            'kind'   => 'invalid',
                            'reason' => "{$code}: 含项目外/已禁用用户",
                        ];
                    }
                    $sanitized[$code] = $valid;
                    break;

                case 'attachment':
                    if (!is_array($value)) {
                        $value = [$value];
                    }
                    $value = array_map('intval', $value);

                    // v3.2 P1-1：新建 report 时禁止预填 attachment（先 save 拿 reportId）
                    if ($mode === 'save' && $reportId === 0 && !empty($value)) {
                        $errors[] = [
                            'code'   => $code,
                            'kind'   => 'invalid',
                            'reason' => "{$code}: 附件需先创建上报后再上传",
                        ];
                        $sanitized[$code] = [];
                        break;
                    }

                    // Sprint 5a Task 5a.4 之前 TaskFieldAttachment model 未建，
                    // 跳过 ownership 校验，仅保留传入的 ID 列表（Pass 2 不会有
                    // attachment 字段定义被启用，故此分支实际进不来）。
                    $attachmentClass = '\\App\\Models\\TaskFieldAttachment';
                    if (class_exists($attachmentClass)) {
                        $valid = $attachmentClass::whereIn('id', $value)
                            ->where('report_id', $reportId)
                            ->pluck('id')
                            ->toArray();
                        if (count($valid) !== count(array_unique($value))) {
                            $errors[] = [
                                'code'   => $code,
                                'kind'   => 'invalid',
                                'reason' => "{$code}: 含非本上报附件",
                            ];
                        }
                        $sanitized[$code] = array_values($valid);
                    } else {
                        // Pass 2 不应触达：未建 model 即直接保留输入，避免炸
                        $sanitized[$code] = array_values(array_unique($value));
                    }
                    break;

                case 'text':
                case 'textarea':
                    $maxLen = $field['options']['max_length']
                        ?? ($field['type'] === 'textarea' ? 500 : 100);
                    if (mb_strlen((string) $value) > $maxLen) {
                        $errors[] = [
                            'code'   => $code,
                            'kind'   => 'invalid',
                            'reason' => "{$field['name']} 超过 {$maxLen} 字",
                        ];
                    }
                    $sanitized[$code] = (string) $value;
                    break;

                case 'json':
                    // spec §3.2 type 列含 'json'。无 strict schema 校验，仅要求 array-like。
                    if (!is_array($value)) {
                        $errors[] = [
                            'code'   => $code,
                            'kind'   => 'invalid',
                            'reason' => "{$field['name']} 必须是 JSON 对象",
                        ];
                        break;
                    }
                    $sanitized[$code] = $value;
                    break;
            }
        }

        // 处理未定义字段（v3.2 P1-8：read 模式保留孤儿值；save 模式剔除 + 警告）
        $definedCodes = array_column($fieldDefs, 'code');
        foreach ($values as $key => $val) {
            if (!in_array($key, $definedCodes, true)) {
                if ($mode === 'read') {
                    $deprecated[$key] = $val;
                } else {
                    $errors[] = [
                        'code'   => $key,
                        'kind'   => 'unknown',
                        'reason' => "字段 {$key} 不在当前定义中（可能已被禁用）",
                    ];
                }
            }
        }

        return [
            'errors'     => $errors,
            'sanitized'  => $sanitized,
            'deprecated' => $deprecated,
        ];
    }
}
