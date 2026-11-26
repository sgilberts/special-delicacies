<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Contracts\Encryption\DecryptException;
use Tests\TestCase;

class RememberMeAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_staff_remember_me_returns_only_an_httponly_cookie_and_authenticates_staff_routes(): void
    {
        $user = User::factory()->create(['email' => 'remember@example.test', 'password' => bcrypt('correct horse battery')]);
        $user->roles()->attach(Role::where('slug', 'super-admin')->firstOrFail());

        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct horse battery', 'remember' => true])->assertOk();
        $cookie = $login->getCookie('sd_staff_remember');
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertNull($login->json('data.token'));

        $this->withUnencryptedCookie('sd_staff_remember', $this->encryptedCookie($cookie->getValue()))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_customer_remember_cookie_cannot_authenticate_staff_routes_and_logout_revokes_it(): void
    {
        $customer = Customer::create(['name' => 'Customer Test', 'phone' => '0240000001', 'email' => 'customer@example.test', 'password' => bcrypt('correct horse battery'), 'is_active' => true]);
        $login = $this->postJson('/api/v1/customers/login', ['phone' => $customer->phone, 'password' => 'correct horse battery', 'remember' => true])->assertOk();
        $cookie = $login->getCookie('sd_customer_remember');
        $this->assertNotNull($cookie);
        $this->assertNull($login->json('data.token'));

        $token = $this->cookiePayload($cookie->getValue());
        $this->withUnencryptedCookie('sd_customer_remember', $this->encryptPayload($token))->getJson('/api/v1/customer/profile')->assertOk();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withUnencryptedCookie('sd_customer_remember', $this->encryptPayload($token))->withHeader('Origin', 'http://special-delicacies.local')
            ->postJson('/api/v1/customer/logout')->assertOk();
        $this->withUnencryptedCookie('sd_customer_remember', $this->encryptPayload($token))->getJson('/api/v1/customer/profile')->assertUnauthorized();
    }

    public function test_unchecked_login_returns_a_tab_scoped_token_with_server_expiration(): void
    {
        $user = User::factory()->create(['email' => 'session@example.test', 'password' => bcrypt('correct horse battery')]);
        $user->roles()->attach(Role::where('slug', 'super-admin')->firstOrFail());

        $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct horse battery', 'remember' => false])->assertOk();
        $this->assertNotEmpty($response->json('data.token'));
        $token = $user->tokens()->latest('id')->firstOrFail();
        $this->assertTrue($token->expires_at->isFuture());
        $this->assertLessThanOrEqual(now()->addHours(8)->timestamp, $token->expires_at->timestamp);
    }

    private function cookiePayload(string $value): string
    {
        try {
            return app('encrypter')->decrypt($value, false);
        } catch (DecryptException) {
            return $value;
        }
    }

    private function encryptedCookie(string $value): string
    {
        return $this->encryptPayload($this->cookiePayload($value));
    }

    private function encryptPayload(string $value): string
    {
        return app('encrypter')->encrypt($value);
    }
}
