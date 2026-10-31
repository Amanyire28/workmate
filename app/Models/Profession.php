<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Profession extends Model
{
    protected $fillable = [
        'category_id',
        'slug',
        'name',
        'plural_name',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->whereHas('category', function ($category) {
            $category->where('is_active', true);
        });
    }
}
