<?php

// [CUSTOM:report-channel]
// Pre_users 自定义 schema（无 name/email_verified_at/remember_token 列），
// 与 Laravel 默认 UserFactory 不兼容。本 Factory 改用真实 schema 字段。
// 参考：tests/Feature/WecomInternalTokenTest.php::makeUser() 已踩过的坑。

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = User::class;

    /**
     * Define the model's default state.
     *
     * 仅填 pre_users 真实存在的列（参 2021_06_25_182631_create_users_table.php）。
     *
     * @return array
     */
    public function definition()
    {
        return [
            'email'      => $this->faker->unique()->safeEmail(),
            'nickname'   => $this->faker->name(),
            'encrypt'    => Str::random(16),
            'password'   => 'test-password',  // string(50)，测试不走登录路径
            'identity'   => '',
            'disable_at' => null,
        ];
    }

    /**
     * 已禁用账号
     */
    public function disabled()
    {
        return $this->state(fn () => ['disable_at' => now()]);
    }
}
