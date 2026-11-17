<?php

namespace App\Listeners;

use App\Services\Audit\PlatformAuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Role / permission assignments (spatie events) -> platform_audit.
 */
class RecordPermissionChanges
{
    public function __construct(private PlatformAuditLogger $audit) {}

    public function handle(RoleAttachedEvent|RoleDetachedEvent|PermissionAttachedEvent|PermissionDetachedEvent $event): void
    {
        [$action, $items, $model] = match (true) {
            $event instanceof RoleAttachedEvent => ['role.assigned', $event->rolesOrIds, Role::class],
            $event instanceof RoleDetachedEvent => ['role.removed', $event->rolesOrIds, Role::class],
            $event instanceof PermissionAttachedEvent => ['permission.granted', $event->permissionsOrIds, Permission::class],
            default => ['permission.revoked', $event->permissionsOrIds, Permission::class],
        };

        $this->audit->record($action, $event->model, ['items' => self::names($items, $model)]);
    }

    /**
     * Spatie passes models or bare ids (syncRoles / assignRole with ids), so
     * ids are resolved to names: the record must say which role, not "1".
     *
     * @param  class-string<Role|Permission>  $model
     * @return list<string|int>
     */
    public static function names(mixed $items, string $model): array
    {
        $items = $items instanceof Collection ? $items->all() : (is_array($items) ? $items : [$items]);
        $ids = array_filter($items, fn ($i) => is_int($i) || (is_string($i) && ctype_digit($i)));
        $byId = $ids === [] ? [] : $model::query()->whereKey($ids)->pluck('name', 'id')->all();

        return array_values(array_map(fn ($i) => match (true) {
            $i instanceof Model => $i->getAttribute('name') ?? $i->getKey(),
            isset($byId[$i]) => $byId[$i],
            default => $i,
        }, $items));
    }
}
