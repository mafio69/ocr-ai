<?php

namespace OvhOcr\Image;

/** Immutable result of ImagePreprocessor::normalize() - final bytes + their real MIME type. */
final class PreprocessedImage
{
    public function __construct(
        public readonly string $data,
        public readonly string $mimeType,
    ) {}
}
