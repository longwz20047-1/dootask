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
        }
    },

    mounted() {
        this.systemSetting();
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
        }
    }
}
</script>
