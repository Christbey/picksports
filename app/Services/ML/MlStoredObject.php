<?php

namespace App\Services\ML;

final readonly class MlStoredObject
{
    public function __construct(
        public string $disk,
        public string $objectKey,
        public string $uri,
        public string $sha256,
        public int $size,
        public string $contentType,
        public string $localPath,
    ) {}
}
