<?php

namespace OvhOcr\Error;

use OvhOcr\Exceptions\OcrException;
use OvhOcr\i18n\Translator;
use OvhOcr\Logging\Logger;
use Throwable;

class ErrorHandler
{
    // Audit #19: assigned once in the constructor, never mutated afterwards.
    private readonly Logger $logger;
    private readonly Translator $translator;
    private readonly bool $isDevelopment;

    public function __construct(Logger $logger, Translator $translator, bool $isDevelopment = false)
    {
        $this->logger        = $logger;
        $this->translator    = $translator;
        $this->isDevelopment = $isDevelopment;
    }

    /**
     * Obsługa wyjątków
     */
    public function handle(Throwable $exception): ErrorResponse
    {
        $this->logException($exception);

        if ($exception instanceof OcrException) {
            return $this->handleOcrException($exception);
        }

        return $this->handleGenericException($exception);
    }

    private function handleOcrException(OcrException $e): ErrorResponse
    {
        $errorCode = $this->mapUserMessageKeyToErrorCode($e->getUserMessageKey());

        return new ErrorResponse(
            userMessage: $e->getUserMessage($this->translator),
            internalMessage: $e->getMessage(),
            context: $e->getContext(),
            code: $errorCode,
            isDevelopment: $this->isDevelopment,
        );
    }

    private function mapUserMessageKeyToErrorCode(?string $userMessageKey): string
    {
        return match ($userMessageKey) {
            'errors.google_api_error'      => 'GOOGLE_API_ERROR',
            'errors.google_not_configured' => 'GOOGLE_API_ERROR',
            'errors.file_not_found'        => 'FILE_NOT_FOUND',
            'errors.unauthorized'          => 'UNAUTHORIZED',
            default                        => 'OCR_ERROR',
        };
    }

    private function handleGenericException(Throwable $e): ErrorResponse
    {
        $userMessage = $this->isDevelopment
            ? $e->getMessage()
            : $this->getTranslatedOrFallback('errors.unexpected_error', 'An unexpected error occurred');

        return new ErrorResponse(
            userMessage: $userMessage,
            internalMessage: $e->getMessage(),
            code: 'INTERNAL_ERROR',
            isDevelopment: $this->isDevelopment,
        );
    }

    private function getTranslatedOrFallback(string $key, string $fallback): string
    {
        $translated = $this->translator->trans($key);

        return $translated !== $key ? $translated : $fallback;
    }

    private function logException(Throwable $exception): void
    {
        $this->logger->error(
            message: $exception->getMessage(),
            context: [
                'exception' => get_class($exception),
                'file'      => $exception->getFile(),
                'line'      => $exception->getLine(),
                'trace'     => $exception->getTraceAsString(),
            ],
        );
    }
}
