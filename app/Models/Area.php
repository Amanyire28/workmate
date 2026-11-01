<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'town',
        'district',
        'kind',
        'latitude',
        'longitude',
        'is_whole_town',
        'is_featured',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'is_whole_town' => 'boolean',
        'is_featured' => 'boolean',
        'geonames_id' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Straight-line distance in km */
    public static function distanceKm(float $lat1, float $lng1, ?float $lat2, ?float $lng2): float
    {
        if ($lat2 === null || $lng2 === null) {
            return INF;
        }
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 12742 * asin(sqrt($h));
    }
}
