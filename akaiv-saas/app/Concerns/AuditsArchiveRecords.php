<?php

namespace App\Concerns;

use App\Services\ArchiveAudit;

trait AuditsArchiveRecords
{
    public static function bootAuditsArchiveRecords(): void
    {
        foreach (['created', 'updated', 'deleted', 'restored'] as $action) {
            static::$action(function ($record) use ($action) {
                app(ArchiveAudit::class)->append((int) $record->organization_id, auth()->id(), strtoupper(class_basename($record).'_'.$action), get_class($record), (string) $record->getKey(), ['changed_fields' => array_keys($record->getChanges())]);
            });
        }
    }
}
