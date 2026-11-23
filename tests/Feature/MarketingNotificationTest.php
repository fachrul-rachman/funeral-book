<?php

use App\Models\User;
use App\Services\FuneralRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    Http::preventStrayRequests();
});

test('only an active superadmin may update the normalized optional marketing number', function (string $role) {
    $actor = User::factory()->create(['role' => $role, 'active' => true]);
    $this->post('/masuk', ['phone' => $actor->phone, 'password' => 'password']);
    $url = '/staf/pengaturan/notifikasi-marketing';
    $response = $this->patch($url, ['marketing_whatsapp' => '081234567899']);
    if ($role !== 'superadmin') {
        $response->assertForbidden();
        $this->assertDatabaseHas('notification_settings', ['id' => 1, 'marketing_whatsapp_e164' => null]);

        return;
    }
    $response->assertRedirect('/staf/pengguna');
    $this->get('/staf/pengguna')->assertInertia(fn (Assert $page) => $page->where('marketingWhatsApp', '+6281234567899'));
    $this->assertDatabaseHas('notification_settings', ['id' => 1, 'marketing_whatsapp_e164' => '+6281234567899', 'updated_by' => $actor->id]);
    $this->patch($url, ['marketing_whatsapp' => 'letters'])->assertSessionHasErrors('marketing_whatsapp');
    $this->assertDatabaseHas('notification_settings', ['id' => 1, 'marketing_whatsapp_e164' => '+6281234567899']);
    $this->patch($url, ['marketing_whatsapp' => ''])->assertRedirect('/staf/pengguna');
    $this->assertDatabaseHas('notification_settings', ['id' => 1, 'marketing_whatsapp_e164' => null]);
})->with(['pic', 'manager', 'admin', 'superadmin']);

test('marketing receives only requests without a plot and never duplicates a staff destination', function (bool $hasPlot, bool $sameAsStaff, int $marketingCount) {
    $staff = User::factory()->create(['active' => true, 'role' => 'pic', 'phone' => '+6281234567891']);
    DB::table('notification_settings')->where('id', 1)->update([
        'marketing_whatsapp_e164' => $sameAsStaff ? $staff->phone : '+6281234567899',
    ]);
    app(FuneralRequests::class)->submit([
        'applicant_name' => 'Pemohon Uji', 'applicant_whatsapp' => '081234567890',
        'relationship_to_deceased' => 'Anak', 'deceased_name' => 'Almarhum Uji',
        'deceased_date_of_death' => '2025-01-01', 'funeral_home_location' => 'Rumah Duka Uji',
        'has_plot' => $hasPlot, 'estimated_burial_date' => now('Asia/Jakarta')->addDay()->toDateString(),
        'estimated_burial_time' => '10:00', 'consent_accepted' => true,
    ]);
    expect(DB::table('notification_deliveries')->where('recipient_role', 'marketing')->count())->toBe($marketingCount);
    expect(DB::table('notification_deliveries')->where('event_type', 'new_request')->where('destination_e164', $staff->phone)->count())->toBe(1);
    if ($marketingCount === 1) {
        $row = DB::table('notification_deliveries')->where('recipient_role', 'marketing')->sole();
        expect($row->event_type)->toBe('new_request')->and($row->template_name)->toBe(config('funeral.templates.new_request'))
            ->and(json_decode($row->template_parameters, true))->toHaveCount(12);
        DB::table('notification_settings')->where('id', 1)->update(['marketing_whatsapp_e164' => '+6281234567898']);
        expect(DB::table('notification_deliveries')->where('id', $row->id)->value('destination_e164'))->toBe('+6281234567899');
    }
    Http::assertNothingSent();
})->with([[false, false, 1], [true, false, 0], [false, true, 0]]);
