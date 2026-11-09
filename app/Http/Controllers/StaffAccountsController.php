<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\FuneralRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StaffAccountsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('staff-users', [
            'users' => User::query()->orderBy('phone')->get(['id', 'name', 'phone', 'role', 'active']),
            'roles' => User::ROLES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['phone' => User::normalizePhone($request->input('phone'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'unique:users,phone'],
            'role' => ['required', Rule::in(User::ROLES)],
            'active' => ['required', 'boolean'],
            'password' => User::passwordRules(),
        ]);

        DB::transaction(function () use ($request, $data) {
            $actor = User::query()->whereKey($request->user()?->getAuthIdentifier())->lockForUpdate()->firstOrFail();
            abort_unless($actor->access_version === $request->session()->get('staff_access_version'), 403);
            Gate::forUser($actor)->authorize('manage', User::class);
            $user = User::query()->create($data);
            $user->recordStaffChange($actor, null);
        });

        return to_route('staff.users');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        if ($request->has('phone')) {
            $request->merge(['phone' => User::normalizePhone($request->input('phone'))]);
        }

        $data = $request->validate([
            'phone' => ['sometimes', 'required', Rule::unique('users')->ignore($user)],
            'role' => ['required', Rule::in(User::ROLES)],
            'active' => ['required', 'boolean'],
            'password' => ['nullable', ...array_slice(User::passwordRules(), 1)],
        ]);

        DB::transaction(function () use ($request, $user, $data) {
            $actorId = $request->user()?->getAuthIdentifier();
            $locked = User::query()->whereIn('id', [$actorId, $user->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $locked->get($actorId);
            $target = $locked->get($user->id);
            abort_unless($actor instanceof User && $target instanceof User, 404);
            abort_unless($actor->access_version === $request->session()->get('staff_access_version'), 403);
            Gate::forUser($actor)->authorize('manage', User::class);

            if ($actor->is($target) && ($data['role'] !== 'superadmin' || ! $data['active'])) {
                throw ValidationException::withMessages(['role' => 'Anda tidak dapat menurunkan peran atau menonaktifkan akun sendiri.']);
            }

            if ((! $data['active'] || ! in_array($data['role'], FuneralRequests::HANDLERS, true))
                && DB::table('funeral_requests')->where('responsible_user_id', $target->id)->whereNotIn('status', FuneralRequests::FINAL)->exists()) {
                throw ValidationException::withMessages(['active' => 'Alihkan pengajuan aktif sebelum mengubah akses penanggung jawab.']);
            }

            $before = $target->only(['role', 'active']);
            $passwordChanged = filled($data['password'] ?? null);
            if (! $passwordChanged) {
                unset($data['password']);
            }

            $target->fill($data);
            if ($target->isDirty()) {
                $target->access_version++;
                $target->save();
                $target->recordStaffChange($actor, $before, $passwordChanged);
            }
        });

        return to_route('staff.users');
    }
}
