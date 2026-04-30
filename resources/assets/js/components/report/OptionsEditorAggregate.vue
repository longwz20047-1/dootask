<template>
    <div class="options-editor-aggregate">
        <!-- [CUSTOM:report-channel] Sprint 4 Task 4.7 (v3.18 第 9 sub-form) -->
        <template v-if="aggregatableSupported">
            <FormItem :label="$L('参与聚合')">
                <i-switch v-model="local.aggregatable"/>
                <p class="text-muted">{{$L('启用后字段可在仪表盘做聚合统计')}}</p>
            </FormItem>
            <FormItem v-if="local.aggregatable" :label="$L('聚合策略')">
                <Select v-model="local.aggregate_strategy">
                    <Option value="sum">{{$L('求和 SUM')}}</Option>
                    <Option value="avg">{{$L('平均 AVG')}}</Option>
                    <Option value="count">{{$L('计数 COUNT')}}</Option>
                    <Option value="distinct">{{$L('去重 COUNT DISTINCT')}}</Option>
                    <Option value="none">{{$L('不聚合')}}</Option>
                </Select>
            </FormItem>
            <FormItem v-if="local.aggregatable && fieldId > 0" :label="$L('聚合索引')">
                <Tag v-if="local.has_index" color="success">{{$L('已建索引')}}</Tag>
                <Tag v-else color="warning">{{$L('未建索引（性能差）')}}</Tag>
                <Button
                    v-if="!local.has_index"
                    type="primary"
                    size="small"
                    :loading="building"
                    style="margin-left: 8px;"
                    @click="enableIndex">
                    {{$L('建立索引')}}
                </Button>
            </FormItem>
        </template>
        <template v-else>
            <p class="text-muted">{{$L('当前字段类型不支持聚合（仅 number/date 支持）')}}</p>
        </template>
    </div>
</template>

<script>
// [CUSTOM:report-channel] Sprint 4 Task 4.7 (v3.18 第 9 sub-form)
// 字段聚合配置编辑器。映射 form 顶层 {aggregatable, aggregate_strategy, has_index}。
// 仅 type=number / type=date 支持聚合，其他类型显示提示。
// build_index 端点 Sprint 7-B 才实施 → enableIndex 失败时降级提示。
export default {
    name: 'OptionsEditorAggregate',
    props: {
        value: { type: Object, default: () => ({}) },
        fieldType: { type: String, default: '' },
        fieldId: { type: Number, default: 0 },
    },
    data() {
        return {
            local: this.normalize(this.value),
            building: false,
        };
    },
    computed: {
        aggregatableSupported() {
            return ['number', 'date'].includes(this.fieldType);
        },
    },
    watch: {
        value(v) { this.local = this.normalize(v); },
        local: {
            deep: true,
            handler(v) { this.$emit('input', v); },
        },
    },
    methods: {
        normalize(v) {
            return Object.assign({
                aggregatable: false,
                aggregate_strategy: 'none',
                has_index: false,
            }, v || {});
        },
        enableIndex() {
            if (!this.fieldId) {
                $A.messageWarning(this.$L('请先保存字段'));
                return;
            }
            this.building = true;
            this.$store.dispatch('call', {
                url: 'project/report_field/build_index',
                method: 'post',
                data: { id: this.fieldId, op: 'enable' },
            }).then(() => {
                $A.messageSuccess(this.$L('索引建立任务已提交，稍候刷新查看'));
                this.local.has_index = true;
            }).catch(({msg}) => {
                // Sprint 7-B 才实施 build_index 端点，当前 404 → 提示
                $A.messageWarning(msg || this.$L('索引建立功能将在 Sprint 7-B 上线'));
            }).finally(() => {
                this.building = false;
            });
        },
    },
};
</script>

<style lang="scss" scoped>
.options-editor-aggregate {
    .text-muted {
        color: #999;
        margin: 0;
        font-size: 12px;
    }
}
</style>
