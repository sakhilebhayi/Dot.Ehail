<?php

namespace App\Domain\Geo\Models;

use App\Domain\Geo\Models\Concerns\HasPolygon;
use Database\Factories\ZoneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shape inside (or across) a service area with a pricing or policy meaning.
 *
 * @property list<array{0: float, 1: float}> $polygon
 */
class Zone extends Model
{
    /** @use HasFactory<ZoneFactory> */
    use HasFactory, HasPolygon;

    public const KINDS = ['airport', 'special', 'restricted', 'surge_eligible'];

    protected $fillable = [
        'service_area_id', 'slug', 'name', 'kind', 'polygon', 'fee_code', 'meta', 'is_active', 'is_approximate', 'source_note',
    ];

    protected function casts(): array
    {
        return [
            'polygon' => 'array',
            'meta' => 'array',
            'is_active' => 'boolean',
            'is_approximate' => 'boolean',
            'min_lat' => 'float',
            'min_lng' => 'float',
            'max_lat' => 'float',
            'max_lng' => 'float',
        ];
    }

    protected static function newFactory(): ZoneFactory
    {
        return ZoneFactory::new();
    }

    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }
}
