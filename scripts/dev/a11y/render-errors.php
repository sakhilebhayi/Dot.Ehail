<?php

/**
 * Renders the error views to storage/a11y/errors/<code>.html so the accessibility sweep can load them
 * without breaking the dev server (503 needs maintenance mode, 500 needs a crash). Dev database only.
 *   php scripts/dev/a11y/render-errors.php
 */

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$dir = __DIR__.'/../../../storage/a11y/errors';
@mkdir($dir, 0775, true);
foreach ([403, 404, 419, 429, 500, 503] as $code) {
    $html = view("errors.$code", ['exception' => new Symfony\Component\HttpKernel\Exception\HttpException($code)])->render();
    file_put_contents("$dir/$code.html", $html);
    echo "$code ".strlen($html)." bytes\n";
}
