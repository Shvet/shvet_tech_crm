<?php

namespace App\Enums;

/**
 * Global permission names. Roles (which are tenant-scoped) map onto these.
 * Record-level rules (own / team / all) are enforced separately inside the tenant.
 */
enum Permission: string
{
    case DashboardView = 'dashboard.view';
    case LeadsViewAll = 'leads.view_all';
    case LeadsViewTeam = 'leads.view_team';
    case LeadsViewOwn = 'leads.view_own';
    case LeadsCreate = 'leads.create';
    case LeadsUpdate = 'leads.update';
    case LeadsDelete = 'leads.delete';
    case LeadsArchive = 'leads.archive';
    case LeadsAssign = 'leads.assign';
    case LeadsBulkUpdate = 'leads.bulk_update';
    case LeadsExport = 'leads.export';
    case LeadsImport = 'leads.import';
    case TasksManage = 'tasks.manage';
    case ConversationsView = 'conversations.view';
    case ConversationsReply = 'conversations.reply';
    case ReportsView = 'reports.view';
    case PipelineManage = 'pipeline.manage';
    case SettingsManage = 'settings.manage';
    case IntegrationsManage = 'integrations.manage';
    case UsersView = 'users.view';
    case UsersManage = 'users.manage';
    case AuditView = 'audit.view';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }
}
