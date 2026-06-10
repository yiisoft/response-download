<?php

declare(strict_types=1);

namespace Yiisoft\ResponseDownload\Tests;

use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequestFactory;
use HttpSoft\Message\StreamFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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

    public function testSendFileWithRangeRequest(): void
    {
        $response = $this
            ->getDownloadResponseFactory()
            ->sendFile(self::getFilePath('style.css'), mimeType: 'text/css', request: $this->createRequest('bytes=7-15'));

        $this->assertSame(206, $response->getStatusCode());
        $this->assertResponseHeaders(
            [
                'Accept-Ranges' => 'bytes',
                'Content-Range' => 'bytes 7-15/26',
                'Content-Length' => '9',
                'Content-Disposition' => 'attachment; filename="style.css"',
                'Content-Type' => 'text/css',
            ],
            $response,
        );
        $this->assertSame('font-size', (string) $response->getBody());
    }

    public function testSendFileWithOpenEndedRangeRequest(): void
    {
        $response = $this
            ->getDownloadResponseFactory()
            ->sendFile(self::getFilePath('style.css'), request: $this->createRequest('bytes=21-'));

        $this->assertSame(206, $response->getStatusCode());
        $this->assertResponseHeaders(
            [
                'Accept-Ranges' => 'bytes',
                'Content-Range' => 'bytes 21-25/26',
                'Content-Length' => '5',
            ],
            $response,
        );
        $this->assertSame("x; }\n", (string) $response->getBody());
    }

    public function testSendContentAsFileWithCaseInsensitiveRangeUnit(): void
    {
        $response = $this
            ->getDownloadResponseFactory()
            ->sendContentAsFile(
                'abcdef',
                'alphabet.txt',
                mimeType: 'text/plain',
                request: $this->createRequest('Bytes=1-3'),
            );

        $this->assertSame(206, $response->getStatusCode());
        $this->assertResponseHeaders(
            [
                'Accept-Ranges' => 'bytes',
                'Content-Range' => 'bytes 1-3/6',
                'Content-Length' => '3',
            ],
            $response,
        );
        $this->assertSame('bcd', (string) $response->getBody());
    }

    public function testSendContentAsFileWithSuffixRangeRequest(): void
    {
        $response = $this
            ->getDownloadResponseFactory()
            ->sendContentAsFile(
                'abcdef',
                'alphabet.txt',
                mimeType: 'text/plain',
                request: $this->createRequest('bytes=-3'),
            );

        $this->assertSame(206, $response->getStatusCode());
        $this->assertResponseHeaders(
            [
                'Accept-Ranges' => 'bytes',
                'Content-Range' => 'bytes 3-5/6',
                'Content-Length' => '3',
            ],
            $response,
        );
        $this->assertSame('def', (string) $response->getBody());
    }

    public function testSendContentAsFileWithUnsupportedRangeRequest(): void
    {
        $response = $this
            ->getDownloadResponseFactory()
            ->sendContentAsFile(
                'abcdef',
                'alphabet.txt',
                mimeType: 'text/plain',
                request: $this->createRequest('bytes=0-1,3-4'),
            );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertResponseHeaders(
            [
                'Accept-Ranges' => 'bytes',
                'Content-Length' => '6',
                'Content-Disposition' => 'attachment; filename="alphabet.txt"',
                'Content-Type' => 'text/plain',
            ],
            $response,
        );
        $this->assertSame('', $response->getHeaderLine('Content-Range'));
        $this->assertSame('abcdef', (string) $response->getBody());
    }

    public function testSendContentAsFileWithMalformedRangeRequest(): void
    {
        $response = $this
            ->getDownloadResponseFactory()
            ->sendContentAsFile(
                'abcdef',
                'alphabet.txt',
                mimeType: 'text/plain',
                request: $this->createRequest('bytes=foo'),
            );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertResponseHeaders(
            [
                'Accept-Ranges' => 'bytes',
                'Content-Length' => '6',
            ],
            $response,
        );
        $this->assertSame('', $response->getHeaderLine('Content-Range'));
        $this->assertSame('abcdef', (string) $response->getBody());
    }

    public function testSendFileWithUnsatisfiableRangeRequest(): void
    {
        $response = $this
            ->getDownloadResponseFactory()
            ->sendFile(self::getFilePath('answer.txt'), request: $this->createRequest('bytes=3-'));

        $this->assertSame(416, $response->getStatusCode());
        $this->assertResponseHeaders(
            [
                'Accept-Ranges' => 'bytes',
                'Content-Range' => 'bytes */3',
                'Content-Length' => '0',
            ],
            $response,
        );
        $this->assertSame('', (string) $response->getBody());
    }

    public static function dataSendContentAsFile(): array
    {
        $txtContent = '42';
        $binContent = "\x00\xFF\xA5\x5A";
        $cssContent = 'body { font-size: 20px; }';

        return [
            'defaults' => [
                [
                    'content' => $txtContent,
                    'attachmentName' => 'answer.txt',
                ],
                [
                    'Content-Disposition' => 'attachment; filename="answer.txt"',
                    'Content-Type' => PHP_VERSION_ID < 80300 ? 'application/octet-stream' : 'text/plain',
                ],
                $txtContent,
            ],
            'defaultsBinaryContent' => [
                [
                    'content' => $binContent,
                    'attachmentName' => 'example.bin',
                ],
                [
                    'Content-Disposition' => 'attachment; filename="example.bin"',
                    'Content-Type' => 'application/octet-stream',
                ],
                $binContent,
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

    private function createRequest(?string $range = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');

        return $range === null ? $request : $request->withHeader('Range', $range);
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
