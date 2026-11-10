<?php

namespace App\Domain\Catalog\Models;

use App\Models\Vehicle;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One way a vehicle can qualify for a category. A vehicle qualifies when ANY rule of the category matches.
 * A requirement the vehicle record cannot answer (doors and features are not recorded on vehicles yet) counts as
 * not met: a category is never served by a vehicle we cannot show meets it.
 *
 * @property int $id
 * @property int $ride_category_id
 * @property array $vehicle_types
 * @property ?int $min_year
 * @property ?int $max_age_years
 * @property ?int $min_doors
 * @property ?int $min_seats
 * @property ?array $required_features
 */
class CategoryVehicleRule extends Model
{
    protected $fillable = [
        'ride_category_id', 'vehicle_types', 'min_year', 'max_age_years', 'min_doors', 'min_seats', 'required_features', 'notes',
    ];

    protected $casts = [
        'vehicle_types' => 'array',
        'required_features' => 'array',
        'min_year' => 'integer',
        'max_age_years' => 'integer',
        'min_doors' => 'integer',
        'min_seats' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(RideCategory::class, 'ride_category_id');
    }

    /**
     * @return list<string> reasons the vehicle fails this rule (empty = it qualifies)
     */
    public function failuresFor(Vehicle $vehicle, CarbonInterface $at): array
    {
        $failures = [];

        if (! in_array($vehicle->type, $this->vehicle_types ?? [], true)) {
            $failures[] = 'vehicle_class';
        }

        $year = (int) $vehicle->year;

        if ($this->min_year !== null && $year < $this->min_year) {
            $failures[] = 'too_old';
        }

        if ($this->max_age_years !== null && ((int) $at->year - $year) > $this->max_age_years) {
            $failures[] = 'too_old';
        }

        if ($this->min_seats !== null && (int) $vehicle->seats < $this->min_seats) {
            $failures[] = 'too_few_seats';
        }

        $doors = $vehicle->getAttribute('doors');

        if ($this->min_doors !== null && ($doors === null || (int) $doors < $this->min_doors)) {
            $failures[] = 'doors_unknown_or_too_few';
        }

        $features = $vehicle->getAttribute('features');
        $features = is_string($features) ? (json_decode($features, true) ?: []) : (is_array($features) ? $features : []);

        foreach ($this->required_features ?? [] as $feature) {
            if (! in_array($feature, $features, true)) {
                $failures[] = 'missing_feature:'.$feature;
            }
        }

        return array_values(array_unique($failures));
    }
}
