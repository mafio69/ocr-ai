<?php

namespace OvhOcr\Error;

use OvhOcr\Exceptions\OcrException;
use OvhOcr\Logging\Logger;
use Throwable;

class ErrorHandler
{
    private Logger $logger;
    private bool $isDevelopment;

    public function __construct(Logger $logger, bool $isDevelopment = false)
    {
        $this->logger = $logger;
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
            userMessage: $e->getUserMessage(),
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
            : "Coś poszło nie tak... spróbuj później 🤷";

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
