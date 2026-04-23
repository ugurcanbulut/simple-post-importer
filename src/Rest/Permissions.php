<?php

declare(strict_types=1);

namespace SimplePostImporter\Rest;

final class Permissions
{
    public static function manageOptions(): bool
    {
        return current_user_can('manage_options');
    }
}
