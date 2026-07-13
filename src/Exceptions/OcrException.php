<?php

namespace OvhOcr\Exceptions;

use Exception;
use OvhOcr\i18n\Translator;

class OcrException extends Exception
{
    private array $context = [];
    private ?string $userMessageKey = null;
    private array $userMessageParams = [];
    private static ?Translator $translator = null;

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

    public static function setTranslator(Translator $translator): void
    {
        self::$translator = $translator;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Zwraca przetłumaczoną wiadomość dla użytkownika
     */
    public function getUserMessage(): string
    {
        if (!self::$translator || !$this->userMessageKey) {
            return "Coś poszło nie tak. Spróbuj później 🤷";
        }

        return self::$translator->trans($this->userMessageKey, $this->userMessageParams);
    }

    public function getUserMessageKey(): ?string
    {
        return $this->userMessageKey;
    }
}
