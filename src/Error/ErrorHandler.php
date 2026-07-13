<?php

namespace OvhOcr\Error;

use OvhOcr\Exceptions\OcrException;
use OvhOcr\i18n\Translator;
use OvhOcr\Logging\Logger;
use Throwable;

class ErrorHandler
{
    private Logger $logger;
    private Translator $translator;
    private bool $isDevelopment;

    public function __construct(Logger $logger, Translator $translator, bool $isDevelopment = false)
    {
        $this->logger = $logger;
        $this->translator = $translator;
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
        return new ErrorResponse(
            userMessage: $e->getUserMessage($this->translator),
            internalMessage: $e->getMessage(),
            context: $e->getContext(),
            code: 'OCR_ERROR',
            isDevelopment: $this->isDevelopment
        );
    }

    private function handleGenericException(Throwable $e): ErrorResponse
    {
        $userMessage = $this->isDevelopment 
            ? $e->getMessage()
            : $this->translator->trans('errors.unexpected_error');

        return new ErrorResponse(
            userMessage: $userMessage,
            internalMessage: $e->getMessage(),
            code: 'INTERNAL_ERROR',
            isDevelopment: $this->isDevelopment
        );
    }

    private function logException(Throwable $exception): void
    {
        $this->logger->error(
            message: $exception->getMessage(),
            context: [
                'exception' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]
        );
    }
}
