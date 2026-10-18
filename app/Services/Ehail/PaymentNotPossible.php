<?php

namespace App\Services\Ehail;

use RuntimeException;

/**
 * A fare cannot be paid, waived or reviewed in its current state. The message is safe to show.
 */
class PaymentNotPossible extends RuntimeException {}
