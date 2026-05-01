<!-- [CUSTOM:report-channel] Sprint 7-D Pass 2 Task 7-D.3
     我的待汇报任务列表页

     数据源: POST /api/project/report/pending_list (Sprint 7-D Pass 1)
     - 时间筛选 / 项目筛选 / 含归档 toggle
     - 紧急 Tag (is_urgent, end_at < 24h)
     - my_role (owner / collaborator)
     - my_report_count
     - 操作列"汇报"按钮 → 打开 ReportDialog

     ReportDialog 内置 report/save + 'saved' 事件，本页监听 'saved' 刷新列表。
-->
<template>
    <div class="my-pending-reports">
        <PageTitle :title="pageTitle"/>

        <!-- 视图切换：待汇报 / 已汇报 / 全部 -->
        <Tabs v-model="mode" class="mode-tabs" @on-click="loadTasks">
            <TabPane :label="$L('待汇报')" name="pending"/>
            <TabPane :label="$L('已汇报')" name="reported"/>
            <TabPane :label="$L('全部')" name="all"/>
        </Tabs>

        <!-- 筛选条 -->
        <Form class="filter-bar" inline :label-width="80" @submit.native.prevent>
            <FormItem :label="$L('时间范围')">
                <DatePicker
                    type="daterange"
                    :value="dateRange"
                    format="yyyy-MM-dd"
                    value-format="yyyy-MM-dd"
                    :placeholder="$L('近 14 天')"
                    @on-change="onDateChange"/>
            </FormItem>
            <FormItem :label="$L('项目')">
                <Select v-model="filterProjectId" style="width:180px;" clearable :placeholder="$L('全部项目')" @on-change="loadTasks">
                    <Option v-for="proj in projectList" :key="proj.id" :value="proj.id">
                        {{proj.name}}
                    </Option>
                </Select>
            </FormItem>
            <FormItem :label="$L('含归档')">
                <i-switch v-model="includeArchived" @on-change="loadTasks"/>
            </FormItem>
            <FormItem>
                <Button type="primary" :loading="loading" @click="loadTasks">
                    {{$L('刷新')}}
                </Button>
            </FormItem>
        </Form>

        <!-- 表格 -->
        <Table
            :columns="columns"
            :data="tasks"
            :loading="loading"
            :row-class-name="rowClassName"/>

        <!-- ReportDialog -->
        <ReportDialog ref="reportDialog" @saved="loadTasks"/>
    </div>
</template>

<script>
import ReportDialog from "../../../components/report/ReportDialog";

export default {
    name: 'MyPendingReports',
    components: {ReportDialog},
    data() {
        return {
            loading: false,
            mode: 'pending',        // 'pending' | 'reported' | 'all'
            tasks: [],
            projectList: [],
            filterProjectId: null,
            dateRange: [],          // [from, to] yyyy-mm-dd
            includeArchived: false,
            columns: [
                {
                    title: this.$L('任务'),
                    key: 'name',
                    minWidth: 220,
                    render: (h, p) => {
                        const children = [];
                        if (p.row.is_urgent) {
                            children.push(h('Tag', {
                                props: {color: 'error', size: 'small'},
                                style: {marginRight: '6px'},
                            }, this.$L('紧急')));
                        }
                        children.push(h('span', p.row.name));
                        return h('div', children);
                    },
                },
                {
                    title: this.$L('任务状态'),
                    key: 'flow_item_name',
                    width: 130,
                    render: (h, p) => {
                        // dootask 标准：flow_item_name 格式 "status|name|color"，
                        // 缺省时退化按 complete_at 显示已完成/未完成
                        const wf = $A.convertWorkflow({
                            flow_item_name: p.row.flow_item_name || '',
                            complete_at: p.row.complete_at || '',
                        });
                        const label = wf.name || (p.row.complete_at ? this.$L('已完成') : this.$L('未完成'));
                        const color = wf.color || (p.row.complete_at ? '#19be6b' : '#909399');
                        return h('Tag', {
                            props: {color: 'default', size: 'small'},
                            style: {color, borderColor: color},
                        }, label);
                    },
                },
                {
                    title: this.$L('项目'),
                    key: 'project_id',
                    width: 160,
                    render: (h, p) => h('span', this.projectName(p.row.project_id)),
                },
                {
                    title: this.$L('我的角色'),
                    key: 'my_role',
                    width: 110,
                    render: (h, p) => h('span',
                        p.row.my_role === 'owner' ? this.$L('负责人') : this.$L('协助人')
                    ),
                },
                {
                    title: this.$L('截止时间'),
                    key: 'end_at',
                    width: 160,
                    render: (h, p) => h('span', p.row.end_at || '-'),
                },
                {
                    title: this.$L('已汇报'),
                    key: 'my_report_count',
                    width: 90,
                    render: (h, p) => h('span', String(p.row.my_report_count == null ? 0 : p.row.my_report_count)),
                },
                {
                    title: this.$L('操作'),
                    width: 100,
                    align: 'center',
                    render: (h, p) => h('Button', {
                        props: {type: 'primary', size: 'small'},
                        on: {click: () => this.reportTask(p.row)},
                    }, this.reportButtonLabel(p.row)),
                },
            ],
        };
    },
    computed: {
        pageTitle() {
            const map = {
                pending:  this.$L('我的待汇报任务'),
                reported: this.$L('我的已汇报任务'),
                all:      this.$L('我的任务（汇报）'),
            };
            return map[this.mode] || this.$L('我的待汇报任务');
        },
    },
    mounted() {
        this.loadProjects();
        this.loadTasks();
    },
    methods: {
        rowClassName(row) {
            return row && row.is_urgent ? 'task-urgent-row' : '';
        },

        projectName(projectId) {
            const p = this.projectList.find(x => x.id === projectId);
            return p ? p.name : '-';
        },

        async loadProjects() {
            try {
                const resp = await this.$store.dispatch('call', {
                    url: 'project/lists',
                    data: {archived: 'no', getstatistics: 'no'},
                });
                this.projectList = (resp && resp.data && resp.data.data) || [];
            } catch (err) {
                // 失败仅影响项目筛选下拉与名称显示，不阻断主功能
            }
        },

        async loadTasks() {
            this.loading = true;
            try {
                const data = {mode: this.mode};
                if (this.filterProjectId) {
                    data.project_id = this.filterProjectId;
                }
                if (Array.isArray(this.dateRange) && this.dateRange.length === 2) {
                    if (this.dateRange[0]) data.date_from = this.dateRange[0];
                    if (this.dateRange[1]) data.date_to = this.dateRange[1];
                }
                if (this.includeArchived) {
                    data.include_archived = true;
                }

                const resp = await this.$store.dispatch('call', {
                    url: 'project/report/pending_list',
                    data,
                });
                this.tasks = (resp && resp.data) || [];
            } catch (err) {
                this.$Message.error((err && err.msg) || this.$L('加载失败'));
            } finally {
                this.loading = false;
            }
        },

        reportButtonLabel(row) {
            // reported 视图：已汇报过 → 「再次汇报」；其余路径默认「汇报」
            if (this.mode === 'reported' || (row && Number(row.my_report_count) > 0)) {
                return this.$L('再次汇报');
            }
            return this.$L('汇报');
        },

        onDateChange(val) {
            this.dateRange = val || [];
            this.loadTasks();
        },

        reportTask(task) {
            // ReportDialog 内置 report/save 调用 + 成功后 emit 'saved'
            this.$refs.reportDialog.open(task);
        },
    },
};
</script>

<style lang="scss" scoped>
.my-pending-reports {
    padding: 16px;
    .mode-tabs {
        margin-bottom: 8px;
    }
    .filter-bar {
        margin-bottom: 16px;
    }
    ::v-deep .task-urgent-row td {
        background-color: #fff5f5;
    }
}
</style>
