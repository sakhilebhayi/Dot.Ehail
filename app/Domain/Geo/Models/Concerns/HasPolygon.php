<?php

namespace App\Domain\Geo\Models\Concerns;

use App\Domain\Geo\Geometry\Polygon;
use App\Domain\Geo\LatLng;
use Illuminate\Database\Eloquent\Builder;

/**
 * A model with a JSON polygon column and a stored bounding box kept in step with it on save.
 */
trait HasPolygon
{
    protected static function bootHasPolygon(): void
    {
        static::saving(function ($model): void {
            $shape = Polygon::fromPairs($model->polygon ?? []);
            $model->polygon = $shape->toPairs();
            $model->min_lat = $shape->bbox->minLat;
            $model->min_lng = $shape->bbox->minLng;
            $model->max_lat = $shape->bbox->maxLat;
            $model->max_lng = $shape->bbox->maxLng;
        });
    }

    public function shape(): Polygon
    {
        return Polygon::fromPairs($this->polygon ?? []);
    }

    public function containsPoint(LatLng $point): bool
    {
        return $this->shape()->contains($point);
    }

    /**
     * Rows whose bounding box contains the point (the prefilter; exact containment is checked in PHP).
     */
    public function scopeBoxContaining(Builder $query, LatLng $point): Builder
    {
        return $query->where('min_lat', '<=', $point->lat)->where('max_lat', '>=', $point->lat)
            ->where('min_lng', '<=', $point->lng)->where('max_lng', '>=', $point->lng);
    }
}
