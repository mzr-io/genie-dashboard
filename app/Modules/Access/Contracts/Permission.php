<?php

namespace App\Modules\Access\Contracts;

/**
 * The closed set of Admin permissions a membership can hold (Story 1.12 defines it; Story 1.19 enforces it).
 * The same list is a CHECK constraint on `membership_permissions.permission`.
 */
enum Permission: string
{
    case DataSourcesManage = 'data_sources.manage';
    case BlocksEdit = 'blocks.edit';
    case BlocksPublish = 'blocks.publish';
    case TemplatesManage = 'templates.manage';
    case UsersManage = 'users.manage';
    case SettingsManage = 'settings.manage';
    case AuditView = 'audit.view';
    case DataPreviewAsUser = 'data.preview_as_user';
    case AccessManage = 'access.manage';

    /**
     * Every permission, as the values stored in `membership_permissions`.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
