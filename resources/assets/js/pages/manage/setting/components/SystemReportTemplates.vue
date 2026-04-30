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
        <!-- ReportTemplateEditor 占位（Sprint 8 Pass 2 实施完整组件） -->
        <Modal
            v-model="editorVisible"
            :title="editorTitle"
            :mask-closable="false"
            :width="640">
            <p class="editor-placeholder-tip">
                {{$L('Sprint 8 Pass 2 实施 ReportTemplateEditor.vue 完整组件（trigger_rules 编辑器 + 字段绑定）')}}
            </p>
            <p class="editor-placeholder-tip">{{$L('当前 form 数据预览：')}}</p>
            <pre class="editor-placeholder-pre">{{ formPreview }}</pre>
            <div slot="footer">
                <Button @click="editorVisible = false">{{$L('关闭')}}</Button>
            </div>
        </Modal>
    </div>
</template>

<script>
// [CUSTOM:report-channel] Sprint 8 Pass 1 · Task 8.1
// 系统级（scope=global）上报模板管理。仅管理员可见（system.vue 已限定 admin tab）。
// 完整 ReportTemplateEditor 组件由 Sprint 8 Pass 2 实施；本 Pass 1 仅提供框架 + list/delete/clone。
export default {
    name: 'SystemReportTemplates',
    data() {
        return {
            loading: false,
            templates: [],
            editorVisible: false,
            editorTitle: '',
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
    computed: {
        formPreview() {
            try {
                return JSON.stringify(this.form, null, 2);
            } catch (e) {
                return String(this.form);
            }
        },
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
                this.editorTitle = this.$L('编辑模板');
            } else {
                this.form = this.emptyForm();
                this.editorTitle = this.$L('新建模板');
            }
            this.editorVisible = true;
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
    .editor-placeholder-tip {
        color: #666;
        margin: 0 0 8px;
    }
    .editor-placeholder-pre {
        background: #f5f7fa;
        border: 1px solid #e8eaec;
        border-radius: 4px;
        padding: 8px 12px;
        max-height: 320px;
        overflow: auto;
        font-size: 12px;
        margin: 0;
    }
}
</style>
