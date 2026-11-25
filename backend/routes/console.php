<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Rotate compromised credentials for test/dev staff accounts and Reverb application secret.
 * Sensitive values are never displayed in stdout or logs.
 */
Artisan::command('credentials:rotate', function () {
    $staffEmails = [
        'admin@special-delicacies.local',
        'waiter@special-delicacies.local',
        'kitchen@special-delicacies.local',
    ];

    $rotatedCount = 0;
    foreach ($staffEmails as $email) {
        $user = User::where('email', $email)->first();
        if ($user) {
            // Generate strong cryptographically secure random password
            $newPassword = Str::random(32);
            $user->password = Hash::make($newPassword);
            $user->save();
            $rotatedCount++;
            $this->info("Rotated credentials for user: {$email}");
        } else {
            $this->warn("User not found: {$email}");
        }
    }

    // Rotate Reverb application secret in .env
    $envPath = base_path('.env');
    if (file_exists($envPath)) {
        $envContent = file_get_contents($envPath);
        $newSecret = 'revsec_' . bin2hex(random_bytes(16));
        $pattern = '/^REVERB_APP_SECRET=.*$/m';
        if (preg_match($pattern, $envContent)) {
            $envContent = preg_replace($pattern, "REVERB_APP_SECRET={$newSecret}", $envContent);
            file_put_contents($envPath, $envContent);
            $this->info('Reverb application secret rotated in .env successfully.');
        } else {
            $this->warn('REVERB_APP_SECRET key not found in .env');
        }
    }

    $this->info("Credential remediation complete. Rotated {$rotatedCount} staff account(s).");
})->purpose('Rotate credentials for staff accounts and Reverb application secret');

Artisan::command('audit:users', function () {
    $this->info('Listing active users:');
    foreach (User::with('roles')->get() as $u) {
        $roles = $u->roles->pluck('slug')->implode(', ') ?: 'no roles';
        $active = $u->is_active ? 'active' : 'inactive';
        $this->line("ID: {$u->id} | Email: {$u->email} | Status: {$active} | Roles: [{$roles}]");
    }
})->purpose('Audit registered user accounts without exposing credentials');
