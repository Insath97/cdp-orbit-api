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

it('shows all recursive subordinates (children and grandchildren) for a parent user in reports', function () {
    // Clear Spatie Permission cache
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    // Grant the Report Hierarchy permission
    $permission = \Spatie\Permission\Models\Permission::firstOrCreate([
        'name' => 'Report Hierarchy',
        'group_name' => 'Report Management Permissions',
        'guard_name' => 'api'
    ]);

    // Setup necessary models
    $department = \App\Models\Department::create([
        'name' => 'Sales',
        'code' => 'SALES',
    ]);

    $designation = Designation::create([
        'name' => 'Staff',
        'code' => 'STAFF',
        'level' => 'mid',
        'department_id' => $department->id,
    ]);

    // Parent
    $employeeParent = Employee::create([
        'f_name' => 'Parent',
        'l_name' => 'User',
        'full_name' => 'Parent User',
        'employee_code' => 'EMP_P',
        'id_type' => 'nic',
        'id_number' => '111111111V',
        'phone_primary' => '0771111111',
        'email' => 'parent@example.com',
        'designation_id' => $designation->id,
        'employee_type' => 'permanent',
    ]);
    $userParent = User::factory()->create([
        'name' => 'Parent User',
        'username' => 'parent_user',
        'email' => 'parent@example.com',
        'user_type' => 'staff',
        'employee_id' => $employeeParent->id,
    ]);
    $userParent->givePermissionTo($permission);

    // Child (reports to Parent)
    $employeeChild = Employee::create([
        'f_name' => 'Child',
        'l_name' => 'User',
        'full_name' => 'Child User',
        'employee_code' => 'EMP_C',
        'id_type' => 'nic',
        'id_number' => '222222222V',
        'phone_primary' => '0772222222',
        'email' => 'child@example.com',
        'designation_id' => $designation->id,
        'employee_type' => 'permanent',
        'reporting_manager_id' => $employeeParent->id,
    ]);
    $userChild = User::factory()->create([
        'name' => 'Child User',
        'username' => 'child_user',
        'email' => 'child@example.com',
        'user_type' => 'staff',
        'employee_id' => $employeeChild->id,
    ]);

    // Grandchild (reports to Child)
    $employeeGrandchild = Employee::create([
        'f_name' => 'Grandchild',
        'l_name' => 'User',
        'full_name' => 'Grandchild User',
        'employee_code' => 'EMP_GC',
        'id_type' => 'nic',
        'id_number' => '333333333V',
        'phone_primary' => '0773333333',
        'email' => 'grandchild@example.com',
        'designation_id' => $designation->id,
        'employee_type' => 'permanent',
        'reporting_manager_id' => $employeeChild->id,
    ]);
    $userGrandchild = User::factory()->create([
        'name' => 'Grandchild User',
        'username' => 'grandchild_user',
        'email' => 'grandchild@example.com',
        'user_type' => 'staff',
        'employee_id' => $employeeGrandchild->id,
    ]);

    // Independent employee (not in the hierarchy)
    $employeeOther = Employee::create([
        'f_name' => 'Other',
        'l_name' => 'User',
        'full_name' => 'Other User',
        'employee_code' => 'EMP_O',
        'id_type' => 'nic',
        'id_number' => '444444444V',
        'phone_primary' => '0774444444',
        'email' => 'other@example.com',
        'designation_id' => $designation->id,
        'employee_type' => 'permanent',
    ]);
    $userOther = User::factory()->create([
        'name' => 'Other User',
        'username' => 'other_user',
        'email' => 'other@example.com',
        'user_type' => 'staff',
        'employee_id' => $employeeOther->id,
    ]);

    // Create a stage and status for leads
    $stage = \App\Models\LeadStage::create([
        'name' => 'Inquiry',
        'sort_order' => 1,
        'is_active' => true,
    ]);
    $status = \App\Models\Status::create([
        'name' => 'New',
        'sort_order' => 1,
        'color_code' => '#3B82F6',
        'lead_stage_id' => $stage->id,
        'is_active' => true,
    ]);

    // Assign leads
    \App\Models\Lead::create([
        'name' => 'Lead for Child',
        'phone_primary' => '0770000001',
        'status_id' => $status->id,
        'created_by' => $userChild->id,
    ]);
    \App\Models\Lead::create([
        'name' => 'Lead for Grandchild',
        'phone_primary' => '0770000002',
        'status_id' => $status->id,
        'created_by' => $userGrandchild->id,
    ]);
    \App\Models\Lead::create([
        'name' => 'Lead for Other',
        'phone_primary' => '0770000003',
        'status_id' => $status->id,
        'created_by' => $userOther->id,
    ]);

    // 2. Perform Request as Parent
    $response = $this->actingAs($userParent, 'api')
        ->getJson('/api/v1/reports/employee-hierarchy');

    // 3. Verify Response
    if ($response->status() !== 200) {
        throw new \Exception("Request failed with status " . $response->status() . " and body: " . $response->getContent());
    }

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success');

    $data = $response->json('data');

    // Root level should contain only the direct subordinate (Child User)
    expect($data)->toHaveCount(1);
    expect($data[0]['employee_name'])->toBe('Child User');
    expect($data[0]['leads_count'])->toBe(1);

    // Subordinates of Child User should contain Grandchild User
    expect($data[0]['subordinates'])->toHaveCount(1);
    expect($data[0]['subordinates'][0]['employee_name'])->toBe('Grandchild User');
    expect($data[0]['subordinates'][0]['leads_count'])->toBe(1);

    // Grandchild should have no subordinates
    expect($data[0]['subordinates'][0]['subordinates'])->toBeEmpty();
});
