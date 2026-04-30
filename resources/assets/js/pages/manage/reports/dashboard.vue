<!-- [CUSTOM:report-channel] Sprint 9 Pass 1 Task 9.1 + 9.2 + 9.3
     上报仪表盘主框架 + 6 维筛选 + ECharts 3 chart 自动选型

     数据源:
     - POST /api/project/report_dashboard/data  (Sprint 7-B Pass 2 Task 7.5)
     - POST /api/project/report_field/list      (Sprint 4 Pass 1 Task A) - 取 aggregatable 字段
     - POST /api/project/lists                  - 项目下拉

     v3.22 默认 dimensions=['reporter_userid'] (按汇报人拆分)
     v3.23/v1.12 双别名: 'time'/'day' 都映射到 day 别名 (与 StatisticsService::dimColMap 对齐)

     Pass 2 (后续) 计划:
     - Drill modal: POST /api/project/report_dashboard/drill
     - 导出:        POST /api/project/report_dashboard/export
     - 看板保存:    POST /api/project/report_dashboard/save_chart
-->
<template>
    <div class="report-dashboard">
        <PageTitle :title="$L('上报仪表盘')"/>

        <Row :gutter="16" class="dashboard-content">
            <!-- 左侧筛选 -->
            <Col span="6">
                <Card :title="$L('筛选')" :bordered="false">
                    <Form :label-width="80" @submit.native.prevent>
                        <FormItem :label="$L('指标')">
                            <Select v-model="metric" @on-change="onChangeFilters">
                                <Option value="count">{{ $L('记录数') }}</Option>
                                <OptionGroup v-if="aggregableFields.length > 0" :label="$L('聚合字段')">
                                    <Option v-for="f in aggregableFields" :key="`sum_${f.id}`" :value="`sum_${f.code}`">
                                        {{ $L('总和') }} {{ f.name }}
                                    </Option>
                                    <Option v-for="f in aggregableFields" :key="`avg_${f.id}`" :value="`avg_${f.code}`">
                                        {{ $L('平均') }} {{ f.name }}
                                    </Option>
                                </OptionGroup>
                            </Select>
                        </FormItem>

                        <FormItem :label="$L('拆分维度')">
                            <CheckboxGroup v-model="dimensions" @on-change="onChangeFilters">
                                <Checkbox label="reporter_userid">{{ $L('用户（按汇报人）') }}</Checkbox>
                                <Checkbox label="project_id">{{ $L('项目') }}</Checkbox>
                                <Checkbox label="task_id">{{ $L('任务') }}</Checkbox>
                                <Checkbox label="template_id">{{ $L('模板') }}</Checkbox>
                                <Checkbox label="time">{{ $L('日期') }}</Checkbox>
                            </CheckboxGroup>
                        </FormItem>

                        <FormItem :label="$L('视图模式')">
                            <RadioGroup v-model="splitMode" type="button" @on-change="onSplitModeChange">
                                <Radio label="by_user">{{ $L('按人拆分') }}</Radio>
                                <Radio label="merged">{{ $L('合并视图') }}</Radio>
                            </RadioGroup>
                        </FormItem>

                        <FormItem :label="$L('时间范围')">
                            <DatePicker
                                type="daterange"
                                :value="dateRange"
                                format="yyyy-MM-dd"
                                value-format="yyyy-MM-dd"
                                :placeholder="$L('近 30 天')"
                                @on-change="onDateChange"/>
                        </FormItem>

                        <FormItem :label="$L('项目过滤')">
                            <Select
                                v-model="filterProjectIds"
                                multiple
                                :placeholder="$L('全部项目')"
                                @on-change="onChangeFilters">
                                <Option v-for="proj in projectList" :key="proj.id" :value="proj.id">
                                    {{ proj.name }}
                                </Option>
                            </Select>
                        </FormItem>

                        <Button type="primary" long :loading="loading" @click="loadData">
                            {{ $L('查询') }}
                        </Button>
                    </Form>
                </Card>
            </Col>

            <!-- 右侧图表 -->
            <Col span="18">
                <Card :title="$L('数据可视化')" :bordered="false">
                    <div v-if="loading" class="loading-wrap">
                        <Spin size="large"/>
                    </div>

                    <div v-else>
                        <Alert v-if="warning" type="warning" show-icon class="warning-alert">
                            {{ warning }}
                        </Alert>

                        <div v-if="rows.length === 0" class="empty-wrap">
                            {{ $L('暂无数据') }}
                        </div>
                        <div v-else>
                            <div class="chart-type-bar">
                                <RadioGroup v-model="chartType" type="button" @on-change="renderChart">
                                    <Radio label="auto">{{ $L('自动') }}</Radio>
                                    <Radio label="line">{{ $L('折线') }}</Radio>
                                    <Radio label="bar">{{ $L('柱状') }}</Radio>
                                    <Radio label="pie">{{ $L('饼图') }}</Radio>
                                </RadioGroup>
                            </div>
                            <div ref="chartContainer" class="chart-container"></div>
                        </div>
                    </div>
                </Card>
            </Col>
        </Row>
    </div>
</template>

<script>
// [CUSTOM:report-channel] Sprint 9 Pass 1
import * as echarts from 'echarts';

export default {
    name: 'ReportDashboard',
    data() {
        return {
            // 筛选
            metric: 'count',
            // v3.22: 默认按汇报人拆分
            dimensions: ['reporter_userid'],
            splitMode: 'by_user',
            dateRange: [],          // [from, to] yyyy-mm-dd
            filterProjectIds: [],

            // 数据
            loading: false,
            rows: [],
            warning: '',
            chartType: 'auto',      // auto / line / bar / pie

            // 辅助
            aggregableFields: [],
            projectList: [],

            // ECharts 实例
            chart: null,
        };
    },
    mounted() {
        this.loadAggregableFields();
        this.loadProjects();
        this.loadData();
    },
    beforeDestroy() {
        if (this.chart) {
            this.chart.dispose();
            this.chart = null;
        }
    },
    methods: {
        onSplitModeChange() {
            // splitMode → dimensions 联动
            if (this.splitMode === 'by_user') {
                this.dimensions = ['reporter_userid'];
            } else {
                this.dimensions = ['time'];
            }
            this.loadData();
        },

        onChangeFilters() {
            // 仅切换状态，由"查询"按钮统一触发；指标 / 维度变化也在用户主动触发
        },

        onDateChange(val) {
            this.dateRange = Array.isArray(val) ? val : [];
            this.loadData();
        },

        async loadAggregableFields() {
            try {
                const resp = await this.$store.dispatch('call', {
                    url: 'project/report_field/list',
                    data: {include_disabled: false},
                });
                // report_field/list 返回: data: [...] (数组)
                const fields = (resp && Array.isArray(resp.data)) ? resp.data : [];
                this.aggregableFields = fields.filter(f => f && f.aggregatable);
            } catch (err) {
                this.aggregableFields = [];
            }
        },

        async loadProjects() {
            try {
                const resp = await this.$store.dispatch('call', {
                    url: 'project/lists',
                    data: {archived: 'no', getstatistics: 'no'},
                });
                // project/lists 返回分页: data: { data: [...], current_page, total, ... }
                this.projectList = (resp && resp.data && resp.data.data) || [];
            } catch (err) {
                this.projectList = [];
            }
        },

        async loadData() {
            // 维度兜底: 至少保留 1 个
            if (!Array.isArray(this.dimensions) || this.dimensions.length === 0) {
                this.dimensions = ['time'];
            }

            this.loading = true;
            this.warning = '';
            try {
                const filters = {};
                if (Array.isArray(this.dateRange) && this.dateRange.length === 2
                    && this.dateRange[0] && this.dateRange[1]) {
                    filters.date_range = this.dateRange;
                }
                if (Array.isArray(this.filterProjectIds) && this.filterProjectIds.length > 0) {
                    filters.project_ids = this.filterProjectIds;
                }

                const resp = await this.$store.dispatch('call', {
                    url: 'project/report_dashboard/data',
                    data: {
                        dimensions: this.dimensions,
                        metric: this.metric,
                        filters,
                        top_n: 50,
                    },
                });

                // report_dashboard/data 返回: data: { rows, warning }
                const payload = (resp && resp.data) || {};
                this.rows = Array.isArray(payload.rows) ? payload.rows : [];
                this.warning = payload.warning || '';

                this.$nextTick(() => this.renderChart());
            } catch (err) {
                this.$Message.error((err && err.msg) || this.$L('查询失败'));
                this.rows = [];
            } finally {
                this.loading = false;
            }
        },

        autoChartType() {
            // 自动选型: 仅 time → line; 2 dim → bar; 其他 → pie
            if (this.dimensions.length === 1 && this.dimensions[0] === 'time') return 'line';
            if (this.dimensions.length === 2) return 'bar';
            return 'pie';
        },

        renderChart() {
            if (!this.$refs.chartContainer) return;

            if (this.rows.length === 0) {
                if (this.chart) {
                    this.chart.dispose();
                    this.chart = null;
                }
                return;
            }

            if (!this.chart) {
                this.chart = echarts.init(this.$refs.chartContainer);
            }

            const type = this.chartType === 'auto' ? this.autoChartType() : this.chartType;
            const option = this.buildChartOption(type);
            this.chart.setOption(option, true);
        },

        buildChartOption(type) {
            const dim0 = this.dimensions[0];
            const dim1 = this.dimensions[1];
            const dim0Key = this.aliasKey(dim0);
            const dim1Key = dim1 ? this.aliasKey(dim1) : null;

            if (type === 'pie') {
                return {
                    tooltip: {trigger: 'item'},
                    legend: {type: 'scroll', orient: 'vertical', right: 10},
                    series: [{
                        type: 'pie',
                        radius: '60%',
                        data: this.rows.map(r => ({
                            name: String(r[dim0Key] == null ? '-' : r[dim0Key]),
                            value: Number(r.metric_value || 0),
                        })),
                        emphasis: {
                            itemStyle: {
                                shadowBlur: 10,
                                shadowOffsetX: 0,
                                shadowColor: 'rgba(0,0,0,0.5)',
                            },
                        },
                    }],
                };
            }

            // bar / line
            if (dim1Key) {
                // 2 dim: dim0 作 x, dim1 作 series
                const xVals = [...new Set(this.rows.map(r => String(r[dim0Key] == null ? '-' : r[dim0Key])))];
                const seriesGroups = {};
                this.rows.forEach(r => {
                    const sKey = String(r[dim1Key] == null ? '-' : r[dim1Key]);
                    const xKey = String(r[dim0Key] == null ? '-' : r[dim0Key]);
                    if (!seriesGroups[sKey]) seriesGroups[sKey] = {};
                    seriesGroups[sKey][xKey] = Number(r.metric_value || 0);
                });
                return {
                    tooltip: {trigger: 'axis'},
                    legend: {type: 'scroll', top: 10},
                    xAxis: {type: 'category', data: xVals},
                    yAxis: {type: 'value'},
                    series: Object.keys(seriesGroups).map(sKey => ({
                        name: sKey,
                        type,
                        data: xVals.map(x => seriesGroups[sKey][x] || 0),
                    })),
                };
            }

            // 1 dim
            return {
                tooltip: {trigger: 'axis'},
                xAxis: {
                    type: 'category',
                    data: this.rows.map(r => String(r[dim0Key] == null ? '-' : r[dim0Key])),
                },
                yAxis: {type: 'value'},
                series: [{
                    name: this.metric,
                    type,
                    data: this.rows.map(r => Number(r.metric_value || 0)),
                }],
            };
        },

        aliasKey(dim) {
            // v3.23/v1.12 双别名: 与后端 StatisticsService::dimColMap 对齐
            // 'time'/'day' → SQL 别名 day; 其他直接用列名
            const map = {
                time: 'day',
                day: 'day',
                user: 'reporter_userid',
                reporter_userid: 'reporter_userid',
                project: 'project_id',
                project_id: 'project_id',
                task: 'task_id',
                task_id: 'task_id',
                template: 'template_id',
                template_id: 'template_id',
            };
            return map[dim] || dim;
        },
    },
};
</script>

<style lang="scss" scoped>
.report-dashboard {
    padding: 16px;

    .dashboard-content {
        margin-top: 16px;
    }

    .loading-wrap {
        text-align: center;
        padding: 40px;
    }

    .empty-wrap {
        text-align: center;
        padding: 40px;
        color: #999;
    }

    .warning-alert {
        margin-bottom: 12px;
    }

    .chart-type-bar {
        margin-bottom: 12px;
    }

    .chart-container {
        width: 100%;
        height: 480px;
    }
}
</style>
