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
        <PageTitle :title="$L('我的待汇报任务')"/>

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
                    }, this.$L('汇报')),
                },
            ],
        };
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
                const data = {};
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
    .filter-bar {
        margin-bottom: 16px;
    }
    ::v-deep .task-urgent-row td {
        background-color: #fff5f5;
    }
}
</style>
