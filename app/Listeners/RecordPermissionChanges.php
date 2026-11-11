<?php

namespace App\Listeners;

use App\Services\Audit\PlatformAuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;

/**
 * Role / permission assignments (spatie events) -> platform_audit.
 */
class RecordPermissionChanges
{
    public function __construct(private PlatformAuditLogger $audit) {}

    public function handle(RoleAttachedEvent|RoleDetachedEvent|PermissionAttachedEvent|PermissionDetachedEvent $event): void
    {
        [$action, $items] = match (true) {
            $event instanceof RoleAttachedEvent => ['role.assigned', $event->rolesOrIds],
            $event instanceof RoleDetachedEvent => ['role.removed', $event->rolesOrIds],
            $event instanceof PermissionAttachedEvent => ['permission.granted', $event->permissionsOrIds],
            default => ['permission.revoked', $event->permissionsOrIds],
        };

        $this->audit->record($action, $event->model, ['items' => self::names($items)]);
    }

    /** @return list<string|int> */
    private static function names(mixed $items): array
    {
        $items = $items instanceof Collection ? $items->all() : (is_array($items) ? $items : [$items]);

        return array_values(array_map(fn ($i) => $i instanceof Model ? ($i->getAttribute('name') ?? $i->getKey()) : $i, $items));
    }
}
