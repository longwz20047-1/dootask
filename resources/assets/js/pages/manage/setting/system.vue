<template>
    <div class="setting-item submit">
        <Tabs v-model="tabAction">
            <TabPane :label="$L('系统设置')" name="setting">
                <SystemSetting/>
            </TabPane>
            <TabPane :label="$L('任务优先级')" name="taskPriority">
                <SystemTaskPriority/>
            </TabPane>
            <TabPane :label="$L('项目模板')" name="columnTemplate">
                <SystemColumnTemplate/>
            </TabPane>
            <TabPane :label="$L('文件设置')" name="fileSetting">
                <SystemFileSetting/>
            </TabPane>
            <!-- [CUSTOM:report-channel] Sprint 4 Pass 1 · Task 4.1 -->
            <TabPane :label="$L('上报字段')" name="reportFields">
                <SystemReportFields v-if="tabAction === 'reportFields'"/>
            </TabPane>
        </Tabs>
    </div>
</template>

<script>
import SystemSetting from "./components/SystemSetting";
import SystemTaskPriority from "./components/SystemTaskPriority";
import SystemColumnTemplate from "./components/SystemColumnTemplate";
import SystemFileSetting from "./components/SystemFileSetting";
// [CUSTOM:report-channel] Sprint 4 Pass 1 · Task 4.1
import SystemReportFields from "./components/SystemReportFields";

export default {
    components: {SystemColumnTemplate, SystemTaskPriority, SystemSetting, SystemFileSetting, SystemReportFields},
    data() {
        return {
            tabAction: 'setting',
        }
    },
}
</script>
