<template>
    <div class="options-editor-attachment">
        <!-- [CUSTOM:report-channel] Sprint 4 Task 4.6 -->
        <FormItem :label="$L('最多文件数')">
            <InputNumber v-model="local.max_count" :min="1" :placeholder="$L('不限')"/>
        </FormItem>
        <FormItem :label="$L('文件类型')">
            <Input v-model="local.accept" :placeholder="$L('如: .jpg,.png,.pdf 留空不限')"/>
        </FormItem>
        <FormItem :label="$L('单文件最大 KB')">
            <InputNumber v-model="local.max_size_kb" :min="1" :placeholder="$L('默认 10240 KB (10 MB)')"/>
        </FormItem>
    </div>
</template>

<script>
// [CUSTOM:report-channel] Sprint 4 Task 4.6
// 字段配置编辑器：type=attachment。配置 {max_count, accept, max_size_kb}。
export default {
    name: 'OptionsEditorAttachment',
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
