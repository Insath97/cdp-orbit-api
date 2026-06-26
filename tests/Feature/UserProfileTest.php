<?php

use App\Models\User;
use App\Models\Employee;
use App\Models\Designation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('allows authenticated user to fetch their details', function () {
    $user = User::factory()->create([
        'username' => 'testadmin',
        'user_type' => 'admin',
    ]);

    $response = $this->actingAs($user, 'api')
        ->getJson('/api/v1/me');

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.user.username', 'testadmin');
});

it('allows authenticated user to update name and email', function () {
    $user = User::factory()->create([
        'name' => 'Original Name',
        'email' => 'original@example.com',
        'user_type' => 'admin',
    ]);

    $response = $this->actingAs($user, 'api')
        ->patchJson('/api/v1/me', [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.user.name', 'Updated Name')
        ->assertJsonPath('data.user.email', 'updated@example.com');

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'name' => 'Updated Name',
        'email' => 'updated@example.com',
    ]);
});

it('allows authenticated user to update password when current password is provided', function () {
    $user = User::factory()->create([
        'password' => Hash::make('old_password'),
        'user_type' => 'admin',
    ]);

    $response = $this->actingAs($user, 'api')
        ->patchJson('/api/v1/me', [
            'current_password' => 'old_password',
            'password' => 'new_password',
            'password_confirmation' => 'new_password',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success');

    $user->refresh();
    $this->assertTrue(Hash::check('new_password', $user->password));
});

it('does not allow password update with incorrect current password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('old_password'),
        'user_type' => 'admin',
    ]);

    $response = $this->actingAs($user, 'api')
        ->patchJson('/api/v1/me', [
            'current_password' => 'wrong_password',
            'password' => 'new_password',
            'password_confirmation' => 'new_password',
        ]);

    $response->assertStatus(422)
        ->assertJsonStructure(['message', 'errors']);
});

it('allows staff user to update employee-specific details and syncs name/email', function () {
    // Need a designation to satisfy foreign key constraints in employees table
    $designation = Designation::create([
        'name' => 'Software Engineer',
    ]);

    $employee = Employee::create([
        'f_name' => 'John',
        'l_name' => 'Doe',
        'full_name' => 'John Doe',
        'employee_code' => 'EMP001',
        'id_type' => 'nic',
        'id_number' => '123456789V',
        'phone_primary' => '0771234567',
        'email' => 'john.doe@example.com',
        'designation_id' => $designation->id,
        'employee_type' => 'permanent',
    ]);

    $user = User::factory()->create([
        'name' => 'John Doe',
        'username' => 'john_doe',
        'email' => 'john.doe@example.com',
        'user_type' => 'staff',
        'employee_id' => $employee->id,
    ]);

    $response = $this->actingAs($user, 'api')
        ->patchJson('/api/v1/me', [
            'f_name' => 'Johnny',
            'l_name' => 'Smith',
            'email' => 'johnny.smith@example.com',
            'phone_primary' => '0779876543',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.user.name', 'Johnny Smith')
        ->assertJsonPath('data.user.email', 'johnny.smith@example.com');

    $this->assertDatabaseHas('employees', [
        'id' => $employee->id,
        'f_name' => 'Johnny',
        'l_name' => 'Smith',
        'full_name' => 'Johnny Smith',
        'email' => 'johnny.smith@example.com',
        'phone_primary' => '0779876543',
    ]);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'name' => 'Johnny Smith',
        'email' => 'johnny.smith@example.com',
    ]);
});

it('returns validation errors for invalid inputs', function () {
    $user = User::factory()->create([
        'user_type' => 'admin',
    ]);

    $response = $this->actingAs($user, 'api')
        ->patchJson('/api/v1/me', [
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'mismatch',
        ]);

    $response->assertStatus(422)
        ->assertJsonStructure(['message', 'errors']);
});

it('rejects unauthenticated profile updates', function () {
    $response = $this->patchJson('/api/v1/me', [
        'name' => 'New Name',
    ]);

    $response->assertStatus(401);
});
