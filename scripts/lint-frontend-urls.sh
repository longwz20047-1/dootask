#!/bin/bash
# Sprint 0 Task 0.3 — frontend URL prefix lint for task report channel
# 扫前端所有 dispatch('call', { url: ... }) 缺 project/ 前缀
# 注意 1: grep -E 不支持 lookahead，必须 -P
# 注意 2: 仅扫**新建任务上报通道**端点（report_field/report_dashboard/report_template/trigger_log）
#         既有"工作报告"模块（report/my, report/receive, report/detail 等）不在 scope
#         plan v1.12 顶部边界声明：既有 ReportController + Report model 不动
# [CUSTOM:report-channel]
set -u
ROOT=$(git rev-parse --show-toplevel)
TARGET="$ROOT/resources/assets/js/pages/manage/"

if [ ! -d "$TARGET" ]; then
    echo "FAIL: target dir not found: $TARGET"
    exit 2
fi

violations=$(grep -rnP "url:\s*['\"](?!project/)(report_field|report_dashboard|report_template|trigger_log)" \
    "$TARGET" 2>/dev/null | wc -l)

if [ "$violations" -gt 0 ]; then
    echo "FAIL: $violations URLs missing project/ prefix"
    grep -rnP "url:\s*['\"](?!project/)(report_field|report_dashboard|report_template|trigger_log)" \
        "$TARGET" 2>/dev/null
    exit 1
fi
echo "PASS: 0 URLs missing prefix"
exit 0
