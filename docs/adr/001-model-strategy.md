# ADR-001: Three-Tier Model Strategy with Fallback

## Status
Accepted

## Context
The library needs to extract text from images using Visual LLM models from OVH AI Endpoints. Different models have different trade-offs:
- **Cost**: More expensive models are better quality but cost more per request
- **Speed**: Larger models are slower
- **Quality**: Smaller models may fail on complex images

Users need a balance between cost, speed, and reliability without manually managing model selection.

## Decision
Implement a three-tier model strategy:
1. **lite** (Qwen3.5-9B) - cheapest, fastest, good for simple images
2. **medium** (Mistral-Small-3.2-24B) - balanced cost/quality
3. **premium** (Qwen3.6-27B) - most expensive, best quality

Default priority: `['medium', 'premium', 'lite']` - try medium first, fall back to premium if it fails, then lite.

If all OVH models fail, optionally fall back to Google Vision API.

## Consequences

### Positive
- **Fail fast**: If a model is down or rate-limited, automatically try the next one
- **Cost optimization**: Start with cheaper models, only use expensive ones when needed
- **Zero config**: Works out of the box with sensible defaults
- **Flexibility**: Users can override modelMap and modelPriority for custom needs

### Negative
- **Latency**: If first model fails, total latency increases (retry + fallback)
- **Complexity**: More code paths to test and maintain
- **Cost unpredictability**: If medium always fails, users pay for premium without knowing

## Alternatives Considered
1. **Single model**: Simpler, but no fallback if model is down
2. **User chooses model**: More control, but more complexity for users
3. **Parallel requests to all models**: Faster, but 3x the cost

## References
- `src/OcrClient.php:38-42` (DEFAULT_MODEL_MAP)
- `src/OcrClient.php:82` (default modelPriority)
- `docs/OVH_ENDPOINTS.md` (model pricing and capabilities)
