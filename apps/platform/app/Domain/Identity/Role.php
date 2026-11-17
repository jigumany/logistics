<?php

namespace App\Domain\Identity;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Dispatcher = 'dispatcher';
    case Driver = 'driver';
    case Viewer = 'viewer';

    public function managesCompany(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }
}
