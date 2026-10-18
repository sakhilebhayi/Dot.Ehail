<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handling for the two identifying numbers on a driver profile (driving
 * licence and national ID): masking for lists, the keyed "blind index" that
 * lets uniqueness still be enforced now the values are encrypted, and the
 * one-off encryption of rows written before that.
 */
class DriverIdentifiers
{
    private const COLUMNS = ['license_number', 'id_number'];

    /**
     * "•••• 8841": the last four characters of a value that is long enough
     * for that to reveal little, otherwise nothing.
     */
    public static function mask(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return mb_strlen($value) >= 8 ? '•••• '.mb_substr($value, -4) : '••••';
    }

    /**
     * HMAC-SHA256 of the value, ignoring case and surrounding whitespace, keyed
     * with the application key. Equal numbers give equal indexes (so a unique
     * index can reject duplicates) without the index revealing the number.
     * Rotating APP_KEY invalidates these; re-run the encrypt step's backfill
     * (or null the columns) if the key ever changes.
     */
    public static function blindIndex(?string $value): ?string
    {
        $normalised = mb_strtoupper(trim((string) $value));

        if ($normalised === '') {
            return null;
        }

        return hash_hmac('sha256', $normalised, 'ehail.driver-identifier-index|'.config('app.key'));
    }

    /**
     * Encrypt every plaintext identifier still in driver_profiles and fill the
     * missing blind indexes. Safe to run repeatedly: a value that already
     * decrypts is left exactly as it is. Two legacy rows whose numbers only
     * differ by case/whitespace would share an index; the later one keeps its
     * (encrypted) number but no index, and the clash is logged.
     *
     * @return array{rows: int}
     */
    public static function encryptLegacyRows(): array
    {
        $changed = 0;

        DB::table('driver_profiles')->orderBy('id')->chunkById(200, function ($rows) use (&$changed) {
            foreach ($rows as $row) {
                $update = [];

                foreach (self::COLUMNS as $column) {
                    $value = $row->{$column};

                    if ($value === null || $value === '') {
                        continue;
                    }

                    $plain = self::decryptOrNull($value);

                    if ($plain === null) {
                        $plain = $value;
                        $update[$column] = Crypt::encryptString($value);
                    }

                    $hashColumn = $column.'_hash';

                    if ($row->{$hashColumn} === null) {
                        $hash = self::blindIndex($plain);

                        if ($hash !== null && DB::table('driver_profiles')->where($hashColumn, $hash)->where('id', '!=', $row->id)->exists()) {
                            Log::warning('Driver identifier clashes with another profile once normalised; blind index left empty.', [
                                'driver_profile_id' => $row->id, 'column' => $column,
                            ]);

                            continue;
                        }

                        if ($hash !== null) {
                            $update[$hashColumn] = $hash;
                        }
                    }
                }

                if ($update !== []) {
                    DB::table('driver_profiles')->where('id', $row->id)->update($update);
                    $changed++;
                }
            }
        });

        return ['rows' => $changed];
    }

    /**
     * The inverse of encryptLegacyRows(), for rolling the migration back.
     *
     * @return array{rows: int}
     */
    public static function decryptRows(): array
    {
        $changed = 0;

        DB::table('driver_profiles')->orderBy('id')->chunkById(200, function ($rows) use (&$changed) {
            foreach ($rows as $row) {
                $update = [];

                foreach (self::COLUMNS as $column) {
                    $plain = $row->{$column} === null ? null : self::decryptOrNull($row->{$column});

                    if ($plain !== null) {
                        $update[$column] = $plain;
                    }
                }

                if ($update !== []) {
                    DB::table('driver_profiles')->where('id', $row->id)->update($update);
                    $changed++;
                }
            }
        });

        return ['rows' => $changed];
    }

    private static function decryptOrNull(string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }
}
