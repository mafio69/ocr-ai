<?php

namespace OvhOcr;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use OvhOcr\i18n\Translator;
use OvhOcr\Logging\Logger;
use OvhOcr\Exceptions\OcrException;
use OvhOcr\Response\OcrResponse;

/**
 * Klient OCR wykorzystujący OVH AI Endpoints (multimodal Visual LLM).
 *
 * API OVH jest OpenAI-compatible, więc używamy standardowego formatu
 * chat/completions z content jako tablicą (text + image_url).
 */
class OcrClient
{
    private const OVH_DEFAULT_ENDPOINT = 'https://oai.endpoints.kepler.ai.cloud.ovh.net/v1/chat/completions';
    private const GOOGLE_VISION_ENDPOINT = 'https://vision.googleapis.com/v1/images:annotate';
    private const MAX_FILE_SIZE_MB = 20;
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    private Client $httpClient;
    private string $apiKey;
    private string $apiEndpoint;
    private Logger $logger;
    private Translator $translator;
    private bool $googleEnabled;
    private ?string $googleApiKey;
    private array $modelMap;
    private array $modelStrategy;

    /**
     * @param string $apiKey Token OVH AI Endpoints
     * @param string $apiEndpoint URL do chat/completions (OpenAI-compatible)
     * @param Logger $logger
     * @param Translator $translator
     * @param array $modelMap ['lite' => 'Qwen3.5-9B', 'medium' => '...', 'premium' => '...']
     * @param array $modelPriority Kolejność prób: np. ['medium', 'premium', 'lite']
     * @param bool $googleEnabled Czy włączyć fallback do Google Vision
     * @param string|null $googleApiKey Klucz Google (wymagany jeśli googleEnabled)
     */
    public function __construct(
        string $apiKey,
        Logger $logger,
        Translator $translator,
        string $apiEndpoint = self::OVH_DEFAULT_ENDPOINT,
        array $modelMap = [],
        array $modelPriority = ['medium', 'premium', 'lite'],
        bool $googleEnabled = false,
        ?string $googleApiKey = null
    ) {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException(
                'OVH API key cannot be empty. Set OVH_AI_ENDPOINTS_ACCESS_TOKEN in .env'
            );
        }

        if ($googleEnabled && (!$googleApiKey || trim($googleApiKey) === '')) {
            throw new \InvalidArgumentException(
                'Google Vision is enabled but GOOGLE_API_KEY is empty. Set the key or disable GOOGLE_VISION_ENABLED.'
            );
        }

        $this->apiKey = $apiKey;
        $this->apiEndpoint = $apiEndpoint;
        $this->logger = $logger;
        $this->translator = $translator;
        $this->googleEnabled = $googleEnabled;
        $this->googleApiKey = $googleApiKey;
        $this->modelMap = $modelMap;

        // Jeśli Google wyłączony - usuń z listy prób
        if (!$this->googleEnabled) {
            $this->modelStrategy = array_values(array_filter(
                $modelPriority,
                fn($m) => $m !== 'google_vision'
            ));
        } else {
            $this->modelStrategy = array_values($modelPriority);
        }

        $this->httpClient = new Client([
            'timeout' => 60,
            'connect_timeout' => 10,
        ]);

        OcrException::setTranslator($translator);

        $this->logger->info('OcrClient initialized', [
            'endpoint' => $apiEndpoint,
            'strategy' => $this->modelStrategy,
            'google_enabled' => $googleEnabled,
        ]);
    }

    /**
     * Wydobywa tekst z obrazu. Próbuje modele kolejno wg strategii.
     */
    public function extractText(string $imagePath, string $language = 'pl'): OcrResponse
    {
        if (!file_exists($imagePath)) {
            throw new OcrException(
                message: "File not found: {$imagePath}",
                userMessageKey: 'errors.file_not_found',
                context: ['file' => $imagePath],
                code: 404
            );
        }

        $this->validateImageFile($imagePath);

        $this->logger->info($this->translator->trans('messages.extraction_started'), [
            'file' => basename($imagePath),
            'size_kb' => round(filesize($imagePath) / 1024, 2),
        ]);

        $lastError = null;

        foreach ($this->modelStrategy as $tierName) {
            try {
                $this->logger->info(
                    $this->translator->trans('messages.attempting_model', ['model' => $tierName])
                );

                if ($tierName === 'google_vision') {
                    $rawData = $this->tryGoogleVision($imagePath);
                } else {
                    $modelName = $this->modelMap[$tierName] ?? null;
                    if (!$modelName) {
                        $this->logger->warning("No model mapping for tier: {$tierName}");
                        continue;
                    }
                    $rawData = $this->tryOvhModel($imagePath, $modelName, $language);
                }

                $this->logger->success(
                    $this->translator->trans('messages.model_success', ['model' => $tierName])
                );

                return new OcrResponse($rawData, $tierName);

            } catch (OcrException $e) {
                $lastError = $e;
                $this->logger->warning(
                    $this->translator->trans('messages.model_failed', ['model' => $tierName]),
                    ['reason' => $e->getMessage()]
                );
                continue;
            }
        }

        throw new OcrException(
            message: 'All models failed. Last error: ' . ($lastError?->getMessage() ?? 'unknown'),
            userMessageKey: 'errors.all_models_failed',
            context: ['attempted' => $this->modelStrategy],
            code: 503
        );
    }

    /**
     * Batch processing - kolejno przetwarza wiele obrazów.
     * Zwraca [ścieżka => OcrResponse | ['error' => msg]]
     */
    public function extractTextBatch(array $imagePaths, string $language = 'pl'): array
    {
        $results = [];

        foreach ($imagePaths as $path) {
            try {
                $results[$path] = $this->extractText($path, $language);
            } catch (OcrException $e) {
                $results[$path] = ['error' => $e->getUserMessage()];
                $this->logger->error("Batch item failed: {$path}", ['reason' => $e->getMessage()]);
            }
        }

        return $results;
    }

    /**
     * OVH Vision LLM - format OpenAI-compatible.
     *
     * Payload zgodny z: https://platform.openai.com/docs/guides/vision
     * content jest tablicą: [ {type:text}, {type:image_url, image_url:{url:"data:..."}} ]
     */
    private function tryOvhModel(string $imagePath, string $modelName, string $language): array
    {
        $mimeType = $this->detectMimeType($imagePath);
        $base64 = base64_encode(file_get_contents($imagePath));
        $dataUrl = "data:{$mimeType};base64,{$base64}";
        $prompt = $this->buildOcrPrompt($language);

        try {
            $response = $this->httpClient->post($this->apiEndpoint, [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => [
                    'model' => $modelName,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => [
                                ['type' => 'text', 'text' => $prompt],
                                ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                            ],
                        ],
                    ],
                    'temperature' => 0.1,
                    'max_tokens' => 8192,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (!is_array($body) || !isset($body['choices'][0]['message']['content'])) {
                throw new OcrException(
                    message: 'OVH returned unexpected response structure',
                    userMessageKey: 'errors.ovh_api_error',
                    context: ['response_keys' => is_array($body) ? array_keys($body) : 'not-array']
                );
            }

            return $body;

        } catch (GuzzleException $e) {
            $statusCode = $e instanceof RequestException ? $e->getResponse()?->getStatusCode() : null;
            $responseBody = $e instanceof RequestException ? $e->getResponse()?->getBody()?->getContents() : null;

            $this->logger->error('OVH API error', [
                'model' => $modelName,
                'status' => $statusCode,
                'response' => $responseBody ? substr($responseBody, 0, 500) : null,
            ]);

            throw new OcrException(
                message: 'OVH API error' . ($statusCode ? " [{$statusCode}]" : '') . ': ' . $e->getMessage(),
                userMessageKey: 'errors.ovh_api_error',
                context: [
                    'model' => $modelName,
                    'status_code' => $statusCode,
                ]
            );
        }
    }

    /**
     * Google Vision - fallback. Używa TEXT_DETECTION.
     */
    private function tryGoogleVision(string $imagePath): array
    {
        $base64 = base64_encode(file_get_contents($imagePath));

        try {
            $response = $this->httpClient->post(self::GOOGLE_VISION_ENDPOINT, [
                'query' => ['key' => $this->googleApiKey],
                'json' => [
                    'requests' => [
                        [
                            'image' => ['content' => $base64],
                            'features' => [['type' => 'TEXT_DETECTION']],
                        ],
                    ],
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (!is_array($body) || !isset($body['responses'][0])) {
                throw new OcrException(
                    message: 'Google Vision returned unexpected response',
                    userMessageKey: 'errors.google_api_error'
                );
            }

            // Google zwraca error w responses[0]['error'] jeśli coś nie poszło
            if (isset($body['responses'][0]['error'])) {
                $err = $body['responses'][0]['error'];
                throw new OcrException(
                    message: "Google Vision error: " . ($err['message'] ?? 'unknown'),
                    userMessageKey: 'errors.google_api_error',
                    context: $err
                );
            }

            return $body;

        } catch (GuzzleException $e) {
            $statusCode = $e instanceof RequestException ? $e->getResponse()?->getStatusCode() : null;

            throw new OcrException(
                message: 'Google Vision request failed: ' . $e->getMessage(),
                userMessageKey: 'errors.google_api_error',
                context: ['status_code' => $statusCode]
            );
        }
    }

    private function buildOcrPrompt(string $language): string
    {
        $langLabel = match ($language) {
            'pl' => 'polski',
            'en' => 'angielski',
            default => $language,
        };

        return <<<PROMPT
Wydobądź CAŁY widoczny tekst z tego obrazu.
Zasady:
1. Zachowaj oryginalne formatowanie: nowe linie, akapity, wcięcia.
2. Zwróć TYLKO tekst - bez komentarzy, opisów, wprowadzeń.
3. Jeśli tekst jest nieczytelny, oznacz [nieczytelne].
4. Nie tłumacz, nie zmieniaj słów, nie parafrazuj.
Głowny język tekstu na obrazie: {$langLabel}.
PROMPT;
    }

    private function validateImageFile(string $imagePath): void
    {
        $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));

        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw new OcrException(
                message: "Invalid image format: {$ext}",
                userMessageKey: 'errors.invalid_format',
                context: [
                    'format' => $ext,
                    'allowed' => self::ALLOWED_EXTENSIONS,
                ],
                code: 400
            );
        }

        $size = filesize($imagePath);
        $maxSize = self::MAX_FILE_SIZE_MB * 1024 * 1024;

        if ($size > $maxSize) {
            throw new OcrException(
                message: "File too large: " . round($size / 1024 / 1024, 2) . "MB",
                userMessageKey: 'errors.file_too_large',
                userMessageParams: [
                    'size' => round($size / 1024 / 1024, 2),
                    'max_size' => self::MAX_FILE_SIZE_MB,
                ],
                context: ['size' => $size, 'max_size' => $maxSize],
                code: 413
            );
        }
    }

    private function detectMimeType(string $imagePath): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($imagePath);
        
        if ($mimeType === false) {
            $this->logger->warning('finfo failed to detect MIME type, falling back to extension', [
                'file' => basename($imagePath)
            ]);
            return $this->detectMimeTypeByExtension($imagePath);
        }
        
        $allowedMimeTypes = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/webp' => ['webp'],
            'image/gif' => ['gif'],
        ];
        
        if (!isset($allowedMimeTypes[$mimeType])) {
            throw new OcrException(
                message: "Detected MIME type not allowed: {$mimeType}",
                userMessageKey: 'errors.invalid_format',
                context: [
                    'detected_mime' => $mimeType,
                    'allowed_mimes' => array_keys($allowedMimeTypes),
                    'file' => basename($imagePath),
                ],
                code: 400
            );
        }
        
        $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
        $expectedExtensions = $allowedMimeTypes[$mimeType];
        
        if (!in_array($ext, $expectedExtensions, true)) {
            throw new OcrException(
                message: "File extension '{$ext}' does not match detected MIME type '{$mimeType}'",
                userMessageKey: 'errors.invalid_format',
                context: [
                    'extension' => $ext,
                    'mime_type' => $mimeType,
                    'expected_extensions' => $expectedExtensions,
                ],
                code: 400
            );
        }
        
        return $mimeType;
    }
    
    private function detectMimeTypeByExtension(string $imagePath): string
    {
        $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
        
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => throw new OcrException(
                message: "Cannot detect MIME type for extension: {$ext}",
                userMessageKey: 'errors.invalid_format',
                context: ['extension' => $ext],
                code: 400
            ),
        };
    }

    /**
     * Zwraca aktualną strategię modeli (do debugowania/logów).
     */
    public function getStrategy(): array
    {
        return $this->modelStrategy;
    }
}
