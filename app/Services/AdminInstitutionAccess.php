<?php
namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use App\Models\User;

class AdminInstitutionAccess
{
    public static function restricted(): bool
    {
        $user = auth()->user();
        return $user && !$user->isSuperAdmin() && $user->hasAnyRole(['Admin', 'Admin Nacional'])
            && request()->routeIs('admin.*', 'catalog.*', 'security.*', 'applications.control.*', 'announcements.*');
    }

    public static function institutionId(): int
    {
        // An administrator without an institution must never receive global access.
        return (int) (auth()->user()?->institution_id ?? 0);
    }

    public static function users(Builder $query): Builder
    {
        return self::restricted() ? $query->where('users.institution_id', self::institutionId()) : $query;
    }

    public static function evaluator(int $id): User
    {
        return self::users(User::role('Evaluador'))->findOrFail($id);
    }
}
