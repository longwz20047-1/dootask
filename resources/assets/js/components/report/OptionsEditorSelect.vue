<template>
    <div class="options-editor-select">
        <!-- [CUSTOM:report-channel] Sprint 4 Task 4.5 -->
        <FormItem :label="$L('选项列表')">
            <div v-for="(opt, idx) in local.options" :key="idx" class="option-row">
                <Row :gutter="8">
                    <Col span="10">
                        <Input v-model="opt.value" :placeholder="$L('值')"/>
                    </Col>
                    <Col span="10">
                        <Input v-model="opt.label_key" :placeholder="$L('显示文案')"/>
                    </Col>
                    <Col span="4">
                        <Button type="text" @click="removeOption(idx)">{{$L('删除')}}</Button>
                    </Col>
                </Row>
            </div>
            <Button @click="addOption">{{$L('添加选项')}}</Button>
        </FormItem>
        <FormItem v-if="multiple" :label="$L('最大选择数')">
            <InputNumber v-model="local.max_count" :min="1" :placeholder="$L('不限')"/>
        </FormItem>
    </div>
</template>

<script>
// [CUSTOM:report-channel] Sprint 4 Task 4.5
// 字段配置编辑器：type=select / multi_select。配置 {options:[{value,label_key},...], max_count}。
// multiple=true 时为 multi_select，多一项 max_count 配置。
export default {
    name: 'OptionsEditorSelect',
    props: {
        value: { type: Object, default: () => ({}) },
        multiple: { type: Boolean, default: false },
    },
    data() {
        return { local: this.normalize(this.value) };
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
            const out = { ...v };
            if (!Array.isArray(out.options)) out.options = [];
            return out;
        },
        addOption() {
            this.local.options.push({ value: '', label_key: '' });
        },
        removeOption(idx) {
            this.local.options.splice(idx, 1);
        },
    },
};
</script>

<style lang="scss" scoped>
.options-editor-select {
    .option-row {
        margin-bottom: 6px;
    }
}
</style>
