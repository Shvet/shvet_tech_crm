<?php

namespace App\Enums;

enum RoleName: string
{
    case Admin = 'Admin';
    case SalesManager = 'Sales Manager';
    case SalesAgent = 'Sales Agent';

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            self::SalesManager => [
                Permission::DashboardView,
                Permission::LeadsViewTeam, Permission::LeadsCreate, Permission::LeadsUpdate,
                Permission::LeadsAssign, Permission::LeadsBulkUpdate, Permission::LeadsExport,
                Permission::TasksManage, Permission::ConversationsView, Permission::ConversationsReply,
                Permission::ReportsView, Permission::UsersView,
            ],
            self::SalesAgent => [
                Permission::DashboardView,
                Permission::LeadsViewOwn, Permission::LeadsCreate, Permission::LeadsUpdate,
                Permission::TasksManage, Permission::ConversationsView, Permission::ConversationsReply,
            ],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }
}
