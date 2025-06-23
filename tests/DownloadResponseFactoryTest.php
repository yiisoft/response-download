<?php

declare(strict_types=1);

namespace Yiisoft\ResponseDownload\Tests;

use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\StreamFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Yiisoft\Http\ContentDispositionHeader;
use Yiisoft\ResponseDownload\DownloadResponseFactory;

final class DownloadResponseFactoryTest extends TestCase
{
    public static function dataXSendFile(): array
    {
        $txtFilePath = self::getFilePath('answer.txt');
        $cssFilePath = self::getFilePath('style.css');
        $nonExistingFilePath = self::getFilePath('non-existing-file.txt');

        return [
            'defaults' => [
                ['filePath' => $txtFilePath],
                [
                    'X-Sendfile' => $txtFilePath,
                    'Content-Disposition' => 'attachment; filename="answer.txt"',
                    'Content-Type' => 'text/plain',
                ],
            ],
            'non-defaults' => [
                [
                    'filePath' => $cssFilePath,
                    'attachmentName' => 'style-custom.css',
                    'disposition' => ContentDispositionHeader::INLINE,
                    'mimeType' => 'text/css',
                    'xHeader' => 'X-Sendfile-Custom',
                ],
                [
                    'X-Sendfile-Custom' => $cssFilePath,
                    'Content-Disposition' => 'inline; filename="style-custom.css"',
                    'Content-Type' => 'text/css',
                ],
            ],
            'non-existing-file' => [
                ['filePath' => $nonExistingFilePath],
                [
                    'X-Sendfile' => $nonExistingFilePath,
                    'Content-Disposition' => 'attachment; filename="non-existing-file.txt"',
                    'Content-Type' => 'application/octet-stream',
                ],
            ],
        ];
    }

    /**
     * @dataProvider dataXSendFile
     */
    public function testXSendFile(array $arguments, array $expectedHeaders): void
    {
        $response = $this
            ->getDownloadResponseFactory()
            ->xSendFile(...$arguments);

        $this->assertResponseHeaders($expectedHeaders, $response);
    }

    public function testXSendFileWithWrongDisposition(): void
    {
        $responseFactory = $this->getDownloadResponseFactory();
        $filePath = self::getFilePath('answer.txt');

        $this->expectWrongDisposition();

        $responseFactory->xSendFile($filePath, disposition: 'a');
    }

    public static function dataSendStreamAsFile(): array
    {
        $txtContent = '42';
        $cssContent = 'body { font-size: 20px; }';

        return [
            'defaults' => [
                [
                    'stream' => (new StreamFactory())->createStream($txtContent),
                    'attachmentName' => 'answer.txt',
                ],
                [
                    'Content-Disposition' => 'attachment; filename="answer.txt"',
                    'Content-Type' => 'application/octet-stream',
                ],
                $txtContent,
            ],
            'non-defaults' => [
                [
                    'stream' => (new StreamFactory())->createStream($cssContent),
                    'attachmentName' => 'style.css',
                    'disposition' => ContentDispositionHeader::INLINE,
                    'mimeType' => 'text/css',
                ],
                [
                    'Content-Disposition' => 'inline; filename="style.css"',
                    'Content-Type' => 'text/css',
                ],
                $cssContent,
            ],
        ];
    }

    /**
     * @dataProvider dataSendStreamAsFile
     */
    public function testSendStreamAsFile(array $arguments, array $expectedHeaders, string $expectedBody): void
    {
        $response = $this
            ->getDownloadResponseFactory()
            ->sendStreamAsFile(...$arguments);

        $this->assertResponseHeaders($expectedHeaders, $response);
        $this->assertSame($expectedBody, (string) $response->getBody());
    }

    public function testSendStreamAsFileWithWrongDisposition(): void
    {
        $responseFactory = $this->getDownloadResponseFactory();
        $stream = (new StreamFactory())->createStream('42');

        $this->expectWrongDisposition();

        $responseFactory->sendStreamAsFile($stream, attachmentName: 'answer.txt', disposition: 'a');
    }

    public static function dataSendFile(): array
    {
        $txtFilePath = self::getFilePath('answer.txt');
        $cssFilePath = self::getFilePath('style.css');

        return [
            'defaults' => [
                [
                    'filePath' => $txtFilePath,
                ],
                [
                    'Content-Disposition' => 'attachment; filename="answer.txt"',
                    'Content-Type' => 'text/plain',
                ],
                '42',
            ],
            'non-defaults' => [
                [
                    'filePath' => $cssFilePath,
                    'attachmentName' => 'style-custom.css',
                    'disposition' => ContentDispositionHeader::INLINE,
                    'mimeType' => 'text/css',
                ],
                [
                    'Content-Disposition' => 'inline; filename="style-custom.css"',
                    'Content-Type' => 'text/css',
                ],
                'body { font-size: 20px; }',
            ],
        ];
    }

    /**
     * @dataProvider dataSendFile
     */
    public function testSendFile(array $arguments, array $expectedHeaders, string $expectedBody): void
    {
        $response = $this
            ->getDownloadResponseFactory()
            ->sendFile(...$arguments);

        $this->assertResponseHeaders($expectedHeaders, $response);
        $this->assertSame($expectedBody, rtrim((string) $response->getBody()));
    }

    public function testSendFileWithWrongDisposition(): void
    {
        $responseFactory = $this->getDownloadResponseFactory();
        $filePath = self::getFilePath('answer.txt');

        $this->expectWrongDisposition();

        $responseFactory->sendFile($filePath, disposition: 'a');
    }

    public function testSendFileWithNonExistingFile(): void
    {
        $responseFactory = $this->getDownloadResponseFactory();
        $filePath = self::getFilePath('not-existing-file.txt');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The stream or file cannot be opened.');
        $responseFactory->sendFile($filePath);
    }

    public static function dataSendContentAsFile(): array
    {
        $txtContent = '42';
        $cssContent = 'body { font-size: 20px; }';

        return [
            'defaults' => [
                [
                    'content' => $txtContent,
                    'attachmentName' => 'answer.txt',
                ],
                [
                    'Content-Disposition' => 'attachment; filename="answer.txt"',
                    'Content-Type' => PHP_VERSION_ID < 80300  ? 'application/octet-stream' : 'text/plain',
                ],
                $txtContent,
            ],
            'non-defaults' => [
                [
                    'content' => $cssContent,
                    'attachmentName' => 'style.css',
                    'disposition' => ContentDispositionHeader::INLINE,
                    'mimeType' => 'text/css',
                ],
                [
                    'Content-Disposition' => 'inline; filename="style.css"',
                    'Content-Type' => 'text/css',
                ],
                $cssContent,
            ],
        ];
    }

    /**
     * @dataProvider dataSendContentAsFile
     */
    public function testSendContentAsFile(array $arguments, array $expectedHeaders, string $expectedBody): void
    {
        $response = $this
            ->getDownloadResponseFactory()
            ->sendContentAsFile(...$arguments);

        $this->assertResponseHeaders($expectedHeaders, $response);
        $this->assertSame($expectedBody, (string) $response->getBody());
    }

    public function testSendContentAsFileWithWrongDisposition(): void
    {
        $responseFactory = $this->getDownloadResponseFactory();

        $this->expectWrongDisposition();
        $responseFactory->sendContentAsFile(content: '42', attachmentName: 'answer.txt', disposition: 'a');
    }

    private function getDownloadResponseFactory(): DownloadResponseFactory
    {
        $responseFactory = new ResponseFactory();
        $streamFactory = new StreamFactory();

        return new DownloadResponseFactory($responseFactory, $streamFactory);
    }

    private static function getFilePath(string $fileName): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . $fileName;
    }

    private function expectWrongDisposition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $message = 'Disposition value must be either "Yiisoft\Http\ContentDispositionHeader::ATTACHMENT" or ' .
            '"Yiisoft\Http\ContentDispositionHeader::INLINE", "a" given.';
        $this->expectExceptionMessage($message);
    }

    private function assertResponseHeaders(array $expectedHeaders, ResponseInterface $response): void
    {
        foreach ($expectedHeaders as $name => $value) {
            $this->assertSame($value, $response->getHeaderLine($name));
        }
    }
}
