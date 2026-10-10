<?php

namespace App\Filament\Resources\Organizations\Pages;

use App\Filament\Resources\Organizations\OrganizationActions;
use App\Filament\Resources\Organizations\OrganizationResource;
use Filament\Resources\Pages\ViewRecord;

class ViewOrganization extends ViewRecord
{
    protected static string $resource = OrganizationResource::class;

    protected function getHeaderActions(): array
    {
        return [OrganizationActions::suspend(), OrganizationActions::reactivate()];
    }
}
