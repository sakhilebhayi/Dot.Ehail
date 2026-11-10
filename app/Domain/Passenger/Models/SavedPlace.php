<?php

namespace App\Domain\Passenger\Models;

use App\Models\User;
use Database\Factories\Passenger\SavedPlaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A place the passenger saved (home, work, or another named place). One home and one work per passenger;
 * at most MAX_OTHER other places (D-801).
 *
 * @property int $id
 * @property int $user_id
 * @property string $kind
 * @property string $label
 * @property string $address
 * @property string $lat
 * @property string $lng
 */
class SavedPlace extends Model
{
    /** @use HasFactory<SavedPlaceFactory> */
    use HasFactory;

    public const KINDS = ['home', 'work', 'other'];

    public const MAX_OTHER = 8;

    protected $table = 'passenger_saved_places';

    protected $fillable = ['user_id', 'kind', 'label', 'address', 'lat', 'lng'];

    protected static function newFactory(): SavedPlaceFactory
    {
        return SavedPlaceFactory::new();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{id: int, kind: string, label: string, address: string, lat: float, lng: float}
     */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'label' => $this->label,
            'address' => $this->address,
            'lat' => round((float) $this->lat, 6),
            'lng' => round((float) $this->lng, 6),
        ];
    }
}
