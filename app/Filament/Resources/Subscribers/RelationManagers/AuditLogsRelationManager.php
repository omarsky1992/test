<?php

namespace App\Filament\Resources\Subscribers\RelationManagers;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class AuditLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'auditLogs';

    protected static ?string $title = 'الخط الزمني';

    public function table(Table $table): Table
    {
        return AuditLogResource::table($table)->filters([]);
    }
}
