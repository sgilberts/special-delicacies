<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_staff_can_update_profile_and_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('CurrentPass123!')]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/profile', [
                'name' => 'Updated Staff Name',
                'email' => 'updated.staff@example.test',
                'phone' => '+233201234567',
                'current_password' => 'CurrentPass123!',
                'new_password' => 'NewStaffPass123!',
                'new_password_confirmation' => 'NewStaffPass123!',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Staff Name')
            ->assertJsonPath('data.email', 'updated.staff@example.test')
            ->assertJsonMissingPath('data.password');

        $this->assertTrue(Hash::check('NewStaffPass123!', $user->fresh()->password));
    }

    public function test_staff_can_upload_a_profile_photo(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/v1/auth/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'profile_photo' => UploadedFile::fake()->image('profile.png'),
            ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJsonPath('success', true);
        $path = $user->fresh()->profile_photo_path;
        $this->assertNotEmpty($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('CurrentPass123!')]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/profile', [
                'current_password' => 'incorrect',
                'new_password' => 'NewStaffPass123!',
                'new_password_confirmation' => 'NewStaffPass123!',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');
    }
}
