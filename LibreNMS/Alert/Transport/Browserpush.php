<?php

namespace LibreNMS\Alert\Transport;

use App\Models\User;
use App\Notifications\AlertNotification;
use Illuminate\Database\Eloquent\Collection;
use LibreNMS\Alert\Transport;
use Notification;
use Spatie\Permission\Models\Role;

class Browserpush extends Transport
{
    protected string $name = 'Browser Push';

    public function deliverAlert(array $alert_data): bool
    {
        Notification::send($this->recipients(), new AlertNotification(
            $alert_data['alert_id'],
            $alert_data['title'],
            $alert_data['msg'],
        ));

        return true;
    }

    private function recipients(): Collection
    {
        $userId = (int) ($this->config['user'] ?? 0);
        $roleId = (int) ($this->config['role'] ?? 0);

        if (! $userId && ! $roleId) {
            return User::all();
        }

        $users = new Collection;

        if ($userId) {
            $users = $users->merge(User::whereKey($userId)->get());
        }

        if ($roleId) {
            $users = $users->merge(User::role([$roleId])->get());
        }

        return $users->unique('user_id')->values();
    }

    public static function configTemplate(): array
    {
        $users = User::get(['user_id', 'username', 'realname']);
        $nameCounts = $users->countBy(fn ($user) => (string) ($user->realname ?: $user->username));
        $options = $users->mapWithKeys(function ($user) use ($nameCounts) {
            $label = (string) ($user->realname ?: $user->username);
            if ($nameCounts[$label] > 1) {
                $label .= " ($user->username)";
            }

            return [htmlentities($label) => $user->user_id];
        })->all();

        $roles = [__('None') => 0];
        foreach (Role::orderBy('name')->get(['id', 'name']) as $role) {
            $roles[htmlentities(ucwords(str_replace(['-', '_'], ' ', $role->name)))] = $role->id;
        }

        return [
            'config' => [
                [
                    'title' => 'User',
                    'name' => 'user',
                    'descr' => 'LibreNMS User. "All Users" with no role sends to everyone.',
                    'type' => 'select',
                    'options' => [__('All Users') => 0] + $options,
                ],
                [
                    'title' => 'Role',
                    'name' => 'role',
                    'descr' => 'Also send to every member of this role',
                    'type' => 'select',
                    'options' => $roles,
                ],
            ],
            'validation' => [
                'user' => 'required|zero_or_exists:users,user_id',
                'role' => 'nullable|zero_or_exists:roles,id',
            ],
        ];
    }

    public function displayDetails(): string
    {
        $userId = (int) ($this->config['user'] ?? 0);
        $roleId = (int) ($this->config['role'] ?? 0);

        if (! $userId && ! $roleId) {
            return 'All users: ' . \DB::table('push_subscriptions')->count() . ' subscriptions';
        }

        $parts = [];
        if ($userId) {
            $user = User::find($userId);
            $parts[] = $user ? "User: $user->username" : 'User not found';
        }
        if ($roleId) {
            $role = Role::find($roleId);
            $parts[] = $role ? "Role: $role->name" : 'Role not found';
        }

        $count = $this->recipients()->sum(fn (User $u) => $u->pushSubscriptions()->count());

        return implode(', ', $parts) . " ($count subscriptions)";
    }
}
