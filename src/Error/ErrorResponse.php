<?php

namespace OvhOcr\Error;

class ErrorResponse
{
    private string $userMessage;
    private string $internalMessage;
    private array $context;
    private string $code;
    private bool $isDevelopment;

    public function __construct(
        string $userMessage,
        string $internalMessage,
        array $context = [],
        string $code = 'ERROR',
        bool $isDevelopment = false
    ) {
        $this->userMessage = $userMessage;
        $this->internalMessage = $internalMessage;
        $this->context = $context;
        $this->code = $code;
        $this->isDevelopment = $isDevelopment;
    }

    /**
     * JSON dla API
     */
    public function toJson(): string
    {
        $response = [
            'success' => false,
            'error' => [
                'message' => $this->userMessage,
                'code' => $this->code,
            ],
        ];

        if ($this->isDevelopment) {
            $response['error']['internal'] = $this->internalMessage;
            $response['error']['context'] = $this->context;
        }

        return json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Dla frontendu (tylko user message)
     */
    public function getUserMessage(): string
    {
        return $this->userMessage;
    }

    /**
     * Dla debugowania
     */
    public function getDebugInfo(): array
    {
        return [
            'userMessage' => $this->userMessage,
            'internalMessage' => $this->internalMessage,
            'code' => $this->code,
            'context' => $this->context,
        ];
    }

    public function getHttpStatusCode(): int
    {
        return match ($this->code) {
            'GOOGLE_API_ERROR' => 402,
            'OCR_ERROR' => 422,
            'FILE_NOT_FOUND' => 404,
            'UNAUTHORIZED' => 401,
            default => 500,
        };
    }
}
