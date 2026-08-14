<?php

require_once 'vendor/autoload.php';

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

echo "Creating admin user...\n";

try {
    // Create or update admin user
    $admin = User::updateOrCreate(
        ['email' => 'admin@matchday-africa.com'],
        [
            'name' => 'Admin User',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_admin' => true,
            'email_verified_at' => now(),
        ]
    );

    echo "✅ Admin user created/updated successfully!\n";
    echo "Email: admin@matchday-africa.com\n";
    echo "Password: password\n";
    echo "Role: admin\n";
    echo "ID: " . $admin->id . "\n";

    // Create regular user as well
    $user = User::updateOrCreate(
        ['email' => 'user@matchday-africa.com'],
        [
            'name' => 'Regular User',
            'password' => Hash::make('password'),
            'role' => 'user',
            'is_admin' => false,
            'email_verified_at' => now(),
        ]
    );

    echo "\n✅ Regular user created/updated successfully!\n";
    echo "Email: user@matchday-africa.com\n";
    echo "Password: password\n";
    echo "Role: user\n";
    echo "ID: " . $user->id . "\n";

    echo "\n🎯 Both users are ready for testing!\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Make sure the database connection is working and migrations are run.\n";
}
