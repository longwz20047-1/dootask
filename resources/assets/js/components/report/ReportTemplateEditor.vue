<template>
    <!-- [CUSTOM:report-channel] Sprint 8 Pass 2 · Task 8.2 + 8.3 + 8.4 -->
    <Modal
        :value="visible"
        :title="title"
        :width="900"
        :mask-closable="false"
        @input="onModalInput"
        class="report-template-editor">
        <Tabs v-model="activeTab" type="card">
            <!-- Tab 1: 基础信息 -->
            <TabPane :label="$L('基础信息')" name="basic">
                <Form :label-width="100">
                    <FormItem :label="$L('模板名称')" required>
                        <Input v-model="form.name" :maxlength="100" :placeholder="$L('请输入模板名称')"/>
                    </FormItem>
                    <FormItem :label="$L('范围')" required>
                        <RadioGroup v-model="form.scope" @on-change="onScopeChange">
                            <Radio label="global" :disabled="form.id > 0">{{$L('全局')}}</Radio>
                            <Radio label="project" :disabled="form.id > 0 || !!projectId">{{$L('项目')}}</Radio>
                            <Radio label="flow_item" :disabled="form.id > 0">{{$L('工作流节点')}}</Radio>
                        </RadioGroup>
                        <p class="form-hint">{{$L('范围创建后不可修改')}}</p>
                    </FormItem>
                    <FormItem v-if="form.scope === 'project' && !projectId" :label="$L('项目 ID')">
                        <InputNumber v-model="form.scope_id" :min="1"/>
                    </FormItem>
                    <FormItem v-if="form.scope === 'flow_item'" :label="$L('节点 ID')">
                        <InputNumber v-model="form.scope_id" :min="1"/>
                    </FormItem>
                    <FormItem v-if="form.scope === 'global'" :label="$L('设为默认')">
                        <Switch v-model="form.is_default" :disabled="!!form.is_builtin"/>
                        <p class="form-hint">{{$L('全局只允许一个默认模板')}}</p>
                    </FormItem>
                    <FormItem :label="$L('启用')">
                        <Switch v-model="form.enabled"/>
                    </FormItem>
                    <FormItem :label="$L('描述')">
                        <Input v-model="form.description" type="textarea" :rows="3" :maxlength="500"/>
                    </FormItem>
                </Form>
            </TabPane>

            <!-- Tab 2: 字段引用 -->
            <TabPane :label="$L('字段引用')" name="fields">
                <p class="form-hint">{{$L('拖拽排序，可单独覆盖字段属性')}}</p>
                <Button type="primary" icon="md-add" @click="addFieldRef" class="action-btn">{{$L('添加字段引用')}}</Button>
                <p v-if="!form.fields || form.fields.length === 0" class="empty-tip">
                    {{$L('未添加任何字段')}}
                </p>
                <Draggable
                    v-else
                    v-model="form.fields"
                    handle=".drag-handle"
                    :animation="150"
                    item-key="_uid"
                    tag="div"
                    class="field-list">
                    <div v-for="(link, i) in form.fields" :key="link._uid" class="field-ref-row">
                        <Icon class="drag-handle" type="md-menu"/>
                        <Select
                            v-model="link.field_id"
                            :placeholder="$L('选择字段')"
                            class="field-select"
                            transfer>
                            <Option
                                v-for="f in availableFields(link.field_id)"
                                :key="f.id"
                                :value="f.id"
                                :label="f.name">
                                <span>{{ f.name }}</span>
                                <Tag size="small" style="margin-left:6px">{{ f.code }}</Tag>
                            </Option>
                        </Select>
                        <Tooltip :content="$L('覆盖字段属性')" transfer>
                            <Switch v-model="link.override_enabled" size="small"/>
                        </Tooltip>
                        <template v-if="link.override_enabled">
                            <Tooltip :content="$L('隐藏')" transfer>
                                <Switch v-model="link.override.hidden" size="small"/>
                            </Tooltip>
                            <Select
                                v-model="link.override.required_override"
                                size="small"
                                :placeholder="$L('继承字段定义')"
                                style="width:110px"
                                transfer>
                                <Option :value="null">{{$L('继承字段定义')}}</Option>
                                <Option :value="true">{{$L('必填')}}</Option>
                                <Option :value="false">{{$L('选填')}}</Option>
                            </Select>
                            <Input
                                v-model="link.override.default_value"
                                size="small"
                                style="width:160px"
                                :placeholder="$L('默认值')"/>
                        </template>
                        <Button type="text" icon="md-trash" @click="removeFieldRef(i)"/>
                    </div>
                </Draggable>
            </TabPane>

            <!-- Tab 3: 触发规则 -->
            <TabPane :label="$L('触发规则')" name="rules">
                <p class="form-hint">{{$L('7 events × 3 modes × constraint 矩阵')}}</p>
                <Button type="primary" icon="md-add" @click="addRule" class="action-btn">{{$L('添加触发规则')}}</Button>
                <p v-if="!form.trigger_rules || form.trigger_rules.length === 0" class="empty-tip">
                    {{$L('未配置任何触发规则')}}
                </p>
                <div v-for="(rule, idx) in form.trigger_rules" :key="idx" class="trigger-rule-card">
                    <div class="rule-header">
                        <span class="rule-title">{{$L('规则')}} #{{ idx + 1 }}</span>
                        <Button type="text" icon="md-trash" @click="removeRule(idx)" size="small">
                            {{$L('删除规则')}}
                        </Button>
                    </div>
                    <Row :gutter="12">
                        <Col span="6">
                            <FormItem :label="$L('事件')" :label-width="60">
                                <Select v-model="rule.event" size="small" transfer>
                                    <Option v-for="ev in EVENTS" :key="ev" :value="ev">{{ eventLabel(ev) }}</Option>
                                </Select>
                            </FormItem>
                        </Col>
                        <Col span="6">
                            <FormItem :label="$L('模式')" :label-width="60">
                                <Select v-model="rule.mode" size="small" transfer @on-change="(v) => onRuleModeChange(rule, v)">
                                    <Option v-for="m in MODES" :key="m" :value="m">{{ modeLabel(m) }}</Option>
                                </Select>
                            </FormItem>
                        </Col>
                        <Col span="12">
                            <FormItem :label="$L('目标')" :label-width="60">
                                <Select v-model="rule.target" size="small" transfer>
                                    <Option value="reporter">{{$L('上报人/任务负责人')}}</Option>
                                    <Option value="collaborators">{{$L('协作者(含负责人+协助人)')}}</Option>
                                    <Option value="assignees">{{$L('协助人')}}</Option>
                                </Select>
                            </FormItem>
                        </Col>
                    </Row>
                    <Row :gutter="12">
                        <Col span="24">
                            <FormItem :label="$L('约束')" :label-width="60">
                                <!-- block: min_count / time_window -->
                                <template v-if="rule.mode === 'block'">
                                    <InputNumber
                                        :value="getConstraint(rule, 'min_count')"
                                        @on-change="(v) => setConstraint(rule, 'min_count', v)"
                                        :min="0"
                                        size="small"
                                        :placeholder="$L('最少几次')"
                                        style="margin-right:8px"/>
                                    <InputNumber
                                        :value="getConstraint(rule, 'time_window')"
                                        @on-change="(v) => setConstraint(rule, 'time_window', v)"
                                        :min="0"
                                        size="small"
                                        :placeholder="$L('时间窗口/分')"/>
                                </template>
                                <!-- modal: _hint only -->
                                <template v-else-if="rule.mode === 'modal'">
                                    <Input
                                        :value="getConstraint(rule, '_hint')"
                                        @input="(v) => setConstraint(rule, '_hint', v)"
                                        size="small"
                                        :placeholder="$L('提示文案')"
                                        style="max-width:480px"/>
                                </template>
                                <!-- remind: frequency_limit_min / time_window / _hint -->
                                <template v-else-if="rule.mode === 'remind'">
                                    <InputNumber
                                        :value="getConstraint(rule, 'frequency_limit_min')"
                                        @on-change="(v) => setConstraint(rule, 'frequency_limit_min', v)"
                                        :min="0"
                                        size="small"
                                        :placeholder="$L('节流/分')"
                                        style="margin-right:8px"/>
                                    <InputNumber
                                        :value="getConstraint(rule, 'time_window')"
                                        @on-change="(v) => setConstraint(rule, 'time_window', v)"
                                        :min="0"
                                        size="small"
                                        :placeholder="$L('时间窗口/分')"
                                        style="margin-right:8px"/>
                                    <Input
                                        :value="getConstraint(rule, '_hint')"
                                        @input="(v) => setConstraint(rule, '_hint', v)"
                                        size="small"
                                        :placeholder="$L('提示文案')"
                                        style="max-width:300px"/>
                                </template>
                            </FormItem>
                        </Col>
                    </Row>
                </div>
            </TabPane>
        </Tabs>
        <div slot="footer" class="adaption">
            <Button type="default" @click="closeModal">{{$L('取消')}}</Button>
            <Button type="primary" :loading="saving" @click="handleSubmit">{{$L('保存')}}</Button>
        </div>
    </Modal>
</template>

<script>
// [CUSTOM:report-channel] Sprint 8 Pass 2 · ReportTemplateEditor
// 3 Tab 单组件：基础信息 / 字段引用（draggable + override）/ 触发规则（7×3 矩阵 UI）
// 后端校验：TaskReportTemplateObserver::validateTriggerRules（spec §3.6.1 84 状态机）
// 与后端契约：
//   - target ∈ {reporter, collaborators, assignees}（单选 string，非数组）
//   - constraint allowed keys: block→[min_count,time_window] / modal→[_hint] / remind→[frequency_limit_min,time_window,_hint]
//   - 后端 save 吃 trigger_rules 数组；字段引用走独立端点 report_template/sync_fields（plan v1.12 B2 闭环）
import Draggable from 'vuedraggable';

const EVENTS = ['on_complete', 'on_start', 'on_status_change', 'on_flow_change', 'daily', 'weekly', 'manual'];
const MODES = ['block', 'modal', 'remind'];

let _uidSeq = 0;
function nextUid() {
    _uidSeq += 1;
    return 'frow-' + _uidSeq;
}

export default {
    name: 'ReportTemplateEditor',
    components: { Draggable },
    props: {
        visible: { type: Boolean, default: false },
        value: { type: Object, default: () => null },
        projectId: { type: Number, default: 0 },
    },
    data() {
        return {
            activeTab: 'basic',
            saving: false,
            EVENTS,
            MODES,
            form: this.normalizeForm(this.value),
            availableFieldsCache: [],
        };
    },
    computed: {
        title() {
            return this.form.id ? this.$L('编辑模板') : this.$L('新建模板');
        },
    },
    watch: {
        value(v) {
            this.form = this.normalizeForm(v);
        },
        visible(v) {
            if (v) {
                this.activeTab = 'basic';
                this.form = this.normalizeForm(this.value);
                this.loadAvailableFields();
            }
        },
    },
    methods: {
        normalizeForm(template) {
            const base = {
                id: 0,
                scope: this.projectId ? 'project' : 'global',
                scope_id: this.projectId || 0,
                name: '',
                description: '',
                is_default: false,
                is_builtin: false,
                enabled: true,
                trigger_rules: [],
                fields: [],
            };
            if (!template) {
                return base;
            }
            const out = Object.assign({}, base, template);
            out.trigger_rules = Array.isArray(out.trigger_rules)
                ? out.trigger_rules.map(r => Object.assign(
                    { event: 'on_complete', mode: 'block', target: 'reporter', constraint: {} },
                    r,
                    { constraint: (r && typeof r.constraint === 'object' && r.constraint) ? Object.assign({}, r.constraint) : {} }
                ))
                : [];
            // template.fields 来自 report_template/resolve 返回的 fields（pivot 关联结构）
            out.fields = Array.isArray(template.fields)
                ? template.fields.map(f => ({
                    _uid: nextUid(),
                    field_id: f.field_id || f.id || null,
                    sort: typeof f.sort === 'number' ? f.sort : 0,
                    override_enabled: !!(f.override && Object.keys(f.override || {}).length > 0),
                    override: Object.assign(
                        { hidden: false, required_override: null, default_value: null },
                        f.override || {}
                    ),
                }))
                : [];
            return out;
        },
        eventLabel(ev) {
            const map = {
                on_complete: this.$L('任务完成'),
                on_start: this.$L('任务开始'),
                on_status_change: this.$L('状态切换'),
                on_flow_change: this.$L('工作流流转'),
                daily: this.$L('每日定时'),
                weekly: this.$L('每周定时'),
                manual: this.$L('仅手动'),
            };
            return map[ev] || ev;
        },
        modeLabel(m) {
            const map = {
                block: this.$L('阻塞'),
                modal: this.$L('弹窗'),
                remind: this.$L('提醒'),
            };
            return map[m] || m;
        },
        onScopeChange() {
            if (this.form.scope === 'global') {
                this.form.scope_id = 0;
            } else if (this.form.scope === 'project' && this.projectId) {
                this.form.scope_id = this.projectId;
            } else if (this.form.scope === 'flow_item') {
                // 用户手填 scope_id
            }
        },
        onRuleModeChange(rule, newMode) {
            // mode 切换时重置 constraint（避免上次 mode 的字段污染 → 后端会拒绝不合法 keys）
            const allowed = {
                block: { min_count: 1, time_window: 0 },
                modal: { _hint: '' },
                remind: { frequency_limit_min: 0, time_window: 0, _hint: '' },
            };
            const next = allowed[newMode || rule.mode] || {};
            this.$set(rule, 'constraint', Object.assign({}, next));
        },
        getConstraint(rule, key) {
            return rule && rule.constraint ? rule.constraint[key] : undefined;
        },
        setConstraint(rule, key, value) {
            if (!rule.constraint || typeof rule.constraint !== 'object') {
                this.$set(rule, 'constraint', {});
            }
            this.$set(rule.constraint, key, value);
        },
        async loadAvailableFields() {
            try {
                const resp = await this.$store.dispatch('call', {
                    url: 'project/report_field/list',
                    method: 'post',
                    data: {
                        scope: this.form.scope === 'project' ? 'project' : 'global',
                        project_id: this.form.scope === 'project' ? this.form.scope_id : 0,
                        include_disabled: false,
                    },
                });
                const data = resp && resp.data;
                this.availableFieldsCache = Array.isArray(data) ? data : (data && data.rows) || [];
            } catch (err) {
                this.availableFieldsCache = [];
                $A.modalError((err && err.msg) || this.$L('加载字段失败'));
            }
        },
        availableFields(currentFieldId) {
            const selected = (this.form.fields || [])
                .map(f => f.field_id)
                .filter(id => id && id !== currentFieldId);
            return (this.availableFieldsCache || []).filter(f => !selected.includes(f.id));
        },
        addFieldRef() {
            this.form.fields.push({
                _uid: nextUid(),
                field_id: null,
                sort: (this.form.fields || []).length + 1,
                override_enabled: false,
                override: { hidden: false, required_override: null, default_value: null },
            });
        },
        removeFieldRef(idx) {
            this.form.fields.splice(idx, 1);
        },
        addRule() {
            this.form.trigger_rules.push({
                event: 'on_complete',
                mode: 'block',
                target: 'reporter',
                constraint: { min_count: 1, time_window: 0 },
            });
        },
        removeRule(idx) {
            this.form.trigger_rules.splice(idx, 1);
        },
        sanitizeTriggerRules(rules) {
            // 与后端 allowedConstraintKeysForMode 保持一致：剔除非法键避免 Observer 拒绝
            const allowed = {
                block: ['min_count', 'time_window'],
                modal: ['_hint'],
                remind: ['frequency_limit_min', 'time_window', '_hint'],
            };
            return (rules || []).map(r => {
                const constraint = {};
                const keys = allowed[r.mode] || [];
                if (r.constraint && typeof r.constraint === 'object') {
                    keys.forEach(k => {
                        if (r.constraint[k] !== undefined && r.constraint[k] !== null && r.constraint[k] !== '') {
                            constraint[k] = r.constraint[k];
                        }
                    });
                }
                return {
                    event: r.event,
                    mode: r.mode,
                    target: r.target || 'reporter',
                    constraint,
                };
            });
        },
        validateLocal() {
            if (!this.form.name || !this.form.name.trim()) {
                $A.messageError(this.$L('请输入模板名称'));
                this.activeTab = 'basic';
                return false;
            }
            if (this.form.scope === 'project' && (!this.form.scope_id || this.form.scope_id <= 0)) {
                $A.messageError(this.$L('请输入项目 ID'));
                this.activeTab = 'basic';
                return false;
            }
            if (this.form.scope === 'flow_item' && (!this.form.scope_id || this.form.scope_id <= 0)) {
                $A.messageError(this.$L('请输入节点 ID'));
                this.activeTab = 'basic';
                return false;
            }
            return true;
        },
        async handleSubmit() {
            if (!this.validateLocal()) {
                return;
            }
            this.saving = true;
            try {
                const payload = {
                    scope: this.form.scope,
                    scope_id: this.form.scope_id || 0,
                    name: this.form.name.trim(),
                    description: this.form.description || '',
                    is_default: !!this.form.is_default,
                    enabled: !!this.form.enabled,
                    trigger_rules: this.sanitizeTriggerRules(this.form.trigger_rules),
                };
                if (this.form.id > 0) {
                    payload.id = this.form.id;
                }
                const resp = await this.$store.dispatch('call', {
                    url: 'project/report_template/save',
                    method: 'post',
                    data: payload,
                });
                const saved = (resp && resp.data && resp.data.template) || null;
                const tplId = (saved && saved.id) || this.form.id;
                // 同步字段引用（plan v1.12 B2 闭环）
                // - builtin global default 模板后端会返 retError，前端忽略以不阻塞模板本身保存
                // - 仅当 tplId>0（save 成功 / 编辑路径）才调，否则跳过
                let syncedFields = null;
                if (tplId > 0) {
                    try {
                        const fieldsPayload = (this.form.fields || []).map((link, idx) => ({
                            field_id: link.field_id,
                            sort: idx + 1,
                            override: link.override_enabled
                                ? {
                                    hidden: !!(link.override && link.override.hidden),
                                    required_override: link.override ? link.override.required_override : null,
                                    default_value: link.override ? link.override.default_value : null,
                                }
                                : {},
                        })).filter(item => item.field_id);
                        const syncResp = await this.$store.dispatch('call', {
                            url: 'project/report_template/sync_fields',
                            method: 'post',
                            data: { template_id: tplId, fields: fieldsPayload },
                        });
                        syncedFields = (syncResp && syncResp.data && syncResp.data.fields) || null;
                    } catch (syncErr) {
                        // 字段同步失败不阻塞模板保存（例如 builtin 模板限制）；仅提示
                        $A.messageWarning((syncErr && syncErr.msg) || this.$L('字段引用同步失败'));
                    }
                }
                $A.messageSuccess(this.$L('保存成功'));
                const emitPayload = saved || Object.assign({}, this.form);
                if (syncedFields) {
                    emitPayload.fields = syncedFields;
                }
                this.$emit('saved', emitPayload);
                this.closeModal();
            } catch (err) {
                $A.modalError((err && err.msg) || this.$L('保存失败'));
            } finally {
                this.saving = false;
            }
        },
        onModalInput(v) {
            // Modal 关闭时反向 emit visible
            this.$emit('update:visible', v);
        },
        closeModal() {
            this.$emit('update:visible', false);
        },
    },
};
</script>

<style lang="scss" scoped>
.report-template-editor {
    .form-hint {
        color: #999;
        font-size: 12px;
        margin: 4px 0 0;
    }
    .empty-tip {
        color: #999;
        text-align: center;
        padding: 24px 0;
    }
    .action-btn {
        margin-bottom: 12px;
    }
    .field-list {
        max-height: 360px;
        overflow-y: auto;
    }
    .field-ref-row {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px;
        margin-bottom: 8px;
        background: #f5f7fa;
        border: 1px solid #e8eaec;
        border-radius: 4px;
        .drag-handle {
            cursor: move;
            color: #999;
            font-size: 18px;
        }
        .field-select {
            flex: 1;
            min-width: 200px;
        }
    }
    .trigger-rule-card {
        padding: 12px;
        margin-bottom: 12px;
        background: #f5f7fa;
        border: 1px solid #e8eaec;
        border-radius: 4px;
        .rule-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            .rule-title {
                font-weight: 500;
                color: #333;
            }
        }
    }
}
</style>
</content>
</invoke>