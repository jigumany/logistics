<?php

use App\Models\User;

it('authenticates valid users', function (): void {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse-battery'])->assertRedirect('/companies');
    $this->assertAuthenticatedAs($user);
});
it('rejects invalid credentials', function (): void {
    $user = User::factory()->create();
    $this->post('/login', ['email' => $user->email, 'password' => 'incorrect'])->assertSessionHasErrors('email');
    $this->assertGuest();
});
it('rate limits repeated login attempts', function (): void {
    for ($i = 0; $i < 5; $i++) {
        $this->post('/login', ['email' => 'missing@example.test', 'password' => 'wrong']);
    }
    $this->post('/login', ['email' => 'missing@example.test', 'password' => 'wrong'])->assertStatus(429);
});
it('invalidates authentication on logout', function (): void {
    $this->actingAs(User::factory()->create())->post('/logout')->assertRedirect('/login');
    $this->assertGuest();
});
