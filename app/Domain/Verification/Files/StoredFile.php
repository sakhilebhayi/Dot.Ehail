<?php

namespace App\Domain\Verification\Files;

final readonly class StoredFile
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $originalName,
        public string $mime,
        public int $size,
        public string $sha256,
    ) {}
}
