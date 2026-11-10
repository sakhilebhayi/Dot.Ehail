<?php

namespace App\Domain\Geo\Models;

use App\Domain\Geo\Models\Concerns\HasPolygon;
use Database\Factories\ServiceAreaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A market Dot.Ehail operates in: country, currency, timezone and its boundary.
 *
 * @property list<array{0: float, 1: float}> $polygon
 */
class ServiceArea extends Model
{
    /** @use HasFactory<ServiceAreaFactory> */
    use HasFactory, HasPolygon;

    protected $fillable = [
        'slug', 'name', 'country_code', 'currency', 'timezone', 'polygon', 'is_active', 'is_approximate', 'source_note',
    ];

    protected function casts(): array
    {
        return [
            'polygon' => 'array',
            'is_active' => 'boolean',
            'is_approximate' => 'boolean',
            'min_lat' => 'float',
            'min_lng' => 'float',
            'max_lat' => 'float',
            'max_lng' => 'float',
        ];
    }

    protected static function newFactory(): ServiceAreaFactory
    {
        return ServiceAreaFactory::new();
    }

    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }
}
