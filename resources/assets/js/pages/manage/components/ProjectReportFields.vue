<template>
    <!-- [CUSTOM:report-channel] Sprint 4 Pass 1 · Task 4.2 -->
    <div class="project-report-fields">
        <div class="header">
            <div class="title">
                {{$L('项目上报字段')}}
                <Loading v-if="loading"/>
            </div>
            <div class="actions">
                <Button type="primary" icon="md-add" @click="openEditor()">{{$L('新建字段')}}</Button>
            </div>
        </div>
        <div class="content">
            <Table
                :columns="columns"
                :data="fields"
                :loading="loading"
                :no-data-text="$L('暂无项目级字段，可点击右上角 [新建字段] 创建')"
                size="small"
                stripe/>
        </div>
        <Modal
            v-model="editorVisible"
            :title="editorTitle"
            :loading="saving"
            :mask-closable="false"
            @on-ok="saveField">
            <Form ref="formRef" :model="form" :label-width="100" @submit.native.prevent>
                <FormItem :label="$L('字段标识')" required>
                    <Input v-model="form.code" :disabled="form.id > 0" :placeholder="$L('字母/数字/下划线')"/>
                </FormItem>
                <FormItem :label="$L('字段名称')" required>
                    <Input v-model="form.name" :placeholder="$L('显示名称')"/>
                </FormItem>
                <FormItem :label="$L('字段类型')" required>
                    <Select v-model="form.type" :disabled="form.id > 0">
                        <Option value="text">{{$L('单行文本')}}</Option>
                        <Option value="textarea">{{$L('多行文本')}}</Option>
                        <Option value="number">{{$L('数字')}}</Option>
                        <Option value="date">{{$L('日期')}}</Option>
                        <Option value="select">{{$L('单选')}}</Option>
                        <Option value="multi_select">{{$L('多选')}}</Option>
                        <Option value="attachment">{{$L('附件')}}</Option>
                        <Option value="json">{{$L('JSON')}}</Option>
                    </Select>
                </FormItem>
                <FormItem :label="$L('排序')">
                    <InputNumber v-model="form.sort" :min="0"/>
                </FormItem>
                <FormItem :label="$L('必填')">
                    <i-switch v-model="form.required"/>
                </FormItem>
                <FormItem :label="$L('启用')">
                    <i-switch v-model="form.enabled"/>
                </FormItem>
                <FormItem v-if="form.type" :label="$L('类型配置')">
                    <OptionsEditorText
                        v-if="form.type === 'text' || form.type === 'textarea'"
                        v-model="form.options"/>
                    <OptionsEditorNumber
                        v-else-if="form.type === 'number'"
                        v-model="form.options"/>
                    <OptionsEditorSelect
                        v-else-if="form.type === 'select' || form.type === 'multi_select'"
                        v-model="form.options"
                        :multiple="form.type === 'multi_select'"/>
                    <OptionsEditorAttachment
                        v-else-if="form.type === 'attachment'"
                        v-model="form.options"/>
                    <p v-else-if="form.type === 'date'" class="type-config-tip">{{$L('日期类型无额外配置')}}</p>
                    <p v-else-if="form.type === 'json'" class="type-config-tip">{{$L('JSON 类型无额外配置')}}</p>
                </FormItem>
                <FormItem v-if="form.type" :label="$L('聚合配置')">
                    <OptionsEditorAggregate
                        :value="aggregateValue"
                        :field-type="form.type"
                        :field-id="form.id"
                        @input="onAggregateInput"/>
                </FormItem>
            </Form>
        </Modal>
    </div>
</template>

<script>
// [CUSTOM:report-channel] Sprint 4 Pass 1 · Task 4.2 + Pass 2 接线
// 项目级（scope=project）上报字段管理。仅项目负责人可见（ProjectPanel dropdown 已限定 owner-only menu）。
import OptionsEditorText from "../../../components/report/OptionsEditorText";
import OptionsEditorNumber from "../../../components/report/OptionsEditorNumber";
import OptionsEditorSelect from "../../../components/report/OptionsEditorSelect";
import OptionsEditorAttachment from "../../../components/report/OptionsEditorAttachment";
import OptionsEditorAggregate from "../../../components/report/OptionsEditorAggregate";

export default {
    name: 'ProjectReportFields',
    components: {
        OptionsEditorText,
        OptionsEditorNumber,
        OptionsEditorSelect,
        OptionsEditorAttachment,
        OptionsEditorAggregate,
    },
    props: {
        projectId: {
            type: Number,
            required: true,
        },
    },
    data() {
        return {
            loading: false,
            fields: [],
            editorVisible: false,
            editorTitle: '',
            saving: false,
            form: this.emptyForm(),
            columns: [
                { title: this.$L('标识'), key: 'code', width: 150 },
                { title: this.$L('名称'), key: 'name', minWidth: 120 },
                { title: this.$L('类型'), key: 'type', width: 110 },
                {
                    title: this.$L('必填'),
                    key: 'required',
                    width: 70,
                    render: (h, p) => h('span', p.row.required ? this.$L('是') : this.$L('否')),
                },
                {
                    title: this.$L('启用'),
                    key: 'enabled',
                    width: 70,
                    render: (h, p) => h('span', p.row.enabled ? this.$L('是') : this.$L('否')),
                },
                { title: this.$L('排序'), key: 'sort', width: 70 },
                {
                    title: this.$L('操作'),
                    width: 140,
                    render: (h, p) => h('div', [
                        h('Button', {
                            props: { type: 'text', size: 'small' },
                            on: { click: () => this.openEditor(p.row) },
                        }, this.$L('编辑')),
                        h('Button', {
                            props: { type: 'text', size: 'small' },
                            style: { color: '#f40' },
                            on: { click: () => this.deleteField(p.row) },
                        }, this.$L('删除')),
                    ]),
                },
            ],
        };
    },
    mounted() {
        this.loadFields();
    },
    computed: {
        // [CUSTOM:report-channel] Sprint 4 Pass 2 桥接：Aggregate 三字段映射 form 顶层属性
        aggregateValue() {
            return {
                aggregatable: !!this.form.aggregatable,
                aggregate_strategy: this.form.aggregate_strategy || 'none',
                has_index: !!this.form.has_index,
            };
        },
    },
    methods: {
        emptyForm() {
            return {
                id: 0,
                scope: 'project',
                project_id: this.projectId,
                code: '',
                name: '',
                type: 'text',
                options: {},
                required: false,
                sort: 0,
                enabled: true,
                aggregatable: false,
                aggregate_strategy: 'none',
                has_index: false,
            };
        },
        onAggregateInput(v) {
            // [CUSTOM:report-channel] Sprint 4 Pass 2 桥接回写
            this.form.aggregatable = v.aggregatable;
            this.form.aggregate_strategy = v.aggregate_strategy;
            this.form.has_index = v.has_index;
        },
        loadFields() {
            this.loading = true;
            this.$store.dispatch('call', {
                url: 'project/report_field/list',
                method: 'post',
                data: {
                    scope: 'project',
                    project_id: this.projectId,
                    include_disabled: true,
                },
            }).then(({data}) => {
                this.fields = Array.isArray(data) ? data : (data?.rows || []);
            }).catch(({msg}) => {
                $A.modalError(msg || this.$L('加载失败'));
            }).finally(() => {
                this.loading = false;
            });
        },
        openEditor(field) {
            if (field) {
                this.form = Object.assign(this.emptyForm(), $A.cloneJSON(field));
                this.editorTitle = this.$L('编辑字段');
            } else {
                this.form = this.emptyForm();
                this.editorTitle = this.$L('新建字段');
            }
            this.editorVisible = true;
        },
        saveField() {
            if (!this.form.code || !this.form.name) {
                $A.modalError(this.$L('字段标识和名称必填'));
                this.editorVisible = true;
                return;
            }
            // 始终携带 scope/project_id，后端按此识别为项目级字段
            this.form.scope = 'project';
            this.form.project_id = this.projectId;
            this.saving = true;
            this.$store.dispatch('call', {
                url: 'project/report_field/save',
                method: 'post',
                data: this.form,
            }).then(() => {
                $A.messageSuccess(this.$L('保存成功'));
                this.editorVisible = false;
                this.loadFields();
            }).catch(({msg}) => {
                $A.modalError(msg || this.$L('保存失败'));
                this.editorVisible = true;
            }).finally(() => {
                this.saving = false;
            });
        },
        deleteField(field) {
            $A.modalConfirm({
                title: this.$L('确认删除'),
                content: this.$L('确定删除字段') + ' "' + field.name + '"?',
                onOk: () => {
                    this.$store.dispatch('call', {
                        url: 'project/report_field/delete',
                        method: 'post',
                        data: { id: field.id },
                    }).then(() => {
                        $A.messageSuccess(this.$L('删除成功'));
                        this.loadFields();
                    }).catch(({msg}) => {
                        $A.modalError(msg || this.$L('删除失败'));
                    });
                },
            });
        },
    },
};
</script>

<style lang="scss" scoped>
.project-report-fields {
    height: 100%;
    display: flex;
    flex-direction: column;
    padding: 20px;
    box-sizing: border-box;
    .header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
        .title {
            font-size: 18px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }
    }
    .content {
        flex: 1;
        overflow: auto;
    }
    .type-config-tip {
        color: #999;
        margin: 0;
    }
}
</style>
