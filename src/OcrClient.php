<?php

namespace OvhOcr;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request as PsrRequest;
use OvhOcr\i18n\Translator;
use OvhOcr\Logging\Logger;
use OvhOcr\Exceptions\OcrException;
use OvhOcr\Pdf\SearchablePdfWriter;
use OvhOcr\Response\OcrResponse;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

/**
 * Klient OCR wykorzystujący OVH AI Endpoints (multimodal Visual LLM).
 *
 * API OVH jest OpenAI-compatible, więc używamy standardowego formatu
 * chat/completions z content jako tablicą (text + image_url).
 */
class OcrClient implements OcrClientInterface
{
    private const OVH_DEFAULT_ENDPOINT = 'https://oai.endpoints.kepler.ai.cloud.ovh.net/v1/chat/completions';
    private const GOOGLE_VISION_ENDPOINT = 'https://vision.googleapis.com/v1/images:annotate';
    private const MAX_FILE_SIZE_MB = 20;
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    private const DEFAULT_TEMPERATURE = 0.1;
    private const DEFAULT_MAX_TOKENS = 8192;

    /**
     * Sensible out-of-the-box model map so a consumer of this library can get going with
     * just an API key - no need to look up OVH model names before the first call works.
     * Kept in sync with the table in README.md; override via the $modelMap constructor
     * arg if you want different models.
     */
    public const DEFAULT_MODEL_MAP = [
        'lite' => 'Qwen3.5-9B',
        'medium' => 'Mistral-Small-3.2-24B-Instruct-2506',
        'premium' => 'Qwen3.6-27B',
    ];

    // Audit #19: every property below is assigned exactly once, in the constructor, and
    // never mutated afterwards - readonly documents that guarantee and lets PHP enforce it.
    private readonly Client $httpClient;
    private readonly string $apiKey;
    private readonly string $apiEndpoint;
    private readonly Logger $logger;
    private readonly Translator $translator;
    private readonly bool $googleEnabled;
    private readonly ?string $googleApiKey;
    private readonly array $modelMap;
    private readonly array $modelStrategy;
    private readonly float $temperature;
    private readonly int $maxTokens;

    /**
     * @param string $apiKey Token OVH AI Endpoints
     * @param string $apiEndpoint URL do chat/completions (OpenAI-compatible)
     * @param Logger $logger
     * @param Translator $translator
     * @param array|null $modelMap ['lite' => 'Qwen3.5-9B', 'medium' => '...', 'premium' => '...'].
     *     Null (default) = use DEFAULT_MODEL_MAP, so a fresh install works with zero extra config.
     *     Pass an empty array explicitly if you really want no OVH models available.
     * @param array $modelPriority Kolejność prób: np. ['medium', 'premium', 'lite']. Jeśli
     *     $googleEnabled=true i 'google_vision' nie jest tu wpisane, zostanie dopisane
     *     automatycznie na końcu - włączenie Google Vision to wtedy tylko dwa argumenty
     *     ($googleEnabled, $googleApiKey), bez ręcznego edytowania tej listy.
     * @param bool $googleEnabled Czy włączyć fallback do Google Vision
     * @param string|null $googleApiKey Klucz Google (wymagany jeśli googleEnabled)
     * @param Client|null $httpClient Opcjonalny klient HTTP (do testów)
     * @param float $temperature OVH model temperature (0.0-2.0), lower = more deterministic
     * @param int $maxTokens Max tokens for the OVH model response
     */
    public function __construct(
        string $apiKey,
        Logger $logger,
        Translator $translator,
        string $apiEndpoint = self::OVH_DEFAULT_ENDPOINT,
        ?array $modelMap = null,
        array $modelPriority = ['medium', 'premium', 'lite'],
        bool $googleEnabled = false,
        ?string $googleApiKey = null,
        ?Client $httpClient = null,
        float $temperature = self::DEFAULT_TEMPERATURE,
        int $maxTokens = self::DEFAULT_MAX_TOKENS
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

        // Easily-selectable Google Vision: enabling it via $googleEnabled shouldn't also
        // require hand-editing $modelPriority - append it as the last fallback if missing.
        if ($googleEnabled && !in_array('google_vision', $modelPriority, true)) {
            $modelPriority[] = 'google_vision';
        }

        $modelMap ??= self::DEFAULT_MODEL_MAP;

        if ($temperature < 0.0 || $temperature > 2.0) {
            throw new \InvalidArgumentException("Temperature must be between 0.0 and 2.0, got: {$temperature}");
        }

        if ($maxTokens < 1) {
            throw new \InvalidArgumentException("maxTokens must be a positive integer, got: {$maxTokens}");
        }

        $this->apiKey = $apiKey;
        $this->apiEndpoint = $apiEndpoint;
        $this->logger = $logger;
        $this->translator = $translator;
        $this->googleEnabled = $googleEnabled;
        $this->googleApiKey = $googleApiKey;
        $this->modelMap = $modelMap;
        $this->temperature = $temperature;
        $this->maxTokens = $maxTokens;

        $this->validateModelConfiguration($modelMap, $modelPriority, $googleEnabled);

        // Jeśli Google wyłączony - usuń z listy prób
        if (!$this->googleEnabled) {
            $this->modelStrategy = array_values(array_filter(
                $modelPriority,
                fn($m) => $m !== 'google_vision'
            ));
        } else {
            $this->modelStrategy = array_values($modelPriority);
        }

        $this->httpClient = $httpClient ?? new Client([
            'timeout' => 60,
            'connect_timeout' => 10,
        ]);

        $this->logger->info('OcrClient initialized', [
            'endpoint' => $apiEndpoint,
            'strategy' => $this->modelStrategy,
            'google_enabled' => $googleEnabled,
        ]);
    }

    private function validateModelConfiguration(array $modelMap, array $modelPriority, bool $googleEnabled): void
    {
        $knownTiers = ['lite', 'medium', 'premium', 'google_vision'];

        if (empty($modelPriority)) {
            return;
        }

        foreach ($modelPriority as $tier) {
            if (!in_array($tier, $knownTiers, true)) {
                throw new \InvalidArgumentException("Unknown tier in model priority: {$tier}");
            }
        }

        $filteredPriority = array_filter($modelPriority, fn($t) => $t !== 'google_vision');

        foreach ($filteredPriority as $tier) {
            if (!isset($modelMap[$tier])) {
                throw new \InvalidArgumentException("Model map missing tier: {$tier}");
            }
        }
    }

    /**
     * Extracts text from an image and also writes a searchable PDF (source image with an
     * invisible, selectable text layer - see SearchablePdfWriter) to $outputPdfPath.
     *
     * Requires mpdf/mpdf, a *suggested* (not hard) dependency of this library - install it
     * yourself if you use this method: `composer require mpdf/mpdf`.
     *
     * @throws OcrException If extraction itself fails (same as extractText()).
     * @throws \RuntimeException If mpdf/mpdf isn't installed or the PDF can't be written.
     */
    public function extractTextAsSearchablePdf(string $imagePath, string $outputPdfPath, string $language = 'pl'): OcrResponse
    {
        $response = $this->extractText($imagePath, $language);
        (new SearchablePdfWriter())->write($imagePath, $response->getText(), $outputPdfPath);

        return $response;
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
                $results[$path] = ['error' => $e->getUserMessage($this->translator)];
                $this->logger->error("Batch item failed: {$path}", ['reason' => $e->getMessage()]);
            }
        }

        return $results;
    }

    /**
     * Audit #16: extractTextBatch() above processes images strictly one at a time, which
     * is slow for large batches since network round-trips dominate. This variant sends the
     * FIRST model-tier attempt for every image concurrently via GuzzleHttp\Pool - the
     * common case where most images succeed on the preferred model. Images whose first
     * attempt fails fall back to the full sequential extractText() (every tier in
     * $modelStrategy, same as always) - so correctness matches extractText() for every
     * image, only the common path is faster.
     *
     * Deliberate scope tradeoff: building the concurrent requests duplicates a slice of
     * the request-building logic from tryOvhModel()/tryGoogleVision() below (see the
     * build*Request() helpers). Doing this without duplication would require rewriting the
     * whole sequential fallback chain to be async, which is a much bigger, riskier change
     * for a "consider" item with no reported real-world bottleneck - not worth it under
     * "simplicity first" until batches large enough to need it actually show up.
     *
     * @param string[] $imagePaths
     * @param int $concurrency Max number of requests in flight at once
     * @return array<string, OcrResponse|array{error: string}>
     */
    public function extractTextBatchConcurrent(array $imagePaths, string $language = 'pl', int $concurrency = 5): array
    {
        if (empty($this->modelStrategy)) {
            return $this->extractTextBatch($imagePaths, $language);
        }

        $results = [];
        $pendingRequests = [];

        foreach ($imagePaths as $path) {
            try {
                if (!file_exists($path)) {
                    throw new OcrException(
                        message: "File not found: {$path}",
                        userMessageKey: 'errors.file_not_found',
                        context: ['file' => $path],
                        code: 404
                    );
                }
                $this->validateImageFile($path);
                $pendingRequests[$path] = $this->buildFirstAttemptRequest($path, $language);
            } catch (OcrException $e) {
                $results[$path] = ['error' => $e->getUserMessage($this->translator)];
                $this->logger->error("Batch item failed: {$path}", ['reason' => $e->getMessage()]);
            }
        }

        if (empty($pendingRequests)) {
            return $results;
        }

        $needsFallback = [];
        $firstTier = $this->modelStrategy[0];

        $requestGenerator = static function () use ($pendingRequests) {
            foreach ($pendingRequests as $path => $request) {
                yield $path => $request;
            }
        };

        $pool = new Pool($this->httpClient, $requestGenerator(), [
            'concurrency' => max(1, $concurrency),
            'fulfilled' => function (PsrResponseInterface $response, $path) use (&$results, &$needsFallback, $firstTier) {
                try {
                    $results[$path] = $this->parseFirstAttemptResponse($response, $firstTier);
                } catch (OcrException) {
                    $needsFallback[] = $path;
                }
            },
            'rejected' => function ($reason, $path) use (&$needsFallback) {
                $needsFallback[] = $path;
            },
        ]);

        $pool->promise()->wait();

        foreach ($needsFallback as $path) {
            try {
                $results[$path] = $this->extractText($path, $language);
            } catch (OcrException $e) {
                $results[$path] = ['error' => $e->getUserMessage($this->translator)];
                $this->logger->error("Batch item failed after fallback: {$path}", ['reason' => $e->getMessage()]);
            }
        }

        return $results;
    }

    private function buildFirstAttemptRequest(string $imagePath, string $language): PsrRequest
    {
        $tier = $this->modelStrategy[0];

        if ($tier === 'google_vision') {
            return $this->buildGoogleVisionRequest($imagePath);
        }

        $modelName = $this->modelMap[$tier] ?? null;
        if (!$modelName) {
            throw new OcrException(
                message: "No model mapping for tier: {$tier}",
                userMessageKey: 'errors.ovh_api_error',
                context: ['tier' => $tier]
            );
        }

        return $this->buildOvhRequest($imagePath, $modelName, $language);
    }

    private function buildOvhRequest(string $imagePath, string $modelName, string $language): PsrRequest
    {
        $mimeType = $this->detectMimeType($imagePath);
        $content = file_get_contents($imagePath);
        if ($content === false) {
            throw new OcrException(
                message: "Failed to read file: {$imagePath}",
                userMessageKey: 'errors.file_read_error',
                context: ['file' => $imagePath],
                code: 500
            );
        }
        $base64 = base64_encode($content);
        $dataUrl = "data:{$mimeType};base64,{$base64}";
        $prompt = $this->buildOcrPrompt($language);

        $body = json_encode([
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
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
        ], JSON_THROW_ON_ERROR);

        return new PsrRequest('POST', $this->apiEndpoint, [
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $body);
    }

    private function buildGoogleVisionRequest(string $imagePath): PsrRequest
    {
        $content = file_get_contents($imagePath);
        if ($content === false) {
            throw new OcrException(
                message: "Failed to read file: {$imagePath}",
                userMessageKey: 'errors.file_read_error',
                context: ['file' => $imagePath],
                code: 500
            );
        }
        $base64 = base64_encode($content);

        $body = json_encode([
            'requests' => [
                [
                    'image' => ['content' => $base64],
                    'features' => [['type' => 'TEXT_DETECTION']],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        return new PsrRequest('POST', self::GOOGLE_VISION_ENDPOINT, [
            'x-goog-api-key' => $this->googleApiKey,
        ], $body);
    }

    /**
     * Audit #21: json_decode() alone gives no distinction between "empty/null response"
     * and "response body wasn't valid JSON at all" - json_last_error_msg() gives a
     * concrete reason for the log/context when decoding actually fails, instead of just
     * falling through to the generic "unexpected response structure" check below.
     */
    private function decodeJsonResponse(string $rawBody, string $errorKey): mixed
    {
        $decoded = json_decode($rawBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new OcrException(
                message: 'Invalid JSON in API response: ' . json_last_error_msg(),
                userMessageKey: $errorKey,
                context: ['json_error' => json_last_error_msg()]
            );
        }

        return $decoded;
    }

    private function parseFirstAttemptResponse(PsrResponseInterface $response, string $tier): OcrResponse
    {
        $errorKey = $tier === 'google_vision' ? 'errors.google_api_error' : 'errors.ovh_api_error';
        $body = $this->decodeJsonResponse($response->getBody()->getContents(), $errorKey);

        if ($tier === 'google_vision') {
            if (!is_array($body) || !isset($body['responses'][0]) || isset($body['responses'][0]['error'])) {
                throw new OcrException(
                    message: 'Google Vision returned unexpected response',
                    userMessageKey: 'errors.google_api_error'
                );
            }
        } elseif (!is_array($body) || !isset($body['choices'][0]['message']['content'])) {
            throw new OcrException(
                message: 'OVH returned unexpected response structure',
                userMessageKey: 'errors.ovh_api_error',
                context: ['response_keys' => is_array($body) ? array_keys($body) : 'not-array']
            );
        }

        return new OcrResponse($body, $tier);
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
        $content = file_get_contents($imagePath);
        if ($content === false) {
            throw new OcrException(
                message: "Failed to read file: {$imagePath}",
                userMessageKey: 'errors.file_read_error',
                context: ['file' => $imagePath],
                code: 500
            );
        }
        $base64 = base64_encode($content);
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
                    'temperature' => $this->temperature,
                    'max_tokens' => $this->maxTokens,
                ],
            ]);

            $body = $this->decodeJsonResponse($response->getBody()->getContents(), 'errors.ovh_api_error');

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
        $content = file_get_contents($imagePath);
        if ($content === false) {
            throw new OcrException(
                message: "Failed to read file: {$imagePath}",
                userMessageKey: 'errors.file_read_error',
                context: ['file' => $imagePath],
                code: 500
            );
        }
        $base64 = base64_encode($content);

        try {
            $response = $this->httpClient->post(self::GOOGLE_VISION_ENDPOINT, [
                'headers' => ['x-goog-api-key' => $this->googleApiKey],
                'json' => [
                    'requests' => [
                        [
                            'image' => ['content' => $base64],
                            'features' => [['type' => 'TEXT_DETECTION']],
                        ],
                    ],
                ],
            ]);

            $body = $this->decodeJsonResponse($response->getBody()->getContents(), 'errors.google_api_error');

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

    /**
     * Audit #17: the instructions used to always be written in Polish, even for
     * non-Polish documents, which can hurt extraction quality for the vision model.
     * Now the instruction language follows the target document's language: Polish
     * documents get Polish instructions, everything else falls back to English
     * (the audit's own recommendation: "language-aware or fully English").
     */
    private function buildOcrPrompt(string $language): string
    {
        return match ($language) {
            'pl' => <<<PROMPT
Wydobądź CAŁY widoczny tekst z tego obrazu.
Zasady:
1. Zachowaj oryginalne formatowanie: nowe linie, akapity, wcięcia.
2. Zwróć TYLKO tekst - bez komentarzy, opisów, wprowadzeń.
3. Jeśli tekst jest nieczytelny, oznacz [nieczytelne].
4. Nie tłumacz, nie zmieniaj słów, nie parafrazuj.
Główny język tekstu na obrazie: polski.
PROMPT,
            default => <<<PROMPT
Extract ALL visible text from this image.
Rules:
1. Preserve the original formatting: line breaks, paragraphs, indentation.
2. Return ONLY the text - no comments, descriptions, or introductions.
3. If the text is illegible, mark it as [illegible].
4. Do not translate, alter words, or paraphrase.
Main language of the text in the image: {$language}.
PROMPT,
        };
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
