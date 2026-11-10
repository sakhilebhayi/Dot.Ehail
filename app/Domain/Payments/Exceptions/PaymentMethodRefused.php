<?php

namespace App\Domain\Payments\Exceptions;

use RuntimeException;

/**
 * A money action the rules do not allow. The message is safe to show the person who asked.
 */
class PaymentMethodRefused extends RuntimeException {}
