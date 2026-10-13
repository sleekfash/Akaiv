<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArchiveAuditEvent extends Model
{
    protected $table = 'archive_audit_events';

    protected $primaryKey = 'sequence';

    public $timestamps = false;

    protected $guarded = ['*'];

    public function getIncrementing()
    {
        return false;
    }
}
