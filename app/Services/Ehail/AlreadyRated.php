<?php

namespace App\Services\Ehail;

use RuntimeException;

class AlreadyRated extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('You have already rated this ride.');
    }
}
