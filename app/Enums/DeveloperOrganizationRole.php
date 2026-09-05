<?php

namespace App\Enums;

enum DeveloperOrganizationRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Developer = 'developer';
    case Billing = 'billing';
    case Viewer = 'viewer';
}
