<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sets exactly what one employee may see and do. The role keeps its defaults; for this user the
 * difference is stored as extra grants (direct permissions) and removals (denied_permissions).
 */
class PermissionService
{
    public function __construct(private Audit $audit) {}

    /** What the user can do now, permission by permission. */
    public function effective(User $user): array
    {
        return array_values(array_filter(array_keys(Permissions::ALL), fn (string $p) => $user->hasPermissionTo($p)));
    }

    /**
     * @param  array<int, string>  $chosen  every permission the user should have, nothing more
     */
    public function apply(User $user, array $chosen): User
    {
        $actor = Auth::user();
        if (! $actor?->can('users.manage')) {
            throw new AuthorizationException('ليست لديك صلاحية إدارة الصلاحيات.');
        }
        if ($user->isAdmin()) {
            throw new BusinessRuleException('المدير يملك كل الصلاحيات ولا تُعدَّل صلاحياته من هنا.');
        }
        if ($user->is($actor)) {
            throw new BusinessRuleException('لا يمكنك تعديل صلاحياتك بنفسك.');
        }
        $chosen = array_values(array_intersect(array_keys(Permissions::ALL), array_unique($chosen)));
        $before = $this->effective($user);
        if (! $actor->isAdmin()) {
            // Someone who manages users but is not the admin can only hand out what they hold themselves.
            $beyond = array_diff(array_diff($chosen, $before), array_filter($chosen, fn ($p) => $actor->can($p)));
            if ($beyond !== []) {
                throw new BusinessRuleException('لا يمكنك منح صلاحيات لا تملكها: '.implode('، ', array_map(fn ($p) => Permissions::ALL[$p][0], $beyond)));
            }
        }

        return DB::transaction(function () use ($user, $chosen, $before) {
            $fromRoles = $user->getPermissionsViaRoles()->pluck('name')->all();
            $user->syncPermissions(array_values(array_diff($chosen, $fromRoles)));
            $user->forceFill(['denied_permissions' => array_values(array_intersect(array_diff($fromRoles, $chosen), array_keys(Permissions::ALL)))])->save();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $user->unsetRelation('permissions')->unsetRelation('roles');

            $added = array_values(array_diff($chosen, $before));
            $removed = array_values(array_diff($before, $chosen));
            $this->audit->log('user.permissions_changed', $user, ['removed' => $removed], ['added' => $added, 'count' => count($chosen)]);

            return $user;
        });
    }

    /** Back to the role's defaults: no extra grants, nothing removed. */
    public function reset(User $user): User
    {
        return $this->apply($user, $user->getPermissionsViaRoles()->pluck('name')->all());
    }
}
