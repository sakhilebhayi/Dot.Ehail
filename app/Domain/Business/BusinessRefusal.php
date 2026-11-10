<?php

namespace App\Domain\Business;

use RuntimeException;

/**
 * A business action refused for reasons a person can act on. $reasons: [{code, message}].
 */
class BusinessRefusal extends RuntimeException
{
    /**
     * @param  list<array{code: string, message: string}>  $reasons
     */
    public function __construct(public readonly string $reasonCode, string $message, public readonly array $reasons = [], public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    /**
     * @param  list<array{code: string, message: string}>  $reasons
     */
    public static function because(array $reasons, int $status = 422): self
    {
        $first = $reasons[0] ?? ['code' => 'refused', 'message' => 'This is not allowed.'];

        return new self($first['code'], implode(' ', array_column($reasons, 'message')), $reasons, $status);
    }

    public static function one(string $code, string $message, int $status = 422): self
    {
        return new self($code, $message, [['code' => $code, 'message' => $message]], $status);
    }
}
