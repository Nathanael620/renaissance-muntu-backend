<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'category', 'type', 'cover_url', 'external_url', 'status', 'metadata'])]
class LibraryResource extends Model
{
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
