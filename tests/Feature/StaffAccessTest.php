<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->withoutVite());

test('active staff can sign in and only superadmin can manage accounts', function (string $role) {
    $user = User::factory()->create(['role' => $role, 'active' => true]);

    $this->get('/staf')->assertRedirect('/masuk');
    $this->post('/masuk', ['phone' => $user->phone, 'password' => 'password'])->assertRedirect('/staf');
    $this->get('/staf')->assertOk();
    $this->get('/staf/pengguna')->assertStatus($role === 'superadmin' ? 200 : 403);
    if ($role !== 'superadmin') {
        $this->post('/staf/pengguna')->assertForbidden();
        $this->patch('/staf/pengguna/'.$user->id)->assertForbidden();
    }
    $this->post('/keluar')->assertRedirect('/masuk');
    $this->assertGuest();
})->with(['admin', 'pic', 'manager', 'superadmin']);

test('inactive accounts cannot sign in or use an existing session', function () {
    $user = User::factory()->create();

    $this->post('/masuk', ['phone' => $user->phone, 'password' => 'password'])
        ->assertSessionHasErrors('phone');
    $this->actingAs($user)->get('/staf')->assertRedirect('/masuk');
    $this->assertGuest();
});

test('superadmin can create and deactivate accounts with an audit record', function () {
    $actor = User::factory()->create(['role' => 'superadmin', 'active' => true]);
    $this->post('/masuk', ['phone' => $actor->phone, 'password' => 'password'])->assertRedirect('/staf');
    $this->post('/staf/pengguna', [
        'name' => 'Operator Uji', 'phone' => '+6281234567890',
        'role' => 'pic', 'active' => true, 'password' => 'uji12345',
    ])->assertRedirect('/staf/pengguna');

    $staff = User::where('phone', '+6281234567890')->firstOrFail();
    $this->patch('/staf/pengguna/'.$staff->id, [
        'role' => 'manager', 'active' => false,
    ])->assertRedirect('/staf/pengguna');
    expect($staff->refresh()->role)->toBe('manager')
        ->and($staff->active)->toBeFalse();
    expect(DB::table('staff_account_audits')->where('user_id', $staff->id)->count())->toBe(2);

    $this->patch('/staf/pengguna/'.$actor->id, ['role' => 'pic', 'active' => false])
        ->assertSessionHasErrors('role');
    expect($actor->refresh()->role)->toBe('superadmin');
});
test('access changes revoke a previously authenticated session', function () {
    $user = User::factory()->create(['active' => true]);

    $this->post('/masuk', ['phone' => $user->phone, 'password' => 'password'])->assertRedirect('/staf');
    $user->increment('access_version');
    auth()->forgetGuards();
    $this->get('/staf')->assertRedirect('/masuk');
    $this->assertGuest();
});

test('a stale logout token refreshes the page without bypassing CSRF or logging out the staff', function () {
    app()->detectEnvironment(fn () => 'local');
    $user = User::factory()->create(['active' => true]);
    $token = 'csrf-fixture-valid-token';
    $this->withSession(['_token' => $token])->post('/masuk', [
        'phone' => $user->phone, 'password' => 'password', '_token' => $token,
    ])->assertRedirect('/staf');
    $this->withCookie(config('session.cookie'), app('session.store')->getId());
    $this->post('/keluar', ['_token' => 'stale-token'])->assertRedirect('/staf')
        ->assertSessionHasErrors('session');
    $this->assertAuthenticatedAs($user);
    $this->get('/staf')->assertInertia(fn (Assert $page) => $page
        ->where('errors.session', 'Halaman telah diperbarui. Klik Keluar sekali lagi.'));
    $freshToken = app('session.store')->token();
    $this->post('/keluar', ['_token' => $freshToken])->assertRedirect('/masuk');
    $this->assertGuest();
});

test('expired logout sessions return to login while other missing CSRF tokens remain rejected', function () {
    app()->detectEnvironment(fn () => 'local');
    $this->post('/keluar', ['_token' => 'expired-token'])->assertRedirect('/masuk')
        ->assertSessionHasErrors('session');
    $this->withCookie(config('session.cookie'), app('session.store')->getId());
    $this->assertGuest();
    $this->get('/masuk')->assertInertia(fn (Assert $page) => $page
        ->where('errors.session', 'Sesi Anda telah berakhir. Silakan masuk kembali.'));
    $this->post('/masuk', ['phone' => '081234567890', 'password' => 'invalid-password'])
        ->assertStatus(419);
});

test('repeated invalid passwords are throttled', function () {
    $user = User::factory()->create(['active' => true]);
    $credentials = ['phone' => $user->phone, 'password' => 'wrong-password'];

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/masuk', $credentials)->assertUnprocessable();
    }

    $this->postJson('/masuk', $credentials)->assertTooManyRequests();
    $this->assertGuest();
});

test('the initial superadmin is created interactively and audited', function () {
    $this->artisan('staff:bootstrap')
        ->expectsQuestion('Nama', 'Superadmin Uji')
        ->expectsQuestion('Nomor WhatsApp', '+6281234567891')
        ->expectsQuestion('Kata sandi (minimal 8 karakter)', 'uji12345')
        ->expectsQuestion('Ulangi kata sandi', 'uji12345')
        ->assertSuccessful();

    $owner = User::where('phone', '+6281234567891')->firstOrFail();
    expect($owner->role)->toBe('superadmin')->and($owner->active)->toBeTrue();
    $this->assertDatabaseHas('staff_account_audits', ['user_id' => $owner->id, 'actor_id' => null]);
    $this->artisan('staff:bootstrap')->assertFailed();
});

test('phone formats resolve to one account and cannot create duplicates', function () {
    $owner = User::factory()->create(['phone' => '+6281234567890', 'active' => true, 'role' => 'superadmin']);

    $this->post('/masuk', ['phone' => '0812-3456-7890', 'password' => 'password'])->assertRedirect('/staf');
    $this->post('/staf/pengguna', [
        'name' => 'Akun Uji', 'phone' => '6281234567890',
        'role' => 'pic', 'active' => true, 'password' => 'uji12345',
    ])->assertSessionHasErrors('phone');
    expect(User::query()->count())->toBe(1);

    $this->post('/staf/pengguna', [
        'name' => 'Akun Uji', 'phone' => '0812-3456-7891',
        'role' => 'pic', 'active' => true, 'password' => '1234567',
    ])->assertSessionHasErrors('password');
});
