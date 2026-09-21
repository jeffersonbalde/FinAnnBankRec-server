<?php

namespace App\Models;

use Database\Factories\ReferenceUacsFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReferenceUacs extends Model
{
    /** @use HasFactory<ReferenceUacsFactory> */
    use HasFactory;

    protected $table = 'reference_uacs';

    protected $fillable = [
        'code',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
