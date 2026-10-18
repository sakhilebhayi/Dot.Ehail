<?php

namespace App\Services\Ehail;

use RuntimeException;

/**
 * A vehicle change the driver's current situation does not allow (it is on a
 * live ride, say). The message is written for the driver.
 */
class VehicleRuleViolation extends RuntimeException {}
