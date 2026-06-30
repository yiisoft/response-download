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
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Yiisoft\Http\ContentDispositionHeader;
use Yiisoft\ResponseDownload\ByteRangeStream;
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

    public function testSendStreamAsFileWithRangeRequestRewindsStream(): void
    {
        $stream = (new StreamFactory())->createStream('abcdef');
        $stream->seek(3);

        $response = $this
            ->getDownloadResponseFactory()
            ->sendStreamAsFile(
                $stream,
                'alphabet.txt',
                mimeType: 'text/plain',
                request: $this->createRequest('bytes=1-3'),
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

    public function testSendStreamAsFileWithRangeRequestDoesNotSupportNonSeekableStream(): void
    {
        $response = $this
            ->getDownloadResponseFactory()
            ->sendStreamAsFile(
                $this->createNonSeekableStream('abcdef'),
                'alphabet.txt',
                mimeType: 'text/plain',
                request: $this->createRequest('bytes=1-3'),
            );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->getHeaderLine('Accept-Ranges'));
        $this->assertSame('', $response->getHeaderLine('Content-Length'));
        $this->assertSame('abcdef', (string) $response->getBody());
    }

    public function testSendStreamAsFileWithRangeRequestDoesNotSupportNonReadableStream(): void
    {
        $response = $this
            ->getDownloadResponseFactory()
            ->sendStreamAsFile(
                $this->createNonReadableSeekableStream('abcdef'),
                'alphabet.txt',
                mimeType: 'text/plain',
                request: $this->createRequest('bytes=1-3'),
            );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->getHeaderLine('Accept-Ranges'));
        $this->assertSame('', $response->getHeaderLine('Content-Length'));
        $this->assertSame('abcdef', (string) $response->getBody());
    }

    public function testSendStreamAsFileWithRequestWithoutRangeRewindsStream(): void
    {
        $stream = (new StreamFactory())->createStream('abcdef');
        $stream->seek(3);

        $response = $this
            ->getDownloadResponseFactory()
            ->sendStreamAsFile(
                $stream,
                'alphabet.txt',
                mimeType: 'text/plain',
                request: $this->createRequest(),
            );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertResponseHeaders(
            [
                'Accept-Ranges' => 'bytes',
                'Content-Length' => '6',
            ],
            $response,
        );
        $this->assertSame('abcdef', (string) $response->getBody());
    }

    public function testSendStreamAsFileWithRequestWithoutRangeCanBeReadByEmitter(): void
    {
        $stream = (new StreamFactory())->createStream('abcdef');
        $stream->seek(3);

        $body = $this
            ->getDownloadResponseFactory()
            ->sendStreamAsFile(
                $stream,
                'alphabet.txt',
                mimeType: 'text/plain',
                request: $this->createRequest(),
            )
            ->getBody();

        $this->assertSame('abc', $body->read(3));
        $this->assertSame('def', $body->read(3));
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

    public function testSendContentAsFileWithSingleByteRangeRequest(): void
    {
        $response = $this
            ->getDownloadResponseFactory()
            ->sendContentAsFile(
                'abcdef',
                'alphabet.txt',
                mimeType: 'text/plain',
                request: $this->createRequest('bytes=0-0'),
            );

        $this->assertSame(206, $response->getStatusCode());
        $this->assertResponseHeaders(
            [
                'Accept-Ranges' => 'bytes',
                'Content-Range' => 'bytes 0-0/6',
                'Content-Length' => '1',
            ],
            $response,
        );
        $this->assertSame('a', (string) $response->getBody());
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

    public function testSendContentAsFileWithFullSuffixRangeRequest(): void
    {
        $response = $this
            ->getDownloadResponseFactory()
            ->sendContentAsFile(
                'abcdef',
                'alphabet.txt',
                mimeType: 'text/plain',
                request: $this->createRequest('bytes=-6'),
            );

        $this->assertSame(206, $response->getStatusCode());
        $this->assertResponseHeaders(
            [
                'Accept-Ranges' => 'bytes',
                'Content-Range' => 'bytes 0-5/6',
                'Content-Length' => '6',
            ],
            $response,
        );
        $this->assertSame('abcdef', (string) $response->getBody());
    }

    public function testSendContentAsFileWithZeroSuffixRangeRequest(): void
    {
        $response = $this
            ->getDownloadResponseFactory()
            ->sendContentAsFile(
                'abcdef',
                'alphabet.txt',
                mimeType: 'text/plain',
                request: $this->createRequest('bytes=-0'),
            );

        $this->assertSame(416, $response->getStatusCode());
        $this->assertResponseHeaders(
            [
                'Accept-Ranges' => 'bytes',
                'Content-Range' => 'bytes */6',
                'Content-Length' => '0',
            ],
            $response,
        );
        $this->assertSame('', (string) $response->getBody());
    }

    public function testSendContentAsFileWithRangeEndGreaterThanContentSize(): void
    {
        $response = $this
            ->getDownloadResponseFactory()
            ->sendContentAsFile(
                'abcdef',
                'alphabet.txt',
                mimeType: 'text/plain',
                request: $this->createRequest('bytes=4-99'),
            );

        $this->assertSame(206, $response->getStatusCode());
        $this->assertResponseHeaders(
            [
                'Accept-Ranges' => 'bytes',
                'Content-Range' => 'bytes 4-5/6',
                'Content-Length' => '2',
            ],
            $response,
        );
        $this->assertSame('ef', (string) $response->getBody());
    }

    public function testSendContentAsFileWithWhitespaceAroundRangeRequest(): void
    {
        $response = $this
            ->getDownloadResponseFactory()
            ->sendContentAsFile(
                'abcdef',
                'alphabet.txt',
                mimeType: 'text/plain',
                request: $this->createRequest(' bytes=1-3 '),
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

    public function testSendContentAsFileWithRangeBodyReadByChunks(): void
    {
        $body = $this
            ->getDownloadResponseFactory()
            ->sendContentAsFile(
                'abcdef',
                'alphabet.txt',
                mimeType: 'text/plain',
                request: $this->createRequest('bytes=1-4'),
            )
            ->getBody();

        $this->assertSame(4, $body->getSize());
        $this->assertSame(0, $body->tell());
        $this->assertSame('bc', $body->read(2));
        $this->assertSame(2, $body->tell());
        $this->assertFalse($body->eof());
        $this->assertSame('de', $body->read(10));
        $this->assertSame(4, $body->tell());
        $this->assertTrue($body->eof());
        $this->assertSame('', $body->read(1));
    }

    public function testSendContentAsFileWithRangeBodyCanSeekAndRewind(): void
    {
        $body = $this
            ->getDownloadResponseFactory()
            ->sendContentAsFile(
                'abcdef',
                'alphabet.txt',
                mimeType: 'text/plain',
                request: $this->createRequest('bytes=2-5'),
            )
            ->getBody();

        $this->assertSame('c', $body->read(1));
        $body->seek(2);
        $this->assertSame('e', $body->read(1));
        $body->seek(-1, SEEK_END);
        $this->assertSame('f', $body->read(1));
        $body->rewind();
        $this->assertSame('cdef', $body->getContents());
    }

    public function testSendContentAsFileWithRangeBodyCanSeekFromCurrentPosition(): void
    {
        $body = $this
            ->getDownloadResponseFactory()
            ->sendContentAsFile(
                'abcdef',
                'alphabet.txt',
                mimeType: 'text/plain',
                request: $this->createRequest('bytes=1-5'),
            )
            ->getBody();

        $this->assertSame('b', $body->read(1));
        $body->seek(2, SEEK_CUR);
        $this->assertSame('e', $body->read(1));
    }

    public function testSendContentAsFileWithRangeBodyCanSeekToEnd(): void
    {
        $body = $this
            ->getDownloadResponseFactory()
            ->sendContentAsFile(
                'abcdef',
                'alphabet.txt',
                mimeType: 'text/plain',
                request: $this->createRequest('bytes=1-5'),
            )
            ->getBody();

        $body->seek(0, SEEK_END);

        $this->assertSame(5, $body->tell());
        $this->assertTrue($body->eof());
        $this->assertSame('', $body->read(1));
    }

    public function testSendContentAsFileWithRangeBodyStringCastRewindsBody(): void
    {
        $body = $this
            ->getDownloadResponseFactory()
            ->sendContentAsFile(
                'abcdef',
                'alphabet.txt',
                mimeType: 'text/plain',
                request: $this->createRequest('bytes=1-4'),
            )
            ->getBody();

        $this->assertSame('bc', $body->read(2));
        $this->assertSame('bcde', (string) $body);
    }

    public function testSendContentAsFileWithRangeBodyZeroLengthRead(): void
    {
        $body = $this
            ->getDownloadResponseFactory()
            ->sendContentAsFile(
                'abcdef',
                'alphabet.txt',
                mimeType: 'text/plain',
                request: $this->createRequest('bytes=1-4'),
            )
            ->getBody();

        $this->assertSame('', $body->read(0));
        $this->assertSame(0, $body->tell());
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

    public function testSendContentAsFileWithMalformedRangeRequestPrefix(): void
    {
        $response = $this
            ->getDownloadResponseFactory()
            ->sendContentAsFile(
                'abcdef',
                'alphabet.txt',
                mimeType: 'text/plain',
                request: $this->createRequest('bytes=foo1-3'),
            );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->getHeaderLine('Content-Range'));
        $this->assertSame('abcdef', (string) $response->getBody());
    }

    public function testSendContentAsFileWithMalformedRangeRequestSuffix(): void
    {
        $response = $this
            ->getDownloadResponseFactory()
            ->sendContentAsFile(
                'abcdef',
                'alphabet.txt',
                mimeType: 'text/plain',
                request: $this->createRequest('bytes=1-3foo'),
            );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->getHeaderLine('Content-Range'));
        $this->assertSame('abcdef', (string) $response->getBody());
    }

    public function testSendContentAsFileWithEmptyMalformedRangeRequest(): void
    {
        $response = $this
            ->getDownloadResponseFactory()
            ->sendContentAsFile(
                'abcdef',
                'alphabet.txt',
                mimeType: 'text/plain',
                request: $this->createRequest('bytes=-'),
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

    public function testSendContentAsFileWithSuffixRangeRequestGreaterThanContentSize(): void
    {
        $response = $this
            ->getDownloadResponseFactory()
            ->sendContentAsFile(
                'abcdef',
                'alphabet.txt',
                mimeType: 'text/plain',
                request: $this->createRequest('bytes=-99'),
            );

        $this->assertSame(206, $response->getStatusCode());
        $this->assertResponseHeaders(
            [
                'Accept-Ranges' => 'bytes',
                'Content-Range' => 'bytes 0-5/6',
                'Content-Length' => '6',
            ],
            $response,
        );
        $this->assertSame('abcdef', (string) $response->getBody());
    }

    public function testSendContentAsFileWithRangeRequestForEmptyContent(): void
    {
        $response = $this
            ->getDownloadResponseFactory()
            ->sendContentAsFile(
                '',
                'empty.txt',
                mimeType: 'text/plain',
                request: $this->createRequest('bytes=-1'),
            );

        $this->assertSame(416, $response->getStatusCode());
        $this->assertResponseHeaders(
            [
                'Accept-Ranges' => 'bytes',
                'Content-Range' => 'bytes */0',
                'Content-Length' => '0',
            ],
            $response,
        );
        $this->assertSame('', (string) $response->getBody());
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

    public function testSendContentAsFileWithExplicitRangeStartingAtContentSize(): void
    {
        $response = $this
            ->getDownloadResponseFactory()
            ->sendContentAsFile(
                'abcdef',
                'alphabet.txt',
                mimeType: 'text/plain',
                request: $this->createRequest('bytes=6-6'),
            );

        $this->assertSame(416, $response->getStatusCode());
        $this->assertResponseHeaders(
            [
                'Accept-Ranges' => 'bytes',
                'Content-Range' => 'bytes */6',
                'Content-Length' => '0',
            ],
            $response,
        );
        $this->assertSame('', (string) $response->getBody());
    }

    public function testSendContentAsFileWithRangeEndBeforeRangeStart(): void
    {
        $response = $this
            ->getDownloadResponseFactory()
            ->sendContentAsFile(
                'abcdef',
                'alphabet.txt',
                mimeType: 'text/plain',
                request: $this->createRequest('bytes=4-2'),
            );

        $this->assertSame(416, $response->getStatusCode());
        $this->assertResponseHeaders(
            [
                'Accept-Ranges' => 'bytes',
                'Content-Range' => 'bytes */6',
                'Content-Length' => '0',
            ],
            $response,
        );
        $this->assertSame('', (string) $response->getBody());
    }

    public function testByteRangeStreamRejectsInvalidRange(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid byte range.');

        new ByteRangeStream((new StreamFactory())->createStream('abcdef'), 3, 2);
    }

    public function testByteRangeStreamRejectsNonSeekableStream(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stream is not seekable.');

        new ByteRangeStream($this->createNonSeekableStream('abcdef'), 1, 3);
    }

    public function testByteRangeStreamRejectsNonReadableStream(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stream is not readable.');

        new ByteRangeStream($this->createNonReadableSeekableStream('abcdef'), 1, 3);
    }

    public function testByteRangeStreamRejectsInvalidSeekMode(): void
    {
        $body = new ByteRangeStream((new StreamFactory())->createStream('abcdef'), 1, 3);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid seek mode.');

        $body->seek(0, 999);
    }

    public function testByteRangeStreamRejectsInvalidSeekOffset(): void
    {
        $body = new ByteRangeStream((new StreamFactory())->createStream('abcdef'), 1, 3);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid seek offset.');

        $body->seek(4);
    }

    public function testByteRangeStreamRejectsNegativeReadLength(): void
    {
        $body = new ByteRangeStream((new StreamFactory())->createStream('abcdef'), 1, 3);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Length parameter cannot be negative.');

        $body->read(-1);
    }

    public function testByteRangeStreamRejectsWrite(): void
    {
        $body = new ByteRangeStream((new StreamFactory())->createStream('abcdef'), 1, 3);

        $this->assertFalse($body->isWritable());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stream is not writable.');

        $body->write('x');
    }

    public function testByteRangeStreamDelegatesCloseDetachAndMetadata(): void
    {
        $resource = fopen('php://memory', 'rb');
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('isSeekable')->willReturn(true);
        $stream->method('isReadable')->willReturn(true);
        $stream->expects($this->once())->method('seek')->with(1);
        $stream->expects($this->once())->method('close');
        $stream->expects($this->once())->method('detach')->willReturn($resource);
        $stream->expects($this->exactly(2))
            ->method('getMetadata')
            ->willReturnMap(
                [
                    [null, ['uri' => 'php://memory']],
                    ['uri', 'php://memory'],
                ],
            );

        $body = new ByteRangeStream($stream, 1, 3);

        $this->assertTrue($body->isSeekable());
        $this->assertTrue($body->isReadable());
        $this->assertSame(['uri' => 'php://memory'], $body->getMetadata());
        $this->assertSame('php://memory', $body->getMetadata('uri'));
        $this->assertSame($resource, $body->detach());

        $body->close();
        fclose($resource);
    }

    public function testByteRangeStreamStringCastReturnsEmptyStringOnFailure(): void
    {
        $calls = 0;
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('isSeekable')->willReturn(true);
        $stream->method('isReadable')->willReturn(true);
        $stream->method('seek')->willReturnCallback(
            static function () use (&$calls): void {
                if (++$calls > 1) {
                    throw new RuntimeException('Unable to seek.');
                }
            },
        );

        $body = new ByteRangeStream($stream, 1, 3);

        $this->assertSame('', (string) $body);
    }

    public function testByteRangeStreamGetContentsStopsWhenUnderlyingStreamReturnsEmptyChunk(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('isSeekable')->willReturn(true);
        $stream->method('isReadable')->willReturn(true);
        $stream->method('eof')->willReturn(false);
        $stream->method('read')->willReturn('');

        $body = new ByteRangeStream($stream, 1, 3);

        $this->assertSame('', $body->getContents());
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

    private function createNonSeekableStream(string $content): StreamInterface
    {
        return new class ($content) implements StreamInterface {
            private int $position = 0;

            public function __construct(private readonly string $content)
            {
            }

            public function __toString(): string
            {
                return $this->content;
            }

            public function close(): void
            {
            }

            public function detach()
            {
                return null;
            }

            public function getSize(): ?int
            {
                return strlen($this->content);
            }

            public function tell(): int
            {
                return $this->position;
            }

            public function eof(): bool
            {
                return $this->position >= strlen($this->content);
            }

            public function isSeekable(): bool
            {
                return false;
            }

            public function seek(int $offset, int $whence = SEEK_SET): void
            {
                throw new RuntimeException('Stream is not seekable.');
            }

            public function rewind(): void
            {
                throw new RuntimeException('Stream is not seekable.');
            }

            public function isWritable(): bool
            {
                return false;
            }

            public function write(string $string): int
            {
                throw new RuntimeException('Stream is not writable.');
            }

            public function isReadable(): bool
            {
                return true;
            }

            public function read(int $length): string
            {
                $contents = substr($this->content, $this->position, $length);
                $this->position += strlen($contents);

                return $contents;
            }

            public function getContents(): string
            {
                $contents = substr($this->content, $this->position);
                $this->position = strlen($this->content);

                return $contents;
            }

            public function getMetadata(?string $key = null)
            {
                return $key === null ? [] : null;
            }
        };
    }

    private function createNonReadableSeekableStream(string $content): StreamInterface
    {
        return new class ($content) implements StreamInterface {
            private int $position = 0;

            public function __construct(private readonly string $content)
            {
            }

            public function __toString(): string
            {
                return $this->content;
            }

            public function close(): void
            {
            }

            public function detach()
            {
                return null;
            }

            public function getSize(): ?int
            {
                return strlen($this->content);
            }

            public function tell(): int
            {
                return $this->position;
            }

            public function eof(): bool
            {
                return $this->position >= strlen($this->content);
            }

            public function isSeekable(): bool
            {
                return true;
            }

            public function seek(int $offset, int $whence = SEEK_SET): void
            {
                $position = match ($whence) {
                    SEEK_SET => $offset,
                    SEEK_CUR => $this->position + $offset,
                    SEEK_END => strlen($this->content) + $offset,
                    default => throw new RuntimeException('Invalid seek mode.'),
                };

                if ($position < 0 || $position > strlen($this->content)) {
                    throw new RuntimeException('Invalid seek offset.');
                }

                $this->position = $position;
            }

            public function rewind(): void
            {
                $this->seek(0);
            }

            public function isWritable(): bool
            {
                return false;
            }

            public function write(string $string): int
            {
                throw new RuntimeException('Stream is not writable.');
            }

            public function isReadable(): bool
            {
                return false;
            }

            public function read(int $length): string
            {
                throw new RuntimeException('Stream is not readable.');
            }

            public function getContents(): string
            {
                throw new RuntimeException('Stream is not readable.');
            }

            public function getMetadata(?string $key = null)
            {
                return $key === null ? [] : null;
            }
        };
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
