<template>
    <!-- [CUSTOM:report-channel] Sprint 8 Pass 1 · Task 8.1 -->
    <div class="setting-component-item system-report-templates">
        <div class="action-bar">
            <Button type="primary" icon="md-add" @click="openEditor()">{{$L('新建模板')}}</Button>
        </div>
        <Table
            :columns="columns"
            :data="templates"
            :loading="loading"
            size="small"
            stripe/>
        <!-- ReportTemplateEditor 完整组件（Sprint 8 Pass 2 替换 Pass 1 占位 Modal） -->
        <ReportTemplateEditor
            :visible.sync="editorVisible"
            :value="form"
            @saved="onTemplateSaved"/>
    </div>
</template>

<script>
// [CUSTOM:report-channel] Sprint 8 Pass 1 · Task 8.1（Pass 2 接入 ReportTemplateEditor）
// 系统级（scope=global）上报模板管理。仅管理员可见（system.vue 已限定 admin tab）。
import ReportTemplateEditor from "../../../../components/report/ReportTemplateEditor";

export default {
    name: 'SystemReportTemplates',
    components: { ReportTemplateEditor },
    data() {
        return {
            loading: false,
            templates: [],
            editorVisible: false,
            form: this.emptyForm(),
            columns: [
                {
                    title: this.$L('名称'),
                    key: 'name',
                    minWidth: 180,
                    render: (h, p) => {
                        const tags = [];
                        if (p.row.is_builtin) {
                            tags.push(h('Tag', { props: { color: 'primary', size: 'small' } }, this.$L('内建')));
                        }
                        if (p.row.is_default) {
                            tags.push(h('Tag', { props: { color: 'success', size: 'small' } }, this.$L('默认')));
                        }
                        tags.push(h('span', { style: { marginLeft: tags.length > 0 ? '4px' : '0' } }, p.row.name));
                        return h('div', tags);
                    },
                },
                { title: this.$L('范围'), key: 'scope', width: 90 },
                {
                    title: this.$L('启用'),
                    key: 'enabled',
                    width: 70,
                    render: (h, p) => h('span', p.row.enabled ? this.$L('是') : this.$L('否')),
                },
                {
                    title: this.$L('触发规则数'),
                    width: 100,
                    render: (h, p) => h('span', String((p.row.trigger_rules || []).length)),
                },
                { title: this.$L('描述'), key: 'description', ellipsis: true, tooltip: true, minWidth: 160 },
                {
                    title: this.$L('操作'),
                    width: 200,
                    render: (h, p) => h('div', [
                        h('Button', {
                            props: { type: 'text', size: 'small' },
                            on: { click: () => this.openEditor(p.row) },
                        }, this.$L('编辑')),
                        h('Button', {
                            props: { type: 'text', size: 'small' },
                            on: { click: () => this.cloneTemplate(p.row) },
                        }, this.$L('复制')),
                        h('Button', {
                            props: {
                                type: 'text',
                                size: 'small',
                                disabled: !!(p.row.is_builtin && p.row.is_default),
                            },
                            style: { color: (p.row.is_builtin && p.row.is_default) ? '' : '#f40' },
                            on: { click: () => this.deleteTemplate(p.row) },
                        }, this.$L('删除')),
                    ]),
                },
            ],
        };
    },
    mounted() {
        this.loadTemplates();
    },
    methods: {
        emptyForm() {
            return {
                id: 0,
                scope: 'global',
                scope_id: 0,
                name: '',
                description: '',
                is_default: false,
                is_builtin: false,
                enabled: true,
                trigger_rules: [],
            };
        },
        loadTemplates() {
            this.loading = true;
            this.$store.dispatch('call', {
                url: 'project/report_template/list',
                method: 'post',
                data: {
                    scope: 'global',
                    include_disabled: true,
                },
            }).then(({data}) => {
                this.templates = Array.isArray(data) ? data : (data?.rows || []);
            }).catch(({msg}) => {
                $A.modalError(msg || this.$L('加载失败'));
            }).finally(() => {
                this.loading = false;
            });
        },
        openEditor(template) {
            if (template) {
                this.form = Object.assign(this.emptyForm(), $A.cloneJSON(template));
            } else {
                this.form = this.emptyForm();
            }
            this.editorVisible = true;
        },
        onTemplateSaved() {
            this.editorVisible = false;
            this.loadTemplates();
        },
        cloneTemplate(template) {
            $A.modalInput({
                title: this.$L('复制模板'),
                placeholder: this.$L('请输入新模板名称'),
                value: template.name + ' (' + this.$L('副本') + ')',
                onOk: (newName) => {
                    if (!newName) {
                        return;
                    }
                    this.$store.dispatch('call', {
                        url: 'project/report_template/clone',
                        method: 'post',
                        data: { id: template.id, name: newName },
                    }).then(() => {
                        $A.messageSuccess(this.$L('复制成功'));
                        this.loadTemplates();
                    }).catch(({msg}) => {
                        $A.modalError(msg || this.$L('复制失败'));
                    });
                },
            });
        },
        deleteTemplate(template) {
            if (template.is_builtin && template.is_default) {
                $A.messageWarning(this.$L('内建默认模板不可删除'));
                return;
            }
            $A.modalConfirm({
                title: this.$L('确认删除'),
                content: this.$L('确定删除模板') + ' "' + template.name + '"?',
                onOk: () => {
                    this.$store.dispatch('call', {
                        url: 'project/report_template/delete',
                        method: 'post',
                        data: { id: template.id },
                    }).then(() => {
                        $A.messageSuccess(this.$L('删除成功'));
                        this.loadTemplates();
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
.system-report-templates {
    .action-bar {
        margin-bottom: 12px;
    }
}
</style>
