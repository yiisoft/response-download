<?php

declare(strict_types=1);

namespace Yiisoft\ResponseDownload;

use finfo;
use InvalidArgumentException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Yiisoft\Http\ContentDispositionHeader;
use Yiisoft\Http\Header;

/**
 * Provides multiple methods for creating PSR-7 compatible response with downloadable content.
 */
final class DownloadResponseFactory
{
    /**
     * A MIME type value for unknown binary files.
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Basics_of_HTTP/MIME_types#applicationoctet-stream
     */
    private const MIME_APPLICATION_OCTET_STREAM = 'application/octet-stream';
    private const HEADER_ACCEPT_RANGES = 'Accept-Ranges';
    private const HEADER_CONTENT_LENGTH = 'Content-Length';
    private const HEADER_CONTENT_RANGE = 'Content-Range';
    private const HEADER_RANGE = 'Range';
    private const RANGE_UNIT_BYTES = 'bytes';

    /**
     * @param ResponseFactoryInterface $responseFactory PSR-17 compatible response factory
     * (@see https://www.php-fig.org/psr/psr-17/#22-responsefactoryinterface).
     * @param StreamFactoryInterface $streamFactory PSR-17 compatible stream factory
     * (@see https://www.php-fig.org/psr/psr-17/#24-streamfactoryinterface).
     */
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    /**
     * Forms a response that sends existing file to a browser as a download using `x-sendfile`.
     *
     * `X-Sendfile` is a feature allowing a web application to redirect the request for a file to the webserver that in
     * turn processes the request, this way eliminating the need to perform tasks like reading the file and sending it
     * to the user. When dealing with a lot of files (or very big files) this can lead to a great increase in
     * performance as the web application is allowed to terminate earlier while the webserver is handling the request.
     *
     * The request is sent to the server through a special non-standard HTTP-header. When the web server encounters the
     * presence of such header it will discard all output and send the file specified by that header using web server
     * internals including all optimizations like caching-headers.
     *
     * As this header directive is non-standard, the name varies depending on the used web server:
     *
     * - Apache: [X-Sendfile](https://tn123.org/mod_xsendfile/)
     * - Lighttpd v1.4: [X-LIGHTTPD-send-file](https://redmine.lighttpd.net/projects/lighttpd/wiki/X-LIGHTTPD-send-file)
     * - Lighttpd v1.5: [X-Sendfile](https://redmine.lighttpd.net/projects/lighttpd/wiki/X-LIGHTTPD-send-file)
     * - Nginx: X-Accel-Redirect
     * - Cherokee: [X-Sendfile and X-Accel-Redirect](https://cherokee-project.com/doc/other_goodies.html#x-sendfile)
     * - FrankenPHP: [X-Accel-Redirect](https://frankenphp.dev/docs/x-sendfile/)
     *
     * So for this method to work, the `X-SENDFILE` option/module must be enabled by the web server and a proper
     * `xHeader` must be sent.
     *
     * **Note**
     *
     * This option allows to download files that are not under web folders, and even files that are otherwise protected
     * (deny from all) like `.htaccess`.
     *
     * **Side effects**
     *
     * If this option is disabled by the web server, when this method is called, a download dialog will open, but the
     * downloaded file will have 0 bytes.
     *
     * @param string $filePath Path to a file to send.
     * @param string|null $attachmentName The file name shown to the user. If `null`, it will be determined from
     * {@see $filePath}.
     * @param string $disposition Content disposition. Either {@see ContentDispositionHeader::ATTACHMENT}
     * or {@see ContentDispositionHeader::INLINE}. Default is {@see ContentDispositionHeader::ATTACHMENT}.
     * @param string|null $mimeType The MIME type of the content. If not set, it will be guessed based on the file
     * content ({@see $filePath}).
     * @param string $xHeader The name of the `x-sendfile` header. Defaults to "X-Sendfile".
     *
     * @return ResponseInterface PSR-7 compatible response
     * (@see https://www.php-fig.org/psr/psr-7/#33-psrhttpmessageresponseinterface).
     */
    public function xSendFile(
        string $filePath,
        ?string $attachmentName = null,
        string $disposition = ContentDispositionHeader::ATTACHMENT,
        ?string $mimeType = null,
        string $xHeader = 'X-Sendfile',
    ): ResponseInterface {
        $this->assertDisposition($disposition);

        return $this->responseFactory
            ->createResponse()
            ->withHeader($xHeader, $filePath)
            ->withHeader(
                ContentDispositionHeader::name(),
                ContentDispositionHeader::value($disposition, $attachmentName ?? basename($filePath)),
            )
            ->withHeader(Header::CONTENT_TYPE, $mimeType ?? $this->getFileMimeType($filePath));
    }

    /**
     * Forms a response that sends the specified stream as a file to the browser.
     *
     * @param StreamInterface $stream PSR-7 compatible stream
     * (@see https://www.php-fig.org/psr/psr-7/#34-psrhttpmessagestreaminterface) to send.
     * @param string $attachmentName File name shown to the user.
     * @param string $disposition Content disposition. Either {@see ContentDispositionHeader::ATTACHMENT}
     * or {@see ContentDispositionHeader::INLINE}. Default is {@see ContentDispositionHeader::ATTACHMENT}.
     * @param string $mimeType The MIME type of the content. Default is {@see MIME_APPLICATION_OCTET_STREAM}.
     * @param ServerRequestInterface|null $request The request to read the `Range` header from. If `null`, range
     * requests are not handled.
     *
     * @return ResponseInterface PSR-7 compatible response
     * (@see https://www.php-fig.org/psr/psr-7/#33-psrhttpmessageresponseinterface).
     */
    public function sendStreamAsFile(
        StreamInterface $stream,
        string $attachmentName,
        string $disposition = ContentDispositionHeader::ATTACHMENT,
        string $mimeType = self::MIME_APPLICATION_OCTET_STREAM,
        ?ServerRequestInterface $request = null,
    ): ResponseInterface {
        $this->assertDisposition($disposition);

        $response = $this->responseFactory->createResponse()
            ->withHeader(Header::CONTENT_TYPE, $mimeType)
            ->withHeader(
                ContentDispositionHeader::name(),
                ContentDispositionHeader::value($disposition, $attachmentName),
            )
            ->withBody($stream);

        return $this->withRangeSupport($response, $stream, $request);
    }

    /**
     * Forms a response that sends a file to the browser. A shortcut for {@see sendStreamAsFIle()}.
     *
     * @param string $filePath Path to a file to send.
     * @param string|null $attachmentName File name shown to the user. If `null`, it will be determined from
     * {@see $filePath}.
     * @param string $disposition Content disposition. Either {@see ContentDispositionHeader::ATTACHMENT}
     * or {@see ContentDispositionHeader::INLINE}. Default is {@see ContentDispositionHeader::ATTACHMENT}.
     * @param string|null $mimeType The MIME type of the content. If not set, it will be guessed based on the file
     * content ({@see $filePath}).
     * @param ServerRequestInterface|null $request The request to read the `Range` header from. If `null`, range
     * requests are not handled.
     *
     * @return ResponseInterface PSR-7 compatible response
     * (@see https://www.php-fig.org/psr/psr-7/#33-psrhttpmessageresponseinterface).
     */
    public function sendFile(
        string $filePath,
        ?string $attachmentName = null,
        string $disposition = ContentDispositionHeader::ATTACHMENT,
        ?string $mimeType = null,
        ?ServerRequestInterface $request = null,
    ): ResponseInterface {
        return $this->sendStreamAsFile(
            stream: $this->streamFactory->createStreamFromFile($filePath),
            attachmentName: $attachmentName ?? basename($filePath),
            disposition: $disposition,
            mimeType: $mimeType ?? $this->getFileMimeType($filePath),
            request: $request,
        );
    }

    /**
     * Sends the specified content as a file to the browser. A shortcut for {@see sendStreamAsFIle()}.
     *
     * @param string $content The content to be sent.
     * @param string $attachmentName The file name shown to the user.
     * @param string $disposition Content disposition. Either {@see ContentDispositionHeader::ATTACHMENT}
     * or {@see ContentDispositionHeader::INLINE}. Default is {@see ContentDispositionHeader::ATTACHMENT}.
     * @param string|null $mimeType The MIME type of the content. If not set, it will be guessed based on the
     * {@see $content}.
     * @param ServerRequestInterface|null $request The request to read the `Range` header from. If `null`, range
     * requests are not handled.
     *
     * @return ResponseInterface PSR-7 compatible response
     * (@see https://www.php-fig.org/psr/psr-7/#33-psrhttpmessageresponseinterface).
     */
    public function sendContentAsFile(
        string $content,
        string $attachmentName,
        string $disposition = ContentDispositionHeader::ATTACHMENT,
        ?string $mimeType = null,
        ?ServerRequestInterface $request = null,
    ): ResponseInterface {
        return $this->sendStreamAsFile(
            stream: $this->streamFactory->createStream($content),
            attachmentName: $attachmentName,
            disposition: $disposition,
            mimeType: $mimeType ?? $this->getContentMimeType($content),
            request: $request,
        );
    }

    /**
     * @return array{int, int}|false|null `false` means unsupported range, `null` means unsatisfiable range.
     */
    private function parseByteRange(string $rangeHeader, int $size): array|false|null
    {
        if (!preg_match('/^' . self::RANGE_UNIT_BYTES . '=(.*)$/i', $rangeHeader, $unitMatches)) {
            return false;
        }

        $range = $unitMatches[1];

        if ($range === '' || str_contains($range, ',')) {
            return false;
        }

        if (!preg_match('/^(\d*)-(\d*)$/', $range, $matches)) {
            return false;
        }

        [, $start, $end] = $matches;

        if ($start === '' && $end === '') {
            return false;
        }

        if ($start === '') {
            $suffixLength = (int) $end;

            if ($suffixLength <= 0) {
                return null;
            }

            if ($suffixLength >= $size) {
                return $size === 0 ? null : [0, $size - 1];
            }

            return [$size - $suffixLength, $size - 1];
        }

        $startPosition = (int) $start;

        if ($startPosition >= $size) {
            return null;
        }

        $endPosition = $end === '' ? $size - 1 : (int) $end;

        if ($endPosition < $startPosition) {
            return null;
        }

        return [$startPosition, min($endPosition, $size - 1)];
    }

    private function withRangeSupport(
        ResponseInterface $response,
        StreamInterface $stream,
        ?ServerRequestInterface $request,
    ): ResponseInterface {
        if ($request === null) {
            return $response;
        }

        $size = $stream->getSize();

        if ($size === null || !$stream->isSeekable()) {
            return $response;
        }

        $response = $response
            ->withHeader(self::HEADER_ACCEPT_RANGES, self::RANGE_UNIT_BYTES)
            ->withHeader(self::HEADER_CONTENT_LENGTH, (string) $size);

        $rangeHeader = trim($request->getHeaderLine(self::HEADER_RANGE));

        if ($rangeHeader === '') {
            return $response;
        }

        $range = $this->parseByteRange($rangeHeader, $size);

        if ($range === false) {
            return $response;
        }

        if ($range === null) {
            return $response
                ->withStatus(416)
                ->withHeader(self::HEADER_CONTENT_RANGE, self::RANGE_UNIT_BYTES . ' */' . $size)
                ->withHeader(self::HEADER_CONTENT_LENGTH, '0')
                ->withBody($this->streamFactory->createStream());
        }

        [$start, $end] = $range;
        $length = $end - $start + 1;

        return $response
            ->withStatus(206)
            ->withHeader(
                self::HEADER_CONTENT_RANGE,
                sprintf('%s %d-%d/%d', self::RANGE_UNIT_BYTES, $start, $end, $size),
            )
            ->withHeader(self::HEADER_CONTENT_LENGTH, (string) $length)
            ->withBody(new ByteRangeStream($stream, $start, $end));
    }

    /**
     * Detects MIME type of file located by a given path. Fallbacks to {@see MIME_APPLICATION_OCTET_STREAM} if
     * extracting was unsuccessful.
     * @param string $filePath A path to analyzed file.
     * @return string MIME type value.
     */
    private function getFileMimeType(string $filePath): string
    {
        $info = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = @$info->file($filePath) ?: null;

        if ($mimeType === null) {
            $mimeType = self::MIME_APPLICATION_OCTET_STREAM;
        }

        return $mimeType;
    }

    /**
     * Detects MIME type from content string. Fallbacks to {@see MIME_APPLICATION_OCTET_STREAM} if
     * extracting was unsuccessful.
     * @param string $content A string containing data to analyze.
     * @return string MIME type value.
     */
    private function getContentMimeType(string $content): string
    {
        $info = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = @$info->buffer($content);

        if (!$mimeType) {
            $mimeType = self::MIME_APPLICATION_OCTET_STREAM;
        }

        return $mimeType;
    }

    /**
     * Asserts that disposition value is correct.
     *
     * @param string $disposition Disposition value.
     * @psalm-assert ContentDispositionHeader::ATTACHMENT | ContentDispositionHeader::INLINE $disposition
     * @throws InvalidArgumentException When disposition value is incorrect.
     */
    private function assertDisposition(string $disposition): void
    {
        if (
            $disposition !== ContentDispositionHeader::ATTACHMENT &&
            $disposition !== ContentDispositionHeader::INLINE
        ) {
            throw new InvalidArgumentException(sprintf(
                'Disposition value must be either "%s" or "%s", "%s" given.',
                ContentDispositionHeader::class . '::ATTACHMENT',
                ContentDispositionHeader::class . '::INLINE',
                $disposition,
            ));
        }
    }
}
