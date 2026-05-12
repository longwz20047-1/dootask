<template>
    <div class="setting-component-item">
        <Form
            ref="formData"
            :model="formData"
            :rules="ruleData"
            v-bind="formOptions"
            @submit.native.prevent>
            <div class="block-setting-box">
                <h3>LDAP</h3>
                <div class="form-box">
                    <FormItem :label="$L('启用 LDAP 认证')" prop="ldap_open">
                        <RadioGroup v-model="formData.ldap_open">
                            <Radio label="open">{{ $L('开启') }}</Radio>
                            <Radio label="close">{{ $L('关闭') }}</Radio>
                        </RadioGroup>
                        <div class="form-tip">{{$L('开启后可以直接使用 LDAP 帐号密码登录')}}</div>
                    </FormItem>
                    <template v-if="formData.ldap_open === 'open'">
                        <FormItem :label="$L('LDAP 地址')" prop="ldap_host">
                            <Input v-model="formData.ldap_host"/>
                            <div class="form-tip">{{$L('例如')}}: 192.168.1.200、www.ldap.com</div>
                        </FormItem>
                        <FormItem :label="$L('LDAP 端口')" prop="ldap_port">
                            <Input v-model="formData.ldap_port" type="number" :placeholder="`${$L('默认')}: 389`"/>
                        </FormItem>
                        <FormItem label="Base DN" prop="ldap_base_dn">
                            <Input v-model="formData.ldap_base_dn"/>
                        </FormItem>
                        <FormItem label="User DN" prop="ldap_user_dn">
                            <Input v-model="formData.ldap_user_dn"/>
                        </FormItem>
                        <FormItem :label="$L('密码')" prop="ldap_password">
                            <Input v-model="formData.ldap_password" type="password"/>
                        </FormItem>
                        <FormItem :label="$L('同步本地帐号')" prop="ldap_sync_local">
                            <RadioGroup v-model="formData.ldap_sync_local">
                                <Radio label="open">{{ $L('开启') }}</Radio>
                                <Radio label="close">{{ $L('关闭') }}</Radio>
                            </RadioGroup>
                            <div class="form-tip">{{$L('开启同步本地帐号登录后将同步到 LDAP 服务器')}}</div>
                        </FormItem>
                        <FormItem>
                            <Button :loading="testLoad" @click="checkTest">{{ $L('测试链接') }}</Button>
                        </FormItem>
                    </template>
                </div>
            </div>
            <div class="block-setting-box">
                <h3>{{ $L('企业微信') }}</h3>
                <div class="form-box">
                    <FormItem :label="$L('启用企微登录')" prop="wecom_open">
                        <RadioGroup v-model="formData.wecom_open">
                            <Radio label="open">{{ $L('开启') }}</Radio>
                            <Radio label="close">{{ $L('关闭') }}</Radio>
                        </RadioGroup>
                        <div class="form-tip">{{$L('开启后企微员工可在应用内免密登录 DooTask')}}</div>
                    </FormItem>
                    <template v-if="formData.wecom_open === 'open'">
                        <FormItem :label="$L('企业 CorpID')" prop="wecom_corp_id">
                            <Input v-model="formData.wecom_corp_id"/>
                            <div class="form-tip">{{$L('企微管理后台 · 我的企业 · 企业信息 · 企业ID')}}</div>
                        </FormItem>
                        <FormItem :label="$L('应用 AgentId')" prop="wecom_agent_id">
                            <Input v-model="formData.wecom_agent_id" type="number"/>
                            <div class="form-tip">{{$L('企微管理后台 · 应用管理 · 自建应用 · AgentId')}}</div>
                        </FormItem>
                        <FormItem :label="$L('应用 Secret')" prop="wecom_secret">
                            <Input v-model="formData.wecom_secret" type="password"/>
                            <div class="form-tip">{{$L('自建应用的 Secret，用于 OAuth 静默登录')}}</div>
                        </FormItem>
                        <FormItem :label="$L('通讯录同步 Secret')" prop="wecom_contact_secret">
                            <Input v-model="formData.wecom_contact_secret" type="password"/>
                            <div class="form-tip">{{$L('管理工具 · 通讯录同步 · Secret，用于读取成员和部门信息')}}</div>
                        </FormItem>
                        <FormItem :label="$L('自动注册')" prop="wecom_auto_reg">
                            <RadioGroup v-model="formData.wecom_auto_reg">
                                <Radio label="open">{{ $L('开启') }}</Radio>
                                <Radio label="close">{{ $L('关闭') }}</Radio>
                            </RadioGroup>
                            <div class="form-tip">{{$L('开启后企微员工首次登录自动创建 DooTask 账号')}}</div>
                        </FormItem>
                        <FormItem :label="$L('组织架构同步')" prop="wecom_org_sync">
                            <RadioGroup v-model="formData.wecom_org_sync">
                                <Radio label="open">{{ $L('开启') }}</Radio>
                                <Radio label="close">{{ $L('关闭') }}</Radio>
                            </RadioGroup>
                            <div class="form-tip">{{$L('开启后可同步企微部门与成员到 DooTask')}}</div>
                        </FormItem>
                        <FormItem v-if="formData.wecom_org_sync === 'open'" :label="$L('同步操作')">
                            <Button :loading="orgSyncLoad" type="primary" @click="syncOrg">{{ $L('立即同步组织架构') }}</Button>
                            <div class="form-tip" v-if="orgStatus">
                                <div>{{$L('部门映射')}}: {{orgStatus.department_mappings || 0}}<span v-if="orgStatus.department_lost > 0" style="color:#ed4014;">（{{$L('已失联部门')}}: {{orgStatus.department_lost}}）</span></div>
                                <div>{{$L('用户绑定')}}: {{orgStatus.user_bindings || 0}}<span v-if="orgStatus.user_unbound > 0" style="color:#ed4014;">（{{$L('已禁用用户')}}: {{orgStatus.user_unbound}}）</span></div>
                                <div v-if="orgStatus.last_sync_at">{{$L('最后同步时间')}}: {{orgStatus.last_sync_at}}</div>
                                <div v-else>{{$L('尚未同步')}}</div>
                            </div>
                        </FormItem>
                        <!-- [CUSTOM:wecom-files-app] 第 2 自建应用：文件管理 -->
                        <FormItem :label="$L('启用文件应用')" prop="wecom_files_open">
                            <RadioGroup v-model="formData.wecom_files_open">
                                <Radio label="open">{{ $L('开启') }}</Radio>
                                <Radio label="close">{{ $L('关闭') }}</Radio>
                            </RadioGroup>
                            <div class="form-tip">{{$L('开启后第 2 个自建应用（DooTask 文件）可用，员工点击企微工作台的"DooTask 文件"图标进入精简版文件管理页')}}</div>
                        </FormItem>
                        <template v-if="formData.wecom_files_open === 'open'">
                            <FormItem :label="$L('文件应用 AgentId')" prop="wecom_files_agent_id">
                                <Input v-model="formData.wecom_files_agent_id" type="number"/>
                                <div class="form-tip">{{$L('企微管理后台 · 应用管理 · 自建应用 · DooTask 文件 · AgentId')}}</div>
                            </FormItem>
                            <FormItem :label="$L('文件应用 Secret')" prop="wecom_files_secret">
                                <Input v-model="formData.wecom_files_secret" type="password"/>
                                <div class="form-tip">{{$L('文件应用的 Secret，与主应用 Secret 不同；CorpID 和 通讯录 Secret 复用主应用，无需重填')}}</div>
                            </FormItem>
                        </template>
                    </template>
                </div>
            </div>
        </Form>
        <div class="setting-footer">
            <Button :loading="loadIng > 0" type="primary" @click="submitForm">{{ $L('提交') }}</Button>
            <Button :loading="loadIng > 0" @click="resetForm" style="margin-left: 8px">{{ $L('重置') }}</Button>
        </div>
    </div>
</template>

<script>
import {mapState} from "vuex";

export default {
    name: "SystemThirdAccess",
    data() {
        return {
            loadIng: 0,
            formData: {

            },
            ruleData: {},

            testLoad: false,
            orgSyncLoad: false,
            orgStatus: null,
        }
    },

    mounted() {
        this.systemSetting();
        this.loadOrgStatus();
    },

    computed: {
        ...mapState(['formOptions']),
    },

    methods: {
        submitForm() {
            this.$refs.formData.validate((valid) => {
                if (valid) {
                    this.systemSetting(true);
                }
            })
        },

        resetForm() {
            this.formData = $A.cloneJSON(this.formDatum_bak);
        },

        systemSetting(save) {
            this.loadIng++;
            this.$store.dispatch("call", {
                url: 'system/setting/thirdaccess?type=' + (save ? 'save' : 'all'),
                data: this.formData,
            }).then(({data}) => {
                if (save) {
                    $A.messageSuccess('修改成功');
                }
                this.formData = data;
                this.formDatum_bak = $A.cloneJSON(this.formData);
            }).catch(({msg}) => {
                if (save) {
                    $A.modalError(msg);
                }
            }).finally(_ => {
                this.loadIng--;
            });
        },

        checkTest() {
            if (this.testLoad) {
                return
            }
            this.testLoad = true
            this.$store.dispatch("call", {
                url: 'system/setting/thirdaccess?type=testldap',
                data: this.formData,
            }).then(({msg}) => {
                $A.messageSuccess(msg);
            }).catch(({msg}) => {
                $A.modalError(msg);
            }).finally(_ => {
                this.testLoad = false;
            });
        },

        loadOrgStatus() {
            this.$store.dispatch("call", {
                url: 'wecom/org/status',
                method: 'get',
            }).then(({data}) => {
                this.orgStatus = data;
            }).catch(_ => {
                this.orgStatus = null;
            });
        },

        syncOrg() {
            if (this.orgSyncLoad) return;
            this.orgSyncLoad = true;
            this.$store.dispatch("call", {
                url: 'wecom/org/sync',
                method: 'post',
            }).then(({data}) => {
                const dept = data.departments || {};
                const users = data.users_created || {};
                const leaders = data.leaders || {};
                const deptLine = $L('部门') + `: ${dept.created || 0} ${$L('新增')}, ${dept.updated || 0} ${$L('更新')}, ${dept.lost || 0} ${$L('失联')}, ${dept.recovered || 0} ${$L('恢复')}`;
                const userLine = $L('用户') + `: ${users.created || 0} ${$L('新增')}, ${users.skipped || 0} ${$L('跳过')}, ${users.disabled || 0} ${$L('离职禁用')}, ${users.resurrected || 0} ${$L('复活')}`;
                const leaderLine = $L('部门负责人') + `: ${leaders.updated || 0} ${$L('已更新')}`;
                $A.modalInfo({
                    title: $L('同步完成'),
                    content: `${deptLine}<br>${userLine}<br>${leaderLine}`,
                    okText: $L('确定'),
                    language: false,
                });
                this.loadOrgStatus();
            }).catch(({msg}) => {
                $A.modalError(msg || $L('同步失败'));
            }).finally(_ => {
                this.orgSyncLoad = false;
            });
        },
    }
}
</script>
