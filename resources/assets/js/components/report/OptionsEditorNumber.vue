<template>
    <div class="options-editor-number">
        <!-- [CUSTOM:report-channel] Sprint 4 Task 4.4 -->
        <Row :gutter="12">
            <Col span="12">
                <FormItem :label="$L('最小值')">
                    <InputNumber v-model="local.min" :placeholder="$L('不限')"/>
                </FormItem>
            </Col>
            <Col span="12">
                <FormItem :label="$L('最大值')">
                    <InputNumber v-model="local.max" :placeholder="$L('不限')"/>
                </FormItem>
            </Col>
        </Row>
        <Row :gutter="12">
            <Col span="12">
                <FormItem :label="$L('步长')">
                    <InputNumber v-model="local.step" :min="0.01" :step="0.5" :placeholder="$L('1')"/>
                </FormItem>
            </Col>
            <Col span="12">
                <FormItem :label="$L('单位')">
                    <Input v-model="local.unit_key" :placeholder="$L('如: h, %, 个')"/>
                </FormItem>
            </Col>
        </Row>
        <p class="text-muted">{{$L('hours 字段范例: min=0, max=24, step=0.5, unit_key=h')}}</p>
    </div>
</template>

<script>
// [CUSTOM:report-channel] Sprint 4 Task 4.4
// 字段配置编辑器：type=number。配置 {min, max, step, unit_key}。
export default {
    name: 'OptionsEditorNumber',
    props: {
        value: { type: Object, default: () => ({}) },
    },
    data() {
        return { local: { ...this.value } };
    },
    watch: {
        value(v) { this.local = { ...v }; },
        local: {
            deep: true,
            handler(v) { this.$emit('input', v); },
        },
    },
};
</script>

<style lang="scss" scoped>
.options-editor-number {
    .text-muted {
        color: #999;
        margin: 0;
        font-size: 12px;
    }
}
</style>
