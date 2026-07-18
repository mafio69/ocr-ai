<?php

namespace OvhOcr\Exceptions;

use OvhOcr\i18n\Translator;

class OcrException extends \Exception
{
    // Audit #19: all three are unconditionally assigned once in the constructor below and
    // never mutated afterwards - readonly properties can't carry an inline default value,
    // which is fine here since the constructor always sets them regardless of which
    // optional arguments the caller passed.
    private readonly array $context;
    private readonly ?string $userMessageKey;
    private readonly array $userMessageParams;

    public function __construct(
        string $message,
        ?string $userMessageKey = null,
        array $context = [],
        array $userMessageParams = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);

        $this->context = $context;
        $this->userMessageKey = $userMessageKey;
        $this->userMessageParams = $userMessageParams;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Zwraca przetłumaczoną wiadomość dla użytkownika
     */
    public function getUserMessage(?Translator $translator = null): string
    {
        $key = $this->userMessageKey ?? 'errors.unexpected_error';

        if (!$translator) {
            return $this->getFallbackMessage($key);
        }

        return $translator->trans($key, $this->userMessageParams);
    }

    private function getFallbackMessage(string $key): string
    {
        return match ($key) {
            'errors.file_not_found' => 'Image file not found',
            'errors.invalid_format' => 'Invalid image format',
            'errors.file_too_large' => 'File too large',
            'errors.file_read_error' => 'Failed to read file',
            'errors.all_models_failed' => 'All OCR models failed',
            'errors.google_not_configured' => 'Google Vision not configured',
            'errors.google_api_error' => 'Google API error',
            'errors.ovh_api_error' => 'OVH API error',
            'errors.internal_error' => 'Internal error',
            default => 'An unexpected error occurred',
        };
    }

    public function getUserMessageKey(): ?string
    {
        return $this->userMessageKey;
    }
}
