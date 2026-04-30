<template>
    <!-- [CUSTOM:report-channel] Sprint 8 Pass 1 · Task 8.5 -->
    <div class="project-report-templates">
        <div class="header">
            <div class="title">
                {{$L('项目上报模板')}}
                <Loading v-if="loading"/>
            </div>
            <div class="actions">
                <Button type="primary" icon="md-add" @click="openEditor()">{{$L('新建模板')}}</Button>
            </div>
        </div>
        <p class="hint">{{$L('为项目自定义上报模板（仅项目负责人可改）')}}</p>
        <div class="content">
            <Table
                :columns="columns"
                :data="templates"
                :loading="loading"
                :no-data-text="$L('暂无项目级模板，可点击右上角 [新建模板] 创建')"
                size="small"
                stripe/>
        </div>
        <!-- ReportTemplateEditor 完整组件（Sprint 8 Pass 2 替换 Pass 1 占位 Modal） -->
        <ReportTemplateEditor
            :visible.sync="editorVisible"
            :value="form"
            :project-id="projectId"
            @saved="onTemplateSaved"/>
    </div>
</template>

<script>
// [CUSTOM:report-channel] Sprint 8 Pass 1 · Task 8.5（Pass 2 接入 ReportTemplateEditor）
// 项目级（scope=project）上报模板管理。仅项目负责人可见（ProjectPanel dropdown 已限定 owner-only menu）。
import ReportTemplateEditor from "../../../components/report/ReportTemplateEditor";

export default {
    name: 'ProjectReportTemplates',
    components: { ReportTemplateEditor },
    props: {
        projectId: {
            type: Number,
            required: true,
        },
    },
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
                            props: { type: 'text', size: 'small' },
                            style: { color: '#f40' },
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
                scope: 'project',
                scope_id: this.projectId,
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
                    scope: 'project',
                    scope_id: this.projectId,
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
.project-report-templates {
    height: 100%;
    display: flex;
    flex-direction: column;
    padding: 20px;
    box-sizing: border-box;
    .header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
        .title {
            font-size: 18px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }
    }
    .hint {
        color: #999;
        font-size: 12px;
        margin: 0 0 12px;
    }
    .content {
        flex: 1;
        overflow: auto;
    }
}
</style>
