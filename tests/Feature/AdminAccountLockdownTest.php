<?php

use App\Models\User;

/**
 * The system is locked to exactly one Admin account — there is no
 * in-app way to create a second one, not even by posting the form field
 * directly (the dropdown option is gone too, see admin/users.blade.php).
 */
test('creating a user with role=admin is rejected server-side, not just hidden from the form', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->post(route('admin.users.store'), [
        'username' => 'sneaky_admin',
        'full_name' => 'Sneaky Admin',
        'email' => 'sneaky@example.test',
        'role' => 'admin',
    ]);

    $response->assertSessionHasErrors('role');
    expect(User::where('username', 'sneaky_admin')->exists())->toBeFalse();
});

test('the Create Account form no longer offers an Admin role option', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.users'));

    $response->assertOk()
        ->assertSee('Originator')
        ->assertSee('Approver')
        ->assertDontSee('<option value="admin"', false);
});
