<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Docling Server
    |--------------------------------------------------------------------------
    |
    | Base URL of your docling-serve deployment. If docling sits behind a
    | reverse proxy that strips a path prefix, include that prefix here
    | (e.g. https://host/docling) — it is prepended to /health and
    | /v1/convert/source.
    |
    */

    'base_url' => env('DOCLING_BASE_URL', 'http://localhost:5001'),

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | Two independent schemes, applied to every request (including the
    | /health probe). Set whichever your deployment uses; leave both empty
    | for an unauthenticated server.
    |
    |  - api_key      -> sent as the "X-Api-Key" header (native docling-serve
    |                    auth, DOCLING_SERVE_API_KEY).
    |  - bearer_token -> sent as "Authorization: Bearer <token>" (typical when
    |                    a reverse proxy in front of docling guards it).
    |
    | If both are set, both headers are sent.
    |
    */

    'api_key' => env('DOCLING_API_KEY'),

    'bearer_token' => env('DOCLING_BEARER_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | All service activity is logged to this channel. If the channel is not
    | defined in config/logging.php, the package registers it automatically
    | as a daily file at storage/logs/docling.log. Set to null to log to the
    | application's default channel instead.
    |
    */

    'log_channel' => env('DOCLING_LOG_CHANNEL', 'docling'),

    /*
    |--------------------------------------------------------------------------
    | Timeouts (seconds)
    |--------------------------------------------------------------------------
    |
    | 'timeout' bounds a single conversion request; OCR on dense pages is
    | slow, so keep it generous. 'health_timeout' bounds the /health probe
    | made before each conversion.
    |
    */

    'timeout' => (int) env('DOCLING_TIMEOUT', 300),

    'connect_timeout' => (int) env('DOCLING_CONNECT_TIMEOUT', 10),

    'health_timeout' => (int) env('DOCLING_HEALTH_TIMEOUT', 2),

    /*
    |--------------------------------------------------------------------------
    | Large-PDF Chunking
    |--------------------------------------------------------------------------
    |
    | PDFs with more pages than this are split into chunks of this size with
    | poppler-utils (pdfinfo/pdfseparate/pdfunite must be on the PATH) and
    | converted chunk by chunk, then recombined.
    |
    | 'temp_dir' is where chunk files are written while processing. Defaults
    | to storage/app/docling-tmp when null.
    |
    */

    'pages_per_chunk' => (int) env('DOCLING_PAGES_PER_CHUNK', 3),

    'temp_dir' => env('DOCLING_TEMP_DIR'),

    /*
    |--------------------------------------------------------------------------
    | Maximum File Size (bytes)
    |--------------------------------------------------------------------------
    |
    | Documents are read fully into memory before upload, so very large files
    | can exhaust PHP's memory_limit. Files above this size are rejected
    | before being read. Set to 0 to disable the guard.
    |
    */

    'max_file_size' => (int) env('DOCLING_MAX_FILE_SIZE', 50 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Conversion Options
    |--------------------------------------------------------------------------
    |
    | Passed verbatim as the "options" object to docling-serve's
    | /v1/convert/source endpoint. Anything here can also be overridden per
    | call: Docling::OCRProcessing($path, ['ocr_engine' => 'easyocr']).
    |
    | Note: 'to_formats' must include both 'md' and 'json' for image-text
    | placeholder extraction to work.
    |
    */

    'options' => [
        'from_formats' => ['image', 'pdf', 'docx', 'pptx', 'html', 'md', 'csv', 'xlsx', 'json_docling'],
        'to_formats' => ['md', 'json'],
        'image_export_mode' => 'placeholder',
        'force_ocr' => true,
        'ocr_engine' => env('DOCLING_OCR_ENGINE', 'rapidocr'),
        'ocr_lang' => ['en', 'ms'],
        'pdf_backend' => env('DOCLING_PDF_BACKEND', 'dlparse_v4'),
        'md_page_break_placeholder' => '<!-- [Page Break] -->',
    ],

];
