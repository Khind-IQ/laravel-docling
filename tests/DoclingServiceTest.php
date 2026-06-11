<?php

namespace KhindIq\Docling\Tests;

use Illuminate\Support\Facades\Http;
use KhindIq\Docling\DoclingService;
use KhindIq\Docling\Facades\Docling;

class DoclingServiceTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureDir = sys_get_temp_dir() . '/docling-tests-' . uniqid();
        mkdir($this->fixtureDir, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->fixtureDir . '/*') ?: []);
        @rmdir($this->fixtureDir);

        parent::tearDown();
    }

    private function fixture(string $name, string $content): string
    {
        $path = $this->fixtureDir . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    public function test_service_is_registered_as_singleton(): void
    {
        $this->assertInstanceOf(DoclingService::class, $this->app->make(DoclingService::class));
        $this->assertSame(
            $this->app->make(DoclingService::class),
            $this->app->make('docling')
        );
    }

    public function test_config_is_merged_with_defaults(): void
    {
        $this->assertSame('http://docling.test', config('docling.base_url'));
        $this->assertIsArray(config('docling.options'));
        $this->assertContains('md', config('docling.options.to_formats'));
    }

    public function test_fails_gracefully_when_server_is_down(): void
    {
        Http::fake([
            'docling.test/health' => Http::response(null, 503),
        ]);

        $result = Docling::OCRProcessing($this->fixture('doc.txt', 'hello'));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not properly configured', $result['message']);
    }

    public function test_rejects_invalid_path(): void
    {
        Http::fake([
            'docling.test/health' => Http::response('ok'),
        ]);

        $result = Docling::OCRProcessing($this->fixtureDir . '/does-not-exist.pdf');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid document path', $result['message']);
    }

    public function test_converts_a_text_document(): void
    {
        Http::fake([
            'docling.test/health' => Http::response('ok'),
            'docling.test/v1/convert/source' => Http::response([
                'document' => [
                    'filename' => 'doc.txt',
                    'md_content' => '# Hello',
                    'json_content' => ['texts' => [], 'pictures' => []],
                ],
                'status' => 'success',
            ]),
        ]);

        $result = Docling::OCRProcessing($this->fixture('doc.txt', 'hello world'));

        $this->assertTrue($result['success']);
        $this->assertSame('# Hello', $result['data']['md_content']);
        $this->assertSame('# Hello', $result['data']['text']);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://docling.test/v1/convert/source'
                && $request->hasHeader('X-Api-Key', 'test-key')
                && $request['sources'][0]['filename'] === 'doc.txt'
                && base64_decode($request['sources'][0]['base64_string']) === 'hello world'
                && $request['options']['to_formats'] === ['md', 'json'];
        });
    }

    public function test_per_call_options_override_config(): void
    {
        Http::fake([
            'docling.test/health' => Http::response('ok'),
            'docling.test/v1/convert/source' => Http::response([
                'document' => ['filename' => 'doc.txt', 'md_content' => 'x', 'json_content' => []],
            ]),
        ]);

        Docling::OCRProcessing(
            $this->fixture('doc.txt', 'hello'),
            ['ocr_engine' => 'easyocr', 'force_ocr' => false]
        );

        Http::assertSent(function ($request) {
            return $request->url() === 'http://docling.test/v1/convert/source'
                && $request['options']['ocr_engine'] === 'easyocr'
                && $request['options']['force_ocr'] === false
                && $request['options']['pdf_backend'] === 'dlparse_v4';
        });
    }

    public function test_replaces_image_placeholders_with_extracted_text(): void
    {
        Http::fake([
            'docling.test/health' => Http::response('ok'),
            'docling.test/v1/convert/source' => Http::response([
                'document' => [
                    'filename' => 'doc.txt',
                    'md_content' => "Before\n<!-- Image -->\nAfter",
                    'json_content' => [
                        'texts' => [
                            ['text' => 'Caption from OCR'],
                        ],
                        'pictures' => [
                            ['children' => [['$ref' => '#/texts/0']]],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = Docling::OCRProcessing($this->fixture('doc.txt', 'hello'));

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('**[Image Text]**', $result['data']['text']);
        $this->assertStringContainsString('> Caption from OCR', $result['data']['text']);
        $this->assertStringNotContainsString('<!-- Image -->', $result['data']['text']);
    }

    public function test_unsupported_mime_type_is_rejected(): void
    {
        Http::fake([
            'docling.test/health' => Http::response('ok'),
        ]);

        // A .zip file is not in the supported list
        $zip = new \ZipArchive();
        $zipPath = $this->fixtureDir . '/archive.zip';
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('a.txt', 'x');
        $zip->close();

        $result = Docling::OCRProcessing($zipPath);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unsupported document mime type', $result['message']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/convert/source'));
    }

    public function test_failed_conversion_request_is_reported(): void
    {
        Http::fake([
            'docling.test/health' => Http::response('ok'),
            'docling.test/v1/convert/source' => Http::response(['detail' => 'boom'], 500),
        ]);

        $result = Docling::OCRProcessing($this->fixture('doc.txt', 'hello'));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('request failed', $result['message']);
        $this->assertSame(['detail' => 'boom'], $result['data']);
    }

    public function test_no_api_key_header_when_key_is_empty(): void
    {
        // No forgetInstance needed: the singleton reads config lazily,
        // so runtime config changes take effect immediately.
        config()->set('docling.api_key', null);

        Http::fake([
            'docling.test/health' => Http::response('ok'),
            'docling.test/v1/convert/source' => Http::response([
                'document' => ['filename' => 'doc.txt', 'md_content' => 'x', 'json_content' => []],
            ]),
        ]);

        $this->app->make(DoclingService::class)->OCRProcessing($this->fixture('doc.txt', 'hello'));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1/convert/source')
                && ! $request->hasHeader('X-Api-Key');
        });
    }

    public function test_is_configured_returns_false_without_base_url(): void
    {
        $service = new DoclingService(['base_url' => '']);

        $this->assertFalse($service->isConfigured());
    }

    public function test_bearer_token_is_sent_on_both_health_and_convert(): void
    {
        config()->set('docling.api_key', null);
        config()->set('docling.bearer_token', 'tok-123');

        Http::fake([
            'docling.test/health' => Http::response('ok'),
            'docling.test/v1/convert/source' => Http::response([
                'document' => ['filename' => 'doc.txt', 'md_content' => 'x', 'json_content' => []],
            ]),
        ]);

        $result = Docling::OCRProcessing($this->fixture('doc.txt', 'hello'));

        $this->assertTrue($result['success']);

        // The /health probe must carry auth — a reverse proxy guards it too.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/health')
            && $request->hasHeader('Authorization', 'Bearer tok-123'));

        // Bearer-only deployment: no X-Api-Key on the conversion request.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/convert/source')
            && $request->hasHeader('Authorization', 'Bearer tok-123')
            && ! $request->hasHeader('X-Api-Key'));
    }

    public function test_both_auth_headers_are_sent_when_both_configured(): void
    {
        config()->set('docling.api_key', 'key-abc');
        config()->set('docling.bearer_token', 'tok-123');

        Http::fake([
            'docling.test/health' => Http::response('ok'),
            'docling.test/v1/convert/source' => Http::response([
                'document' => ['filename' => 'doc.txt', 'md_content' => 'x', 'json_content' => []],
            ]),
        ]);

        Docling::OCRProcessing($this->fixture('doc.txt', 'hello'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/convert/source')
            && $request->hasHeader('X-Api-Key', 'key-abc')
            && $request->hasHeader('Authorization', 'Bearer tok-123'));
    }

    public function test_base_url_path_prefix_is_preserved(): void
    {
        config()->set('docling.base_url', 'https://ie.khind.com/docling');
        config()->set('docling.bearer_token', 'tok-123');

        Http::fake([
            'ie.khind.com/docling/health' => Http::response('ok'),
            'ie.khind.com/docling/v1/convert/source' => Http::response([
                'document' => ['filename' => 'doc.txt', 'md_content' => 'x', 'json_content' => []],
            ]),
        ]);

        $result = Docling::OCRProcessing($this->fixture('doc.txt', 'hello'));

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request) => $request->url() === 'https://ie.khind.com/docling/v1/convert/source');
    }

    public function test_http_200_with_failure_status_is_not_success(): void
    {
        Http::fake([
            'docling.test/health' => Http::response('ok'),
            'docling.test/v1/convert/source' => Http::response([
                'document' => ['filename' => 'doc.txt', 'md_content' => null, 'json_content' => null],
                'status' => 'failure',
                'errors' => ['OCR engine not available'],
            ]),
        ]);

        $result = Docling::OCRProcessing($this->fixture('doc.txt', 'hello'));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('status: failure', $result['message']);
    }

    public function test_http_200_without_document_key_is_not_success(): void
    {
        Http::fake([
            'docling.test/health' => Http::response('ok'),
            'docling.test/v1/convert/source' => Http::response(['unexpected' => 'body']),
        ]);

        $result = Docling::OCRProcessing($this->fixture('doc.txt', 'hello'));

        $this->assertFalse($result['success']);
        $this->assertSame(['unexpected' => 'body'], $result['data']);
    }

    public function test_rejects_files_over_max_file_size(): void
    {
        config()->set('docling.max_file_size', 4);

        Http::fake([
            'docling.test/health' => Http::response('ok'),
        ]);

        $result = Docling::OCRProcessing($this->fixture('doc.txt', 'more than four bytes'));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('maximum file size', $result['message']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/convert/source'));
    }
}
