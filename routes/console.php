<?php

use App\Models\User;
use App\Services\VisitationPlans;
use App\Services\WhatsAppDelivery;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

Artisan::command('staff:bootstrap', function (): int {
    if (User::query()->where('role', 'superadmin')->exists()) {
        $this->error('Akun Superadmin sudah ada.');

        return 1;
    }

    $input = [
        'name' => trim((string) $this->ask('Nama')),
        'phone' => User::normalizePhone($this->ask('Nomor WhatsApp')),
        'password' => $this->secret('Kata sandi (minimal 8 karakter)', fallback: false),
        'password_confirmation' => $this->secret('Ulangi kata sandi', fallback: false),
    ];
    $validator = Validator::make($input, [
        'name' => ['required', 'string', 'max:120'],
        'phone' => ['required', 'unique:users,phone'],
        'password' => [...User::passwordRules(), 'confirmed'],
    ]);

    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $message) {
            $this->error($message);
        }

        return 1;
    }

    DB::transaction(function () use ($validator) {
        $user = User::query()->create($validator->validated() + ['role' => 'superadmin', 'active' => true]);
        $user->recordStaffChange(null, null);
    });

    $this->info('Akun Superadmin siap digunakan.');

    return 0;
})->purpose('Create the initial Superadmin account interactively');

Schedule::call(function (): void {
    DB::table('public_submission_keys')->where('expires_at', '<=', now()->utc())->delete();
})->name('expire-public-submission-keys')->daily()->withoutOverlapping();

Schedule::call(function (): void {
    $name = config('funeral.document_disk');
    $disk = Storage::disk($name);
    $cutoff = now()->subDay()->timestamp;
    foreach ($disk->getDriver()->listContents('funeral-documents', true) as $entry) {
        if (! $entry->isFile() || $entry->lastModified() === null || $entry->lastModified() >= $cutoff) {
            continue;
        }
        if (! DB::table('request_documents')->where(['storage_disk' => $name, 'object_key' => $entry->path()])->exists()) {
            $disk->delete($entry->path());
        }
    }
})->name('clean-unreferenced-funeral-documents')->daily()->withoutOverlapping();

Schedule::call(fn () => app(WhatsAppDelivery::class)->recover())
    ->name('recover-whatsapp-outbox')->everyMinute()->withoutOverlapping();

Schedule::call(fn () => app(VisitationPlans::class)->recover())
    ->name('recover-visitation-plans')->everyMinute()->withoutOverlapping();
