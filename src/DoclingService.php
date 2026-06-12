<?php

namespace KhindIq\Docling;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

class DoclingService
{
    /**
     * When $config is null, settings are read from config('docling') on every
     * call, so runtime config changes (tests, tenant switching) take effect
     * without rebinding. Pass an explicit array to pin the settings.
     */
    public function __construct(private ?array $config = null)
    {
    }

    /**
     * Check that a base URL is set and the docling-serve instance responds.
     */
    public function isConfigured(): bool
    {
        $baseUrl = $this->baseUrl();

        if ($baseUrl === '') {
            $this->log()->warning('Docling base_url is not set.');

            return false;
        }

        try {
            $response = Http::timeout($this->healthTimeout())
                ->withHeaders($this->authHeaders())
                ->get("{$baseUrl}/health");

            if (! $response->successful()) {
                // A 401 here on an otherwise-working server usually means the
                // base_url scheme triggered an http->https redirect that
                // dropped the auth header — point base_url at the final scheme.
                $this->log()->warning('Docling health check returned a non-success status.', [
                    'url' => "{$baseUrl}/health",
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);
            }

            return $response->successful();
        } catch (\Exception $e) {
            $this->log()->warning('Docling health check failed to connect.', [
                'url' => "{$baseUrl}/health",
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Convert a document to markdown/JSON via docling-serve.
     *
     * PDFs longer than the configured pages_per_chunk are split with
     * poppler-utils and processed chunk by chunk. $options entries override
     * the configured conversion options for this call only.
     *
     * @return array{success: bool, message: string, data: ?array}
     */
    public function OCRProcessing(mixed $documentPath, array $options = []): array
    {
        try {
            if (! $this->isConfigured()) {
                $this->log()->warning('Docling service is not properly configured.', [
                    'base_url' => $this->baseUrl(),
                    'auth_set' => $this->authHeaders() !== [],
                ]);

                return [
                    'success' => false,
                    'message' => 'Docling service is not properly configured.',
                    'data' => null,
                ];
            }

            if (! is_string($documentPath) || ! is_file($documentPath)) {
                $this->log()->error('Invalid document path provided for OCR processing.', [
                    'document_path' => $documentPath,
                ]);

                return [
                    'success' => false,
                    'message' => 'Invalid document path provided for OCR processing.',
                    'data' => null,
                ];
            }

            $size = File::size($documentPath);
            $maxFileSize = $this->maxFileSize();

            if ($maxFileSize !== null && $size > $maxFileSize) {
                $this->log()->warning('Document exceeds the configured maximum file size.', [
                    'document_path' => $documentPath,
                    'size' => $size,
                    'max_file_size' => $maxFileSize,
                ]);

                return [
                    'success' => false,
                    'message' => "Document exceeds the configured maximum file size ({$maxFileSize} bytes).",
                    'data' => null,
                ];
            }

            // Async path: submit the whole document as one docling task and poll.
            // Avoids per-request response caps (e.g. Cloudflare's ~100s 524) and
            // the poppler page-splitting entirely — docling paginates server-side.
            if ($this->async()) {
                return $this->processAsync($documentPath, $options);
            }

            $mimeType = File::mimeType($documentPath);

            if ($mimeType === 'application/pdf') {
                $pageCount = $this->getPDFPageCount($documentPath);

                if ($pageCount !== null && $pageCount > $this->pagesPerChunk()) {
                    $this->log()->info('Multi-page PDF detected, processing in chunks.', [
                        'document_path' => $documentPath,
                        'size' => $size,
                        'pages' => $pageCount,
                    ]);

                    return $this->processLargePDF($documentPath, $pageCount, $options);
                }
            }

            return $this->processSingleDocument($documentPath, $options);
        } catch (\Exception $e) {
            $this->log()->error('OCR processing failed.', [
                'document_path' => $documentPath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'OCR processing failed: ' . $e->getMessage(),
                'data' => null,
            ];
        }
    }

    private function configValue(string $key, mixed $default = null): mixed
    {
        $config = $this->config ?? (array) config('docling', []);

        return data_get($config, $key) ?? $default;
    }

    private function baseUrl(): string
    {
        return rtrim((string) $this->configValue('base_url', ''), '/');
    }

    private function apiKey(): ?string
    {
        $key = $this->configValue('api_key');

        return $key !== null ? (string) $key : null;
    }

    private function bearerToken(): ?string
    {
        $token = $this->configValue('bearer_token');

        return $token !== null ? (string) $token : null;
    }

    /**
     * Auth headers applied to every request, including the /health probe.
     * A reverse proxy guarding docling may reject /health without them.
     *
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        $headers = [];

        if (! empty($this->apiKey())) {
            $headers['X-Api-Key'] = $this->apiKey();
        }

        if (! empty($this->bearerToken())) {
            $headers['Authorization'] = 'Bearer ' . $this->bearerToken();
        }

        return $headers;
    }

    private function logChannel(): ?string
    {
        return $this->configValue('log_channel');
    }

    private function timeout(): int
    {
        return (int) $this->configValue('timeout', 300);
    }

    private function async(): bool
    {
        return (bool) $this->configValue('async', false);
    }

    private function pollInterval(): int
    {
        return max(1, (int) $this->configValue('poll_interval', 3));
    }

    private function asyncTimeout(): int
    {
        return (int) $this->configValue('async_timeout', 1800);
    }

    private function connectTimeout(): int
    {
        return (int) $this->configValue('connect_timeout', 10);
    }

    private function healthTimeout(): int
    {
        return (int) $this->configValue('health_timeout', 2);
    }

    private function pagesPerChunk(): int
    {
        return max(1, (int) $this->configValue('pages_per_chunk', 3));
    }

    private function maxFileSize(): ?int
    {
        $value = (int) $this->configValue('max_file_size', 0);

        return $value > 0 ? $value : null;
    }

    private function tempDir(): string
    {
        return $this->configValue('temp_dir') ?: storage_path('app/docling-tmp');
    }

    private function options(): array
    {
        return (array) $this->configValue('options', []);
    }

    private function log(): LoggerInterface
    {
        return Log::channel($this->logChannel());
    }

    private function processImagePlaceholders(string $content, array $jsonContent): string
    {
        if (! isset($jsonContent['pictures']) || ! is_array($jsonContent['pictures'])) {
            return $content;
        }

        $pictures = $jsonContent['pictures'];
        $pictureIndex = 0;

        return preg_replace_callback('/<!--\s*\[?Image.*?\]?\s*-->/i', function ($matches) use (&$pictureIndex, $pictures, $jsonContent) {
            if (! isset($pictures[$pictureIndex])) {
                return $matches[0];
            }

            $text = $this->extractTextFromPicture($pictures[$pictureIndex], $jsonContent);
            $pictureIndex++;

            if (! empty($text)) {
                // Prefix every line with '>' to ensure the blockquote continues for the whole text
                $formattedText = implode("\n", array_map(function ($line) {
                    return '> ' . $line;
                }, explode("\n", $text)));

                return "\n> **[Image Text]**:\n" . $formattedText . "\n";
            }

            return $matches[0];
        }, $content);
    }

    private function extractTextFromPicture(array $picture, array $jsonContent): string
    {
        $text = '';
        if (isset($picture['children']) && is_array($picture['children'])) {
            foreach ($picture['children'] as $child) {
                if (isset($child['$ref'])) {
                    $text .= $this->resolveRefText($child['$ref'], $jsonContent);
                }
            }
        }

        return trim($text);
    }

    private function resolveRefText(string $ref, array $jsonContent): string
    {
        if (preg_match('/#\/(\w+)\/(\d+)/', $ref, $matches)) {
            $type = $matches[1];
            $index = (int) $matches[2];

            if ($type === 'texts' && isset($jsonContent['texts'][$index]['text'])) {
                return $jsonContent['texts'][$index]['text'] . "\n";
            }

            if ($type === 'groups' && isset($jsonContent['groups'][$index]['children'])) {
                $groupText = '';
                foreach ($jsonContent['groups'][$index]['children'] as $child) {
                    if (isset($child['$ref'])) {
                        $groupText .= $this->resolveRefText($child['$ref'], $jsonContent);
                    }
                }

                return $groupText;
            }
        }

        return '';
    }

    /**
     * Process large PDF by splitting into chunks.
     *
     * Returns success=true only when every chunk converts. If some chunks
     * fail, success is false but data still carries the partial combined
     * result plus chunks_total / chunks_failed so the caller can decide.
     */
    private function processLargePDF(string $documentPath, int $pageCount, array $options = []): array
    {
        $tempDir = $this->tempDir() . '/pdf_chunks_' . uniqid();

        try {
            if (! File::exists($tempDir)) {
                File::makeDirectory($tempDir, 0755, true);
            }

            $chunkFiles = $this->splitPDFIntoChunks($documentPath, $tempDir, $pageCount);

            if (empty($chunkFiles)) {
                File::deleteDirectory($tempDir);

                $this->log()->error('Failed to split PDF into chunks.', [
                    'document_path' => $documentPath,
                ]);

                return [
                    'success' => false,
                    'message' => 'Failed to split PDF into chunks. Check that poppler-utils (pdfseparate, pdfunite) is installed.',
                    'data' => null,
                ];
            }

            $this->log()->info('PDF split into chunks.', [
                'document_path' => $documentPath,
                'chunks' => count($chunkFiles),
            ]);

            $allResults = [];
            $failedChunks = [];
            foreach ($chunkFiles as $index => $chunkFile) {
                $this->log()->info('Processing chunk.', [
                    'chunk' => $index + 1,
                    'of' => count($chunkFiles),
                    'file' => $chunkFile,
                ]);

                $result = $this->processSingleDocument($chunkFile, $options);

                if ($result['success'] && is_array($result['data'])) {
                    $allResults[] = ['chunk' => $index + 1, 'data' => $result['data']];
                } else {
                    $failedChunks[] = $index + 1;
                    $this->log()->warning('Chunk processing failed, continuing with next chunk.', [
                        'chunk' => $index + 1,
                        'error' => $result['message'],
                    ]);
                }
            }

            $this->cleanupChunks($chunkFiles);
            File::deleteDirectory($tempDir);

            if (empty($allResults)) {
                return [
                    'success' => false,
                    'message' => 'All chunks failed to process.',
                    'data' => null,
                ];
            }

            $combinedResult = $this->combineChunkResults($allResults, basename($documentPath));
            $combinedResult['chunks_total'] = count($chunkFiles);
            $combinedResult['chunks_failed'] = $failedChunks;

            if (! empty($failedChunks)) {
                $this->log()->error('Large PDF processed with missing chunks.', [
                    'document_path' => $documentPath,
                    'chunks_total' => count($chunkFiles),
                    'chunks_failed' => $failedChunks,
                ]);

                return [
                    'success' => false,
                    'message' => sprintf(
                        'Processed %d of %d chunks; chunk(s) %s failed. Partial result returned in data.',
                        count($allResults),
                        count($chunkFiles),
                        implode(', ', $failedChunks)
                    ),
                    'data' => $combinedResult,
                ];
            }

            return [
                'success' => true,
                'message' => 'Large PDF processing completed successfully.',
                'data' => $combinedResult,
            ];
        } catch (\Exception $e) {
            if (File::exists($tempDir)) {
                File::deleteDirectory($tempDir);
            }

            $this->log()->error('Large PDF processing failed.', [
                'document_path' => $documentPath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'Large PDF processing failed: ' . $e->getMessage(),
                'data' => null,
            ];
        }
    }

    /**
     * Split PDF into chunks of N pages each
     */
    private function splitPDFIntoChunks(string $pdfPath, string $outputDir, int $pageCount): array
    {
        $chunkFiles = [];
        $pagesPerChunk = $this->pagesPerChunk();

        try {
            $this->log()->info('Splitting PDF.', [
                'pages' => $pageCount,
                'chunks_needed' => (int) ceil($pageCount / $pagesPerChunk),
            ]);

            $chunkNumber = 0;
            for ($startPage = 1; $startPage <= $pageCount; $startPage += $pagesPerChunk) {
                $endPage = min($startPage + $pagesPerChunk - 1, $pageCount);
                $chunkFile = $outputDir . '/chunk_' . str_pad((string) $chunkNumber, 4, '0', STR_PAD_LEFT) . '.pdf';

                if ($this->extractPDFPages($pdfPath, $chunkFile, $startPage, $endPage)) {
                    $chunkFiles[] = $chunkFile;
                    $chunkNumber++;
                }
            }

            return $chunkFiles;
        } catch (\Exception $e) {
            $this->log()->error('PDF splitting failed.', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get PDF page count
     */
    private function getPDFPageCount(string $pdfPath): ?int
    {
        // Method 1: Try using pdfinfo first (fastest)
        $output = [];
        $returnVar = 0;
        exec('pdfinfo ' . escapeshellarg($pdfPath) . ' 2>&1', $output, $returnVar);

        if ($returnVar === 0) {
            foreach ($output as $line) {
                if (preg_match('/^Pages:\s+(\d+)/', $line, $matches)) {
                    return (int) $matches[1];
                }
            }
        } else {
            $this->log()->debug('pdfinfo failed or not available.', [
                'output' => implode("\n", $output),
            ]);
        }

        // Method 2: Try ghostscript
        $postScript = sprintf(
            '(%s) (r) file runpdfbegin pdfpagecount = quit',
            str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $pdfPath)
        );

        $output = [];
        $returnVar = 0;
        $cmd = sprintf(
            'gs -q -dNODISPLAY -dSAFER --permit-file-read=%s -c %s 2>&1',
            escapeshellarg($pdfPath),
            escapeshellarg($postScript)
        );
        exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && isset($output[0]) && is_numeric(trim($output[0]))) {
            return (int) trim($output[0]);
        }

        $this->log()->debug('ghostscript failed or not available.', [
            'output' => implode("\n", $output),
        ]);

        // Method 3: Try mutool (from mupdf-tools)
        $output = [];
        $returnVar = 0;
        exec('mutool info ' . escapeshellarg($pdfPath) . ' 2>&1', $output, $returnVar);

        if ($returnVar === 0) {
            foreach ($output as $line) {
                if (preg_match('/Pages:\s+(\d+)/', $line, $matches)) {
                    return (int) $matches[1];
                }
            }
        } else {
            $this->log()->debug('mutool failed or not available.', [
                'output' => implode("\n", $output),
            ]);
        }

        // Method 4: Count /Type /Page objects in the raw file (not exact, but
        // works for many PDFs when no CLI tool is available)
        try {
            $content = file_get_contents($pdfPath);
            if ($content !== false) {
                preg_match_all("/\/Type\s*\/Page[^s]/", $content, $matches);
                $pageCount = count($matches[0]);

                if ($pageCount > 0) {
                    return $pageCount;
                }
            }
        } catch (\Exception $e) {
            $this->log()->debug('Regex page count method failed.', [
                'error' => $e->getMessage(),
            ]);
        }

        $this->log()->error('All page count methods failed.', [
            'pdf_path' => $pdfPath,
        ]);

        return null;
    }

    /**
     * Extract specific pages from PDF
     */
    private function extractPDFPages(string $sourcePdf, string $outputPdf, int $startPage, int $endPage): bool
    {
        $tempDir = dirname($outputPdf) . '/temp_' . uniqid();

        try {
            if (! File::exists($tempDir)) {
                File::makeDirectory($tempDir, 0755, true);
            }

            // Step 1: Extract individual pages using pdfseparate
            $separateCmd = sprintf(
                'pdfseparate -f %d -l %d %s %s 2>&1',
                $startPage,
                $endPage,
                escapeshellarg($sourcePdf),
                escapeshellarg($tempDir . '/page-%d.pdf')
            );

            $output = [];
            $returnVar = 0;
            exec($separateCmd, $output, $returnVar);

            if ($returnVar !== 0) {
                $this->log()->error('Failed to separate PDF pages.', [
                    'command' => $separateCmd,
                    'output' => implode("\n", $output),
                    'return_code' => $returnVar,
                ]);
                File::deleteDirectory($tempDir);

                return false;
            }

            // Step 2: Find all extracted page files
            $pageFiles = [];
            for ($page = $startPage; $page <= $endPage; $page++) {
                $pageFile = $tempDir . '/page-' . $page . '.pdf';
                if (file_exists($pageFile)) {
                    $pageFiles[] = $pageFile;
                }
            }

            if (empty($pageFiles)) {
                $this->log()->error('No pages were extracted.', [
                    'start_page' => $startPage,
                    'end_page' => $endPage,
                ]);
                File::deleteDirectory($tempDir);

                return false;
            }

            // Step 3: Unite pages into output file if multiple pages
            if (count($pageFiles) === 1) {
                rename($pageFiles[0], $outputPdf);
            } else {
                $uniteCmd = sprintf(
                    'pdfunite %s %s 2>&1',
                    implode(' ', array_map('escapeshellarg', $pageFiles)),
                    escapeshellarg($outputPdf)
                );

                $output = [];
                $returnVar = 0;
                exec($uniteCmd, $output, $returnVar);

                if ($returnVar !== 0) {
                    $this->log()->error('Failed to unite PDF pages.', [
                        'command' => $uniteCmd,
                        'output' => implode("\n", $output),
                        'return_code' => $returnVar,
                    ]);
                    File::deleteDirectory($tempDir);

                    return false;
                }
            }

            File::deleteDirectory($tempDir);

            if (! file_exists($outputPdf)) {
                $this->log()->error('Output PDF was not created.', [
                    'output_path' => $outputPdf,
                ]);

                return false;
            }

            return true;
        } catch (\Exception $e) {
            if (File::exists($tempDir)) {
                File::deleteDirectory($tempDir);
            }

            $this->log()->error('Failed to extract PDF pages.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Process a single document (used for both regular and chunked processing)
     */
    /**
     * Mime types docling-serve can convert (application/json must be
     * DoclingDocument JSON).
     *
     * @return list<string>
     */
    private function supportedMimeTypes(): array
    {
        return [
            'text/csv',
            'text/plain',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/html',
            'image/jpeg',
            'image/png',
            'image/tiff',
            'image/gif',
            'image/bmp',
            'image/webp',
            'application/json',
            'text/markdown',
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    /**
     * Turn a docling-serve convert response (sync /v1/convert/source or async
     * /v1/result/{id}) into the package's standard envelope. docling replies 200
     * even when the conversion itself failed; the body's status carries the real
     * outcome.
     *
     * @return array{success: bool, message: string, data: ?array}
     */
    private function interpretConvertResponse(?array $responseJson, string $documentPath): array
    {
        $conversionStatus = is_array($responseJson) ? ($responseJson['status'] ?? null) : null;

        if (! is_array($responseJson) || ! isset($responseJson['document']) || in_array($conversionStatus, ['failure', 'skipped'], true)) {
            $this->log()->error('Docling conversion was not successful.', [
                'document_path' => $documentPath,
                'conversion_status' => $conversionStatus,
                'errors' => is_array($responseJson) ? ($responseJson['errors'] ?? null) : null,
            ]);

            return [
                'success' => false,
                'message' => 'Docling conversion was not successful'
                    . ($conversionStatus ? " (status: {$conversionStatus})." : '.'),
                'data' => $responseJson,
            ];
        }

        $responseData = [
            'filename' => $responseJson['document']['filename'] ?? null,
            'text' => null,
            'json_content' => $responseJson['document']['json_content'] ?? null,
            'md_content' => $responseJson['document']['md_content'] ?? null,
        ];

        if (isset($responseData['json_content'], $responseData['md_content'])) {
            $responseData['text'] = $this->processImagePlaceholders($responseData['md_content'], $responseData['json_content']);
        }

        return [
            'success' => true,
            'message' => 'OCR processing completed successfully.',
            'data' => $responseData,
        ];
    }

    /**
     * Convert the whole document via docling-serve's async API: submit one task,
     * poll until terminal, then fetch the result. Every HTTP request returns
     * quickly, so the conversion is never cut by a reverse-proxy / CDN response
     * cap (e.g. Cloudflare's ~100s). No poppler page-splitting — docling
     * paginates the whole document server-side.
     *
     * @return array{success: bool, message: string, data: ?array}
     */
    private function processAsync(string $documentPath, array $options = []): array
    {
        try {
            $mimeType = File::mimeType($documentPath);

            if (! in_array($mimeType, $this->supportedMimeTypes(), true)) {
                return [
                    'success' => false,
                    'message' => 'Unsupported document mime type for OCR processing.',
                    'data' => null,
                ];
            }

            $payload = [
                'options' => array_replace($this->options(), $options),
                'sources' => [
                    [
                        'base64_string' => base64_encode(File::get($documentPath)),
                        'filename' => basename($documentPath),
                        'kind' => 'file',
                    ],
                ],
            ];

            $submit = Http::timeout($this->timeout())
                ->connectTimeout($this->connectTimeout())
                ->withHeaders($this->authHeaders())
                ->post("{$this->baseUrl()}/v1/convert/source/async", $payload);

            if ($submit->failed()) {
                $this->log()->error('Async submit request failed.', [
                    'document_path' => $documentPath,
                    'status' => $submit->status(),
                    'response' => $submit->body(),
                ]);

                return [
                    'success' => false,
                    'message' => 'Async submit request failed.',
                    'data' => $submit->json(),
                ];
            }

            $taskId = $submit->json('task_id');

            if (! $taskId) {
                return [
                    'success' => false,
                    'message' => 'Async submit returned no task id.',
                    'data' => $submit->json(),
                ];
            }

            // Poll until terminal or the overall ceiling. The poll endpoint
            // long-polls up to `wait` seconds, so each request stays well under
            // any proxy response cap.
            $status = (string) ($submit->json('task_status') ?? 'pending');
            $deadline = time() + $this->asyncTimeout();

            while (! in_array($status, ['success', 'failure', 'partial_success'], true)) {
                if (time() >= $deadline) {
                    $this->log()->error('Async conversion timed out.', [
                        'document_path' => $documentPath,
                        'task_id' => $taskId,
                        'last_status' => $status,
                        'async_timeout' => $this->asyncTimeout(),
                    ]);

                    return [
                        'success' => false,
                        'message' => "Async conversion timed out after {$this->asyncTimeout()}s (last status: {$status}).",
                        'data' => null,
                    ];
                }

                sleep($this->pollInterval());

                $poll = Http::timeout($this->timeout())
                    ->withHeaders($this->authHeaders())
                    ->get("{$this->baseUrl()}/v1/status/poll/{$taskId}", ['wait' => $this->pollInterval()]);

                if ($poll->failed()) {
                    $this->log()->warning('Async poll request failed; retrying.', [
                        'task_id' => $taskId,
                        'status' => $poll->status(),
                    ]);

                    continue;
                }

                $status = (string) ($poll->json('task_status') ?? $status);
            }

            if ($status === 'failure') {
                $this->log()->error('Async conversion failed.', [
                    'document_path' => $documentPath,
                    'task_id' => $taskId,
                ]);

                return [
                    'success' => false,
                    'message' => 'Docling async conversion failed.',
                    'data' => null,
                ];
            }

            $result = Http::timeout($this->timeout())
                ->withHeaders($this->authHeaders())
                ->get("{$this->baseUrl()}/v1/result/{$taskId}");

            if ($result->failed()) {
                $this->log()->error('Async result request failed.', [
                    'document_path' => $documentPath,
                    'task_id' => $taskId,
                    'status' => $result->status(),
                ]);

                return [
                    'success' => false,
                    'message' => 'Async result request failed.',
                    'data' => $result->json(),
                ];
            }

            return $this->interpretConvertResponse($result->json(), $documentPath);
        } catch (\Exception $e) {
            $this->log()->error('Async document processing failed.', [
                'document_path' => $documentPath,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Processing failed: ' . $e->getMessage(),
                'data' => null,
            ];
        }
    }

    private function processSingleDocument(string $documentPath, array $options = []): array
    {
        try {
            $mimeType = File::mimeType($documentPath);

            if (! in_array($mimeType, $this->supportedMimeTypes(), true)) {
                return [
                    'success' => false,
                    'message' => 'Unsupported document mime type for OCR processing.',
                    'data' => null,
                ];
            }

            $payload = [
                'options' => array_replace($this->options(), $options),
                'sources' => [
                    [
                        'base64_string' => base64_encode(File::get($documentPath)),
                        'filename' => basename($documentPath),
                        'kind' => 'file',
                    ],
                ],
            ];

            /**
             * @var \Illuminate\Http\Client\Response $response
             */
            $response = Http::timeout($this->timeout())
                ->connectTimeout($this->connectTimeout())
                ->withHeaders($this->authHeaders())
                ->post("{$this->baseUrl()}/v1/convert/source", $payload);

            if ($response->failed()) {
                $this->log()->error('OCR processing request failed.', [
                    'document_path' => $documentPath,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'message' => 'OCR processing request failed.',
                    'data' => $response->json(),
                ];
            }

            return $this->interpretConvertResponse($response->json(), $documentPath);
        } catch (\Exception $e) {
            $this->log()->error('Single document processing failed.', [
                'document_path' => $documentPath,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Processing failed: ' . $e->getMessage(),
                'data' => null,
            ];
        }
    }

    /**
     * Combine results from multiple chunks. Page-break markers carry the
     * original chunk number, so a gap is visible when a chunk failed.
     *
     * Note: the combined json_content concatenates the per-chunk node arrays,
     * so internal $ref indices are only valid within their original chunk.
     * The 'text' and 'md_content' outputs are unaffected (placeholders are
     * resolved per chunk before combining).
     *
     * @param array<int, array{chunk: int, data: array}> $results
     */
    private function combineChunkResults(array $results, string $originalFilename): array
    {
        $combinedText = '';
        $combinedMdContent = '';
        $combinedJsonContent = [
            'texts' => [],
            'pictures' => [],
            'groups' => [],
            'tables' => [],
        ];

        foreach ($results as $position => $entry) {
            $chunkNumber = $entry['chunk'];
            $result = $entry['data'];

            if ($position > 0) {
                $marker = "\n\n<!-- [Page Break - Chunk {$chunkNumber}] -->\n\n";
                $combinedText .= $marker;
                $combinedMdContent .= $marker;
            }

            if (! empty($result['text'])) {
                $combinedText .= $result['text'];
            }

            if (! empty($result['md_content'])) {
                $combinedMdContent .= $result['md_content'];
            }

            if (! empty($result['json_content'])) {
                foreach (['texts', 'pictures', 'groups', 'tables'] as $key) {
                    if (isset($result['json_content'][$key]) && is_array($result['json_content'][$key])) {
                        $combinedJsonContent[$key] = array_merge(
                            $combinedJsonContent[$key],
                            $result['json_content'][$key]
                        );
                    }
                }
            }
        }

        return [
            'filename' => $originalFilename,
            'text' => $combinedText,
            'json_content' => $combinedJsonContent,
            'md_content' => $combinedMdContent,
        ];
    }

    /**
     * Clean up chunk files
     */
    private function cleanupChunks(array $chunkFiles): void
    {
        foreach ($chunkFiles as $chunkFile) {
            if (File::exists($chunkFile)) {
                File::delete($chunkFile);
            }
        }
    }
}
