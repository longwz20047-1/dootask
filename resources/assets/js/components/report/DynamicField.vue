<!-- [CUSTOM:report-channel] Sprint 3 Pass 1 Task 3.1
     8-type dynamic renderer for task report fields.
     Spec: docs/plans/2026-04-25-task-report-channel-design.md §11.2
     Conventions:
       - view-design-hi 4.7 components (Input/InputNumber/Select/DatePicker/Upload/FormItem)
       - DatePicker MUST set value-format="yyyy-MM-dd" (avoids ISO Date object)
       - Upload uses :on-success callback (no v-model — TEditor.vue:25 / ImgUpload.vue:24 same)
       - i18n: $L('xxx') wraps every user-visible string
-->
<template>
    <FormItem :label="$L(field.name)" :prop="field.code" :rules="rules">
        <!-- text -->
        <Input
            v-if="field.type === 'text'"
            v-model="localValue"
            :maxlength="textMaxLength"
            :placeholder="$L('请输入')"/>

        <!-- textarea -->
        <Input
            v-else-if="field.type === 'textarea'"
            type="textarea"
            v-model="localValue"
            :maxlength="textareaMaxLength"
            :rows="4"
            :placeholder="$L('请输入')"/>

        <!-- number -->
        <InputNumber
            v-else-if="field.type === 'number'"
            v-model="localValue"
            :min="numberMin"
            :max="numberMax"
            :step="numberStep"
            :placeholder="$L('请输入数字')"/>

        <!-- date · v3.2 P0-4: must use value-format to get YYYY-MM-DD string -->
        <DatePicker
            v-else-if="field.type === 'date'"
            v-model="localValue"
            type="date"
            format="yyyy-MM-dd"
            value-format="yyyy-MM-dd"
            :placeholder="$L('请选择日期')"/>

        <!-- select · v3.2 P1-6: option.label_key wrapped in $L() -->
        <Select
            v-else-if="field.type === 'select'"
            v-model="localValue"
            :placeholder="$L('请选择')">
            <Option
                v-for="opt in selectOptions"
                :key="opt.value"
                :value="opt.value">
                {{ $L(opt.label_key || opt.label) }}
            </Option>
        </Select>

        <!-- multi_select -->
        <Select
            v-else-if="field.type === 'multi_select'"
            v-model="localValue"
            multiple
            :multiple-max="multiSelectMax"
            :placeholder="$L('请选择')">
            <Option
                v-for="opt in selectOptions"
                :key="opt.value"
                :value="opt.value">
                {{ $L(opt.label_key || opt.label) }}
            </Option>
        </Select>

        <!-- user (复用现有 UserSelect) -->
        <UserSelect
            v-else-if="field.type === 'user'"
            v-model="localValue"
            :multiple-max="userMultipleMax"
            :project-id="userProjectId"/>

        <!-- attachment · v3.2 P0-4: Upload via :on-success callback (no v-model)
             NOTE: report_attachment/upload endpoint is Sprint 5a Task 5a.4.
                   Sprint 3 seed fields (hours/note) do not use this type, so safe. -->
        <Upload
            v-else-if="field.type === 'attachment'"
            :action="uploadUrl"
            :data="uploadData"
            :headers="uploadHeaders"
            :format="acceptList"
            :max-size="maxSizeKb"
            multiple
            :on-success="onAttachmentSuccess"
            :on-format-error="onFormatError"
            :on-exceeded-size="onSizeExceeded"
            :on-error="onAttachmentError"
            :default-file-list="attachmentList">
            <Button icon="ios-cloud-upload-outline">{{ $L('上传文件') }}</Button>
        </Upload>

        <!-- json (textarea + parse-on-blur validation) -->
        <div v-else-if="field.type === 'json'" class="dynamic-field-json">
            <Input
                type="textarea"
                v-model="jsonStr"
                :rows="6"
                :placeholder="$L('请输入 JSON')"
                @on-blur="validateJson"/>
            <div v-if="jsonError" class="json-error">{{ jsonError }}</div>
        </div>
    </FormItem>
</template>

<script>
import UserSelect from '../UserSelect.vue';

export default {
    name: 'DynamicField',
    components: { UserSelect },
    props: {
        field: {
            type: Object,
            required: true,
        },
        value: {
            default: null,
        },
        projectId: {
            type: Number,
            default: 0,
        },
        reportId: {
            type: Number,
            default: 0,
        },
        // v3.2 P0-4: server-rendered attachment list for回显
        attachmentList: {
            type: Array,
            default: () => [],
        },
    },
    data() {
        return {
            jsonStr: this.serializeJson(this.value),
            jsonError: '',
        };
    },
    computed: {
        localValue: {
            get() {
                return this.value;
            },
            set(v) {
                this.$emit('input', v);
            },
        },

        // ---- text/textarea ----
        textMaxLength() {
            const opts = this.field.options || {};
            return opts.max_length || 100;
        },
        textareaMaxLength() {
            const opts = this.field.options || {};
            return opts.max_length || 500;
        },

        // ---- number ----
        numberMin() {
            const opts = this.field.options || {};
            return opts.min;
        },
        numberMax() {
            const opts = this.field.options || {};
            return opts.max;
        },
        numberStep() {
            const opts = this.field.options || {};
            return opts.step || 1;
        },

        // ---- select / multi_select ----
        // Spec accepts both options.options[] and options.items[] shapes; normalize.
        selectOptions() {
            const opts = this.field.options || {};
            if (Array.isArray(opts.options)) return opts.options;
            if (Array.isArray(opts.items)) return opts.items;
            return [];
        },
        multiSelectMax() {
            const opts = this.field.options || {};
            return opts.max_count || 0;
        },

        // ---- user ----
        userMultipleMax() {
            const opts = this.field.options || {};
            return opts.multiple_max || 0;
        },
        userProjectId() {
            const opts = this.field.options || {};
            return opts.scope_project ? this.projectId : 0;
        },

        // ---- attachment ----
        uploadUrl() {
            const baseUrl = this.$store.state.systemConfig?.baseUrl || '';
            return `${baseUrl}/api/project/report_attachment/upload`;
        },
        uploadData() {
            return {
                report_id: this.reportId,
                field_code: this.field.code,
            };
        },
        uploadHeaders() {
            // Base.php token() reads 'token' header (case-insensitive).
            return { Token: this.$store.state.userToken };
        },
        acceptList() {
            const opts = this.field.options || {};
            return (opts.accept || '')
                .split(',')
                .map(s => s.trim().replace(/^\./, ''))
                .filter(Boolean);
        },
        maxSizeKb() {
            // view-design-hi 4.7 Upload :max-size unit is KB.
            const opts = this.field.options || {};
            if (opts.max_size_kb) return opts.max_size_kb;
            if (opts.max_size_mb) return opts.max_size_mb * 1024;
            return 50 * 1024;
        },

        // ---- validation rules ----
        rules() {
            const r = [];
            if (this.field.required) {
                r.push({
                    required: true,
                    message: this.$L(this.field.name) + this.$L('必填'),
                    trigger: 'blur',
                });
            }
            return r;
        },
    },
    watch: {
        value(v) {
            if (this.field.type === 'json') {
                this.jsonStr = this.serializeJson(v);
            }
        },
    },
    methods: {
        serializeJson(v) {
            if (v === null || v === undefined || v === '') return '';
            try {
                return typeof v === 'string' ? v : JSON.stringify(v, null, 2);
            } catch (e) {
                return '';
            }
        },

        validateJson() {
            if (!this.jsonStr) {
                this.localValue = null;
                this.jsonError = '';
                return;
            }
            try {
                this.localValue = JSON.parse(this.jsonStr);
                this.jsonError = '';
            } catch (e) {
                this.jsonError = this.$L('JSON 格式错误') + ': ' + e.message;
            }
        },

        // v3.2 P0-4: collect attachment id into localValue array
        onAttachmentSuccess(response) {
            if (!response || response.ret !== 1) {
                this.$Message.error((response && response.msg) || this.$L('上传失败'));
                return;
            }
            const arr = Array.isArray(this.localValue) ? [...this.localValue] : [];
            const id = response.data && response.data.id;
            if (id) arr.push(id);
            this.localValue = arr;
        },
        onAttachmentError(error) {
            this.$Message.error((error && error.message) || this.$L('上传失败'));
        },
        onFormatError(file) {
            this.$Message.error(this.$L('文件格式不允许') + ': ' + file.name);
        },
        onSizeExceeded(file) {
            this.$Message.error(this.$L('文件超过大小限制') + ': ' + file.name);
        },
    },
};
</script>

<style lang="scss" scoped>
.dynamic-field-json {
    .json-error {
        color: #ed4014;
        font-size: 12px;
        margin-top: 4px;
        line-height: 1.5;
    }
}
</style>
