<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\Config;
use App\Models\ConfigCategory;
use App\Models\VueRouter;
use Tests\AdminTestCase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\RequestActions;

class ConfigControllerTest extends AdminTestCase
{
    use RefreshDatabase;
    use RequestActions;
    protected $resourceName = 'configs';

    protected function setUp(): void
    {
        parent::setUp();
        $this->login();
    }

    protected function getConfig(string $config)
    {
        return $this->get(route("admin.configs.{$config}"));
    }

    protected function prepareVueRouters()
    {
        factory(VueRouter::class, 5)->create();
        VueRouter::find(2)->children()->save(VueRouter::find(3));
        VueRouter::find(4)->children()->save(VueRouter::find(5));
    }

    public function testVueRoutersWithoutAuth()
    {
        $this->prepareVueRouters();

        $res = $this->getConfig('vue-routers');
        $res->assertStatus(200)
            ->assertJsonCount(3);
    }

    public function testVueRoutersUserNoAuth()
    {
        $this->prepareVueRouters();

        // 绑定角色
        VueRouter::find(1)->roles()->create(
            factory(AdminRole::class)->create(['slug' => 'role-router-1'])->toArray()
        );
        // 子菜单绑定权限
        VueRouter::find(3)->update([
            'permission' => factory(AdminPermission::class)->create(['slug' => 'perm-router-3'])->slug,
        ]);
        $res = $this->getConfig('vue-routers');
        $res->assertStatus(200)
            ->assertJsonCount(2)
            ->assertJsonMissing(['id' => 3]);
    }

    public function testVueRoutersUserHasAuth()
    {
        $this->prepareVueRouters();

        $this->user->roles()->attach(1);
        $this->user->permissions()->attach(1);

        $res = $this->getConfig('vue-routers');
        $res->assertStatus(200)
            ->assertJsonCount(3);
    }

    public function testDestroy()
    {
        factory(Config::class)->create();

        $res = $this->destroyResource(1);
        $res->assertStatus(204);

        $this->assertDatabaseMissing('configs', ['id' => 1]);
    }

    public function testEdit()
    {
        factory(Config::class)->create();

        $res = $this->editResource(1);
        $res->assertStatus(200);
    }

    public function testUpdate()
    {
        factory(ConfigCategory::class)->create();
        factory(Config::class)->create([
            'name' => 'name',
            'slug' => 'slug',
            'type' => Config::TYPE_INPUT,
        ]);

        // category_id exists
        $res = $this->updateResource(1, [
            'category_id' => -1,
        ]);
        $res->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);

        // name unique 排除自身
        $res = $this->updateResource(1, [
            'name' => 'name1',
        ]);
        $res->assertStatus(201)
            ->assertJsonMissingValidationErrors(['name']);

        // type, slug 不能修改
        $inputs = [
            'name' => 'new name',
            'type' => Config::TYPE_TEXTAREA,
            'slug' => 'new slug',
            'category_id' => 1,
            'desc' => 'new desc',
            'value' => 'new value',
            'validation_rules' => 'new rules',
        ];
        $res = $this->updateResource(1, $inputs);
        $res->assertStatus(201);

        $expectData = array_merge($inputs, [
            'type' => Config::TYPE_TEXTAREA,
            'slug' => 'new slug',
            'value' => json_encode('new value'),
        ]);
        $this->assertDatabaseHas('configs', $expectData);
    }

    public function testIndex()
    {
        factory(ConfigCategory::class, 2)->create()
            ->each(function (ConfigCategory $cate) {
                $cate->configs()->createMany(factory(Config::class, 2)->make()->toArray());
            });

        $res = $this->getResources();
        $res->assertStatus(200)
            ->assertJsonCount(4, 'data');
    }

    public function testStoreValidation()
    {
        factory(ConfigCategory::class)->create();
        factory(Config::class)->create([
            'name' => 'name',
            'slug' => 'slug',
        ]);

        // type, name, slug required
        // category_id required
        // desc, validation_rules string
        $res = $this->storeResource([
            'desc' => [],
            'validation_rules' => [],
        ]);
        $res->assertStatus(422)
            ->assertJsonValidationErrors([
                'type', 'name', 'slug', 'desc',
                'validation_rules', 'category_id',
            ]);

        // type in
        // category_id exists
        // name, slug string
        // desc, validation_rules max:xx
        $res = $this->storeResource([
            'type' => 'not in',
            'category_id' => '-999',
            'name' => [],
            'slug' => [],
            'desc' => str_repeat('a', 256),
            'validation_rules' => str_repeat('a', 256),
        ]);
        $res->assertStatus(422)
            ->assertJsonValidationErrors([
                'type', 'name', 'slug', 'desc', 'validation_rules',
            ]);

        // name, slug max:50
        $res = $this->storeResource([
            'type' => Config::TYPE_INPUT,
            'name' => str_repeat('a', 51),
            'slug' => str_repeat('a', 51),
        ]);
        $res->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'slug']);

        // name, slug unique
        $res = $this->storeResource([
            'type' => Config::TYPE_INPUT,
            'name' => 'name',
            'slug' => 'slug',
        ]);
        $res->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'slug']);
    }

    public function testConfigOptionsValidation()
    {
        // type 字段无效时，不验证
        $res = $this->storeResource([
            'options' => null,
        ]);
        $res->assertJsonMissingValidationErrors(['options']);

        // options 只能为空的类型
        foreach ([Config::TYPE_INPUT, Config::TYPE_TEXTAREA, Config::TYPE_OTHER] as $type) {
            $res = $this->storeResource([
                'type' => $type,
                'options' => 'not null',
            ]);
            $res->assertJsonValidationErrors(['options']);
        }

        /**
         * type TYPE_FILE 的数据
         * [
         *     'max' => 'required|between:1,99',
         *     'ext' => 'nullable',
         * ]
         */
        $optionsInputs = [null, 'not number', '-1'];
        foreach ($optionsInputs as $max) {
            $res = $this->storeResource([
                'type' => Config::TYPE_FILE,
                'options' => [
                    'max' => $max,
                    'ext' => null,
                ],
            ]);
            $res->assertStatus(422)
                ->assertJsonValidationErrors('options');
        }

        /**
         * type TYPE_SINGLE_SELECT 或 TYPE_MULTIPLE_SELECT 的数据
         * [
         *     'options' => 'required|ConfigSelectTypeOptions',
         *     'type' => 'required|in:input,select',
         * ]
         */
        foreach ([null, [], "=>\n=>"] as $options) {
            $res = $this->storeResource([
                'type' => Config::TYPE_SINGLE_SELECT,
                'options' => [
                    'options' => $options,
                    'type' => 'input',
                ],
            ]);
            $res->assertStatus(422)
                ->assertJsonValidationErrors('options');
        }

        foreach ([null, 'not in'] as $type) {
            $res = $this->storeResource([
                'type' => Config::TYPE_SINGLE_SELECT,
                'options' => [
                    'options' => '1=>value1',
                    'type' => $type,
                ],
            ]);
            $res->assertStatus(422)
                ->assertJsonValidationErrors('options');
        }
    }

    public function testStore()
    {
        factory(ConfigCategory::class)->create();

        $inputs = factory(Config::class)->make()->toArray();
        $inputs['category_id'] = 1;

        $res = $this->storeResource($inputs);
        $res->assertStatus(201);

        $this->assertDatabaseHas('configs', [
            'id' => 1,
            'category_id' => 1,
        ]);
    }
}
