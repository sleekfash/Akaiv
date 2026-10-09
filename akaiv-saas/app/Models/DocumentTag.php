<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class DocumentTag extends Pivot
{
    use HasFactory;

    protected $table = 'document_tag';

    protected $fillable = [
        'document_id',
        'tag_id',
        'tagged_by',
        'auto_tagged',
    ];

    protected $casts = [
        'auto_tagged' => 'boolean',
    ];

    public $timestamps = true;

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    public function taggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tagged_by');
    }
}
