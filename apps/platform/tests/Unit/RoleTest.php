<?php

use App\Domain\Identity\Role;

it('restricts settings management to owners and administrators', function (): void {
    expect(Role::Owner->managesCompany())->toBeTrue()->and(Role::Admin->managesCompany())->toBeTrue();
    foreach ([Role::Viewer, Role::Driver, Role::Dispatcher] as $role) {
        expect($role->managesCompany())->toBeFalse();
    }
});
