# Laravel Docling

Laravel client for [docling-serve](https://github.com/docling-project/docling-serve) — OCR and document-to-markdown conversion with automatic large-PDF chunking.

- Converts PDFs, Office documents, images, HTML, CSV, and more to Markdown + structured JSON via docling-serve's `/v1/convert/source` endpoint.
- Splits large PDFs into small chunks with poppler-utils so each request stays fast, then recombines the results with page-break markers.
- Resolves image placeholders in the markdown output into the OCR'd text of each image (`> **[Image Text]**:` blockquotes).
- Auto-registers a dedicated `docling` log channel (daily file at `storage/logs/docling.log`) — zero log config needed.

## Requirements

| Requirement                       | Notes                                                                                                                                                                                                                                                                                                                                                 |
| --------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| PHP ^8.1, Laravel 10/11/12/13     |                                                                                                                                                                                                                                                                                                                                                       |
| docling-serve **>= 1.0.0**        | The package uses the `/v1` API (`/v1/convert/source` with the unified `sources` payload), which 0.x deployments (`/v1alpha`) do not have. Verify your deployment supports the `rapidocr` OCR engine and `dlparse_v4` PDF backend, or switch via `DOCLING_OCR_ENGINE` / `DOCLING_PDF_BACKEND` (the standard docling-serve image ships with `easyocr`). |
| `poppler-utils` on the app server | Only needed for PDFs longer than `pages_per_chunk` pages. Provides `pdfinfo`, `pdfseparate`, `pdfunite`.                                                                                                                                                                                                                                              |

Installing poppler-utils:

```bash
# Debian / Ubuntu
sudo apt-get install -y poppler-utils

# Alpine (Docker)
apk add --no-cache poppler-utils

# macOS
brew install poppler
```

## Installation

This package is installed straight from GitHub (it is not on Packagist). In your app's `composer.json`, add:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/Khind-IQ/laravel-docling"
    }
]
```

Then:

```bash
composer require khind-iq/laravel-docling:dev-main
```

(Once you tag a release, e.g. `v1.0.0`, you can require `^1.0` instead of `dev-main`.)

The service provider and `Docling` facade are auto-discovered. Set your environment variables:

```dotenv
DOCLING_BASE_URL=http://your-docling-server:5001
DOCLING_API_KEY=your-api-key   # omit if the server is unauthenticated
```

That's it — no config file or log channel setup required. To customize defaults (OCR engine, languages, chunk size, timeouts), publish the config:

```bash
php artisan vendor:publish --tag=docling-config
```

## Usage

```php
use KhindIq\Docling\Facades\Docling;

$result = Docling::OCRProcessing(storage_path('app/uploads/resume.pdf'));

if ($result['success']) {
    $markdown = $result['data']['text'];        // markdown with image text resolved
    $rawMd    = $result['data']['md_content'];  // markdown as returned by docling
    $json     = $result['data']['json_content']; // structured DoclingDocument JSON
}
```

Or via dependency injection:

```php
use KhindIq\Docling\DoclingService;

public function handle(DoclingService $docling)
{
    $result = $docling->OCRProcessing($this->path);
}
```

Per-call option overrides (anything under `config('docling.options')`):

```php
Docling::OCRProcessing($path, [
    'ocr_engine' => 'easyocr',
    'ocr_lang' => ['en'],
    'force_ocr' => false,
]);
```

### Return shape

```php
[
    'success' => true|false,
    'message' => '...',
    'data' => [
        'filename' => 'resume.pdf',
        'text' => '...',         // md_content with <!-- Image --> placeholders replaced by OCR'd image text
        'md_content' => '...',   // raw markdown from docling
        'json_content' => [...], // DoclingDocument JSON
        // Chunked PDFs only:
        'chunks_total' => 10,    // how many chunks the PDF was split into
        'chunks_failed' => [],   // original chunk numbers that failed
    ],
]
```

On failure, `data` is usually `null` — with two exceptions: when the conversion request itself failed, `data` holds the server's error JSON; and when **some** chunks of a chunked PDF failed, `success` is `false` but `data` still carries the partial combined result with `chunks_total`/`chunks_failed`, so you can decide whether the partial text is usable. `success => true` always means a complete conversion.

### Run it from a queue, not a web request

Conversion is synchronous and can take minutes for OCR-heavy documents (the per-chunk timeout defaults to 300s). Dispatch it from a queued job:

```php
class ProcessDocument implements ShouldQueue
{
    public int $timeout = 3600;

    public function __construct(private string $path) {}

    public function handle(DoclingService $docling): void
    {
        $result = $docling->OCRProcessing($this->path);
        // ... store $result['data']['text']
    }
}
```

## Configuration

All keys in `config/docling.php`:

| Key                   | Env                       | Default                   | Purpose                                                                   |
| --------------------- | ------------------------- | ------------------------- | ------------------------------------------------------------------------- |
| `base_url`            | `DOCLING_BASE_URL`        | `http://localhost:5001`   | docling-serve URL                                                         |
| `api_key`             | `DOCLING_API_KEY`         | `null`                    | Sent as `X-Api-Key` header                                                |
| `log_channel`         | `DOCLING_LOG_CHANNEL`     | `docling`                 | Auto-registered if undefined; `null` = app default channel                |
| `timeout`             | `DOCLING_TIMEOUT`         | `300`                     | Seconds per conversion request                                            |
| `connect_timeout`     | `DOCLING_CONNECT_TIMEOUT` | `10`                      | Seconds to establish the connection                                       |
| `health_timeout`      | `DOCLING_HEALTH_TIMEOUT`  | `2`                       | Seconds for the pre-flight `/health` probe                                |
| `pages_per_chunk`     | `DOCLING_PAGES_PER_CHUNK` | `3`                       | PDFs above this page count are split                                      |
| `temp_dir`            | `DOCLING_TEMP_DIR`        | `storage/app/docling-tmp` | Where PDF chunks are written                                              |
| `max_file_size`       | `DOCLING_MAX_FILE_SIZE`   | `52428800` (50 MB)        | Files above this are rejected before being read into memory; `0` disables |
| `options.ocr_engine`  | `DOCLING_OCR_ENGINE`      | `rapidocr`                | OCR engine on the docling-serve side                                      |
| `options.pdf_backend` | `DOCLING_PDF_BACKEND`     | `dlparse_v4`              | PDF parser backend                                                        |
| `options.*` (other)   | —                         | see config                | Remaining conversion options; publish the config to change them           |

## Caveats

- **Partial chunk failures**: if some (but not all) chunks of a large PDF fail, the result is `success => false` with the partial combined text still available in `data`, plus `chunks_failed` listing which chunks are missing. The page-break markers keep their original chunk numbers, so gaps are visible in the text too.
- **Chunked PDFs and `json_content`**: for PDFs processed in chunks, the combined `json_content` concatenates per-chunk node arrays, so internal `$ref` indices are only valid within their original chunk. `text` and `md_content` are unaffected. If you consume `json_content` structurally, raise `pages_per_chunk` high enough to avoid chunking.
- **OCR engine availability**: the request fails if the docling-serve deployment doesn't have the configured `ocr_engine` installed. `easyocr` (the docling-serve default) explicitly supports Malay (`ms`); verify `rapidocr` language coverage before relying on it.
- **Legacy Office formats are not supported**: docling only converts OOXML (`.docx`/`.pptx`/`.xlsx`); legacy `.doc`/`.ppt`/`.xls` files are rejected client-side. `application/json` input must be DoclingDocument JSON (`json_docling`), not arbitrary JSON.
- **Page count fallbacks**: if `pdfinfo` is missing, the package falls back to ghostscript, mutool, then a regex estimate — but splitting still requires `pdfseparate`/`pdfunite` from poppler-utils.

## Testing

```bash
composer install
composer test
```

## License

MIT
