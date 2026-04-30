<!-- [CUSTOM:report-channel] Sprint 3 Pass 1 Task 3.2
     Modal report dialog with dual entry path: open(task, options) + emitter('reportTriggerModal').
     Spec: docs/plans/2026-04-25-task-report-channel-design.md §11.3 / §21.3.2 / §11.5.1

     Entry paths:
       1. modal mode: caller dispatches open(task, { onSubmit }) — onSubmit handles save序列
       2. block mode: server returns 422 with template_block — axios catch emits
          'reportTriggerModal' with { task, fields, _retryAction, _hint } → handleTriggerEmit

     Sprint 3 limitation: report_field/list endpoint is Sprint 4. fetchFields falls back to
     hardcoded hours/note builtin (matches Sprint 1 seeded fields).
-->
<template>
    <Modal
        v-model="visible"
        :title="title"
        :loading="submitting"
        :mask-closable="false"
        @on-ok="handleSubmit">
        <p v-if="hint" class="report-dialog-hint">{{ hint }}</p>
        <Form
            ref="form"
            :model="form"
            :label-width="100"
            v-bind="formOptions"
            @submit.native.prevent>
            <DynamicField
                v-for="field in effectiveFields"
                :key="field.code"
                :field="field"
                :project-id="projectId"
                :report-id="form.id"
                v-model="form.values[field.code]"/>
        </Form>
        <div slot="footer" class="adaption">
            <Button @click="handleSkip">{{ $L('跳过') }}</Button>
            <Button type="primary" :loading="submitting" @click="handleSubmit">
                {{ $L('确认') }}
            </Button>
        </div>
    </Modal>
</template>

<script>
import { mapState } from 'vuex';
import emitter from '../../store/events';
import DynamicField from './DynamicField.vue';

export default {
    name: 'ReportDialog',
    components: { DynamicField },
    data() {
        return {
            visible: false,
            submitting: false,
            task: null,
            hint: '',
            form: {
                id: 0,
                task_id: 0,
                work_date: '',
                values: {},
            },
            defaultFields: [],
            overrideFields: null,
            onSubmitCallback: null,
            retryAction: null,
        };
    },
    computed: {
        ...mapState(['formOptions']),
        title() {
            return this.task && this.task.name
                ? this.$L('上报') + ' - ' + this.task.name
                : this.$L('上报');
        },
        projectId() {
            return (this.task && this.task.project_id) || 0;
        },
        // block mode prefers server-pushed override; modal mode uses fetched defaults
        effectiveFields() {
            return this.overrideFields || this.defaultFields;
        },
    },
    mounted() {
        emitter.on('reportTriggerModal', this.handleTriggerEmit);
    },
    beforeDestroy() {
        emitter.off('reportTriggerModal', this.handleTriggerEmit);
    },
    methods: {
        /**
         * Active entry — spec §11.3 open(task, options?)
         * @param {Object} task     ProjectTask object (must have id, project_id)
         * @param {Object} options  { onSubmit?, fields?, _retryAction?, _hint? }
         */
        async open(task, options) {
            options = options || {};
            this.task = task || {};
            this.hint = options._hint || '';
            this.onSubmitCallback = options.onSubmit || null;
            this.retryAction = options._retryAction || null;
            this.overrideFields = options.fields || null;

            // reset form
            this.form = {
                id: 0,
                task_id: this.task.id || 0,
                work_date: this.computeDefaultWorkDate(this.task),
                values: {},
            };

            // fetch default fields if no override (Sprint 4 endpoint, falls back if 404)
            if (!this.overrideFields && this.task.project_id) {
                await this.fetchFields(this.task.project_id);
            } else if (!this.overrideFields) {
                this.defaultFields = this.builtinFallbackFields();
            }

            // seed default values
            this.effectiveFields.forEach(f => {
                this.$set(this.form.values, f.code, this.normalizeDefault(f));
            });

            this.visible = true;
        },

        /**
         * Passive entry — emitter callback for block mode (axios catch)
         * Payload: { task?, fields, _retryAction?, _hint? }
         */
        handleTriggerEmit(payload) {
            payload = payload || {};
            this.open(payload.task || this.task || {}, {
                fields: payload.fields,
                _retryAction: payload._retryAction,
                _hint: payload._hint,
            });
        },

        async fetchFields(projectId) {
            try {
                const resp = await this.$store.dispatch('call', {
                    url: 'project/report_field/list',
                    method: 'post',
                    data: { project_id: projectId },
                });
                this.defaultFields =
                    (resp && (resp.data?.rows || resp.data)) || [];
                if (!Array.isArray(this.defaultFields) || this.defaultFields.length === 0) {
                    this.defaultFields = this.builtinFallbackFields();
                }
            } catch (err) {
                // Sprint 3 graceful fallback — endpoint未上线
                this.defaultFields = this.builtinFallbackFields();
            }
        },

        // Sprint 3 fallback: matches Sprint 1 seeded builtin fields (hours / note)
        builtinFallbackFields() {
            return [
                {
                    code: 'hours',
                    name: '工时',
                    type: 'number',
                    required: true,
                    options: { min: 0, max: 24, step: 0.5 },
                    default_value: null,
                },
                {
                    code: 'note',
                    name: '备注',
                    type: 'textarea',
                    required: false,
                    options: { max_length: 2000 },
                    default_value: null,
                },
            ];
        },

        // §13.1 work_date 默认值规则
        computeDefaultWorkDate(task) {
            try {
                const day = (window.$A && window.$A.daytz) ? window.$A.daytz : null;
                if (task && task.complete_at && day) {
                    return day(task.complete_at).format('YYYY-MM-DD');
                }
                return day ? day().format('YYYY-MM-DD') : '';
            } catch (e) {
                return '';
            }
        },

        normalizeDefault(field) {
            if (field.default_value !== undefined && field.default_value !== null) {
                return field.default_value;
            }
            if (field.type === 'multi_select' || field.type === 'attachment' || field.type === 'user') {
                return [];
            }
            return null;
        },

        handleSkip() {
            this.visible = false;
        },

        async handleSubmit() {
            if (this.submitting) return;
            this.submitting = true;
            try {
                if (this.onSubmitCallback) {
                    // §11.5.1 modal: caller-provided submit (e.g., report/save → task/save serial)
                    await this.onSubmitCallback(this.form);
                } else {
                    // block mode default: report/save (Task 3.4 backend already live)
                    await this.$store.dispatch('call', {
                        url: 'project/report/save',
                        method: 'post',
                        data: this.form,
                    });
                }
                this.visible = false;
                this.$Message.success(this.$L('上报成功'));
                this.$emit('saved', this.form);

                // §21.3.2: block mode retry the original dispatch after save
                if (this.retryAction) {
                    try {
                        await this.retryAction();
                    } catch (e) {
                        // retry failure surfaces independently — caller decides
                    }
                }
            } catch (err) {
                // Backend may attach errors[] array (missing/invalid kinds)
                const errors = (err && err.data && err.data.errors) || [];
                if (errors.length) {
                    this.$Message.error(errors.map(x => x.reason).join(' / '));
                } else {
                    this.$Message.error((err && err.msg) || this.$L('上报失败'));
                }
            } finally {
                this.submitting = false;
            }
        },
    },
};
</script>

<style lang="scss" scoped>
.report-dialog-hint {
    color: #ff9900;
    margin-bottom: 12px;
    line-height: 1.5;
}
</style>
