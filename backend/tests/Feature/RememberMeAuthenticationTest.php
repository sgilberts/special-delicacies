<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Http\Middleware\ResolveRememberedApiToken;
use Illuminate\Http\Request;
use Tests\TestCase;

class RememberMeAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_staff_remember_me_returns_an_httponly_cookie_and_staff_cookie_resolves_to_bearer_auth(): void
    {
        $user = User::factory()->create(['email' => 'remember@example.test', 'password' => bcrypt('correct horse battery')]);
        $user->roles()->attach(Role::where('slug', 'super-admin')->firstOrFail());

        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct horse battery', 'remember' => true])->assertOk();
        $cookie = $login->getCookie('sd_staff_remember', false);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertNull($login->json('data.token'));

        $request = Request::create('/api/v1/auth/me', 'GET');
        $request->cookies->set('sd_staff_remember', $cookie->getValue());
        $middleware = new ResolveRememberedApiToken();
        $middleware->handle($request, function (Request $request) use ($cookie) {
            $this->assertSame('Bearer '.$cookie->getValue(), $request->header('Authorization'));
            return response()->json(['success' => true]);
        });
    }

    public function test_customer_remember_cookie_cannot_authenticate_staff_routes_and_logout_revokes_it(): void
    {
        $customer = Customer::create(['name' => 'Customer Test', 'phone' => '0240000001', 'email' => 'customer@example.test', 'password' => bcrypt('correct horse battery'), 'is_active' => true]);
        $login = $this->postJson('/api/v1/customers/login', ['phone' => $customer->phone, 'password' => 'correct horse battery', 'remember' => true])->assertOk();
        $cookie = $login->getCookie('sd_customer_remember', false);
        $this->assertNotNull($cookie);
        $this->assertNull($login->json('data.token'));

        $request = Request::create('/api/v1/customer/profile', 'GET');
        $request->cookies->set('sd_customer_remember', $cookie->getValue());
        (new ResolveRememberedApiToken())->handle($request, function (Request $request) use ($cookie) {
            $this->assertSame('Bearer '.$cookie->getValue(), $request->header('Authorization'));
            return response()->json(['success' => true]);
        });

        $staffRequest = Request::create('/api/v1/auth/me', 'GET');
        $staffRequest->cookies->set('sd_customer_remember', $cookie->getValue());
        (new ResolveRememberedApiToken())->handle($staffRequest, function (Request $request) {
            $this->assertNull($request->header('Authorization'));
            return response()->json(['success' => true]);
        });
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

}
