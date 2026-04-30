<template>
    <div class="options-editor-text">
        <!-- [CUSTOM:report-channel] Sprint 4 Task 4.3 -->
        <FormItem :label="$L('最大字符数')">
            <InputNumber v-model="local.max_length" :min="1" :placeholder="$L('不限')"/>
        </FormItem>
    </div>
</template>

<script>
// [CUSTOM:report-channel] Sprint 4 Task 4.3
// 字段配置编辑器：type=text / textarea。仅 max_length 一项配置。
export default {
    name: 'OptionsEditorText',
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
