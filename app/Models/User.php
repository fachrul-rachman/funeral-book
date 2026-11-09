<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Closure;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property string $id
 * @property string $role
 * @property bool $active
 * @property int $access_version
 * @property string $name
 * @property string $phone
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'phone', 'password', 'role', 'active'])]
#[Hidden(['password', 'remember_token', 'access_version'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    public const ROLES = ['admin', 'pic', 'manager', 'superadmin'];

    protected $attributes = ['role' => 'pic', 'active' => false, 'access_version' => 0];

    public static function normalizePhone(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 40) {
            return null;
        }

        $phone = preg_replace('/[ ()-]+/', '', trim($value)) ?? '';
        if (str_starts_with($phone, '08')) {
            $phone = '+62'.substr($phone, 1);
        } elseif (str_starts_with($phone, '628')) {
            $phone = '+'.$phone;
        }

        return preg_match('/^\+[1-9][0-9]{7,14}$/D', $phone) ? $phone : null;
    }

    /** @return list<string|Closure(string, mixed, Closure): void> */
    public static function passwordRules(): array
    {
        return [
            'required', 'string', 'min:8', 'max:72',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
                    $fail('Kata sandi tidak dapat digunakan.');
                }
            },
        ];
    }

    /** @param array<string, mixed>|null $before */
    public function recordStaffChange(?User $actor, ?array $before, bool $passwordChanged = false): void
    {
        DB::table('staff_account_audits')->insert([
            'actor_id' => $actor?->id,
            'user_id' => $this->id,
            'previous_role' => $before['role'] ?? null,
            'previous_active' => $before['active'] ?? null,
            'role' => $this->role,
            'active' => $this->active,
            'password_changed' => $passwordChanged,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'active' => 'boolean',
            'access_version' => 'integer',
        ];
    }
}
