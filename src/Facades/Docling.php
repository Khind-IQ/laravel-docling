<?php

namespace KhindIq\Docling\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool isConfigured()
 * @method static array{success: bool, message: string, data: ?array} OCRProcessing(string $documentPath, array $options = [])
 *
 * @see \KhindIq\Docling\DoclingService
 */
class Docling extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \KhindIq\Docling\DoclingService::class;
    }
}
