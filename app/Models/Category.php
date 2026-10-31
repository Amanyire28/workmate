<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'icon',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function professions()
    {
        return $this->hasMany(Profession::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
