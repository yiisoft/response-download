<?php

declare(strict_types=1);


namespace Yiisoft\ResponseDownload;

use finfo;
use InvalidArgumentException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Yiisoft\Http\ContentDispositionHeader;
use Yiisoft\Http\Header;

final class DownloadResponseFactory
{
    private const MIME_APPLICATION_OCTET_STREAM = 'application/octet-stream';

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    )
    {
    }

    /**
     * Forms a response that sends existing file to a browser as a download using x-sendfile.
     *
     * X-Sendfile is a feature allowing a web application to redirect the request for a file to the webserver
     * that in turn processes the request, this way eliminating the need to perform tasks like reading the file
     * and sending it to the user. When dealing with a lot of files (or very big files) this can lead to a great
     * increase in performance as the web application is allowed to terminate earlier while the webserver is
     * handling the request.
     *
     * The request is sent to the server through a special non-standard HTTP-header.
     * When the web server encounters the presence of such header it will discard all output and send the file
     * specified by that header using web server internals including all optimizations like caching-headers.
     *
     * As this header directive is non-standard different directives exists for different web servers applications:
     *
     * - Apache: [X-Sendfile](https://tn123.org/mod_xsendfile/)
     * - Lighttpd v1.4: [X-LIGHTTPD-send-file](https://redmine.lighttpd.net/projects/lighttpd/wiki/X-LIGHTTPD-send-file)
     * - Lighttpd v1.5: [X-Sendfile](https://redmine.lighttpd.net/projects/lighttpd/wiki/X-LIGHTTPD-send-file)
     * - Nginx: [X-Accel-Redirect](https://www.nginx.com/resources/wiki/XSendfile)
     * - Cherokee: [X-Sendfile and X-Accel-Redirect](https://cherokee-project.com/doc/other_goodies.html#x-sendfile)
     *
     * So for this method to work the X-SENDFILE option/module should be enabled by the web server and
     * a proper xHeader should be sent.
     *
     * **Note**
     *
     * This option allows to download files that are not under web folders, and even files that are otherwise protected
     * (deny from all) like `.htaccess`.
     *
     * **Side effects**
     *
     * If this option is disabled by the web server, when this method is called a download configuration dialog
     * will open but the downloaded file will have 0 bytes.
     *
     * @param string $filePath Path to file to send.
     * @param string|null $attachmentName The file name shown to the user. If null, it will be determined from
     * {@see $filePath}.
     * @param string $disposition Content disposition. Either {@see ContentDispositionHeader::ATTACHMENT}
     * or {@see ContentDispositionHeader::INLINE}. Default is {@see {@see ContentDispositionHeader::ATTACHMENT}.
     * @param string|null $mimeType The MIME type of the content. If not set, it will be guessed based on the file
     * content.
     * @param string $xHeader The name of the x-sendfile header. Defaults to "X-Sendfile".
     *
     * @return ResponseInterface Response.
     */
    public function xSendFile(
        string $filePath,
        ?string $attachmentName = null,
        string $disposition = ContentDispositionHeader::ATTACHMENT,
        ?string $mimeType = null,
        string $xHeader = 'X-Sendfile',
    ): ResponseInterface
    {
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
     * @param string $attachmentName File name shown to the user.
     * @param string $disposition Content disposition. Either {@see ContentDispositionHeader::ATTACHMENT}
     * or {@see ContentDispositionHeader::INLINE}. Default is {@see {@see ContentDispositionHeader::ATTACHMENT}.
     * @param string $mimeType The MIME type of the content. Default is {@see MIME_APPLICATION_OCTET_STREAM}.
     */
    public function sendStreamAsFile(
        StreamInterface $stream,
        string $attachmentName,
        string $disposition = ContentDispositionHeader::ATTACHMENT,
        string $mimeType = self::MIME_APPLICATION_OCTET_STREAM,
    ): ResponseInterface
    {
        $this->assertDisposition($disposition);

        return $this->responseFactory->createResponse()
            ->withHeader(Header::CONTENT_TYPE, $mimeType)
            ->withHeader(
                ContentDispositionHeader::name(),
                ContentDispositionHeader::value($disposition, $attachmentName),
            )
            ->withBody($stream);
    }

    /**
     * Forms a response that sends a file to the browser. A shortcut for {@see sendStreamAsFIle()}.
     *
     * @param string $filePath Path to file to send.
     * @param string|null $attachmentName File name shown to the user. If null, it will be determined from
     * {@see $filePath}.
     * @param string $disposition Content disposition. Either {@see ContentDispositionHeader::ATTACHMENT}
     * or {@see ContentDispositionHeader::INLINE}. Default is {@see {@see ContentDispositionHeader::ATTACHMENT}.
     * @param string|null $mimeType The MIME type of the content. If not set, it will be guessed based on the file
     * content.
     */
    public function sendFile(
        string $filePath,
        ?string $attachmentName = null,
        string $disposition = ContentDispositionHeader::ATTACHMENT,
        ?string $mimeType = null,
    ): ResponseInterface
    {
        return $this->sendStreamAsFile(
            stream: $this->streamFactory->createStreamFromFile($filePath),
            attachmentName: $attachmentName ?? basename($filePath),
            disposition: $disposition,
            mimeType: $mimeType ?? $this->getFileMimeType($filePath),
        );
    }

    /**
     * Sends the specified content as a file to the browser. A shortcut for {@see sendStreamAsFIle()}.
     *
     * @param string $content The content to be sent.
     * @param string $attachmentName The file name shown to the user.
     * @param string $disposition Content disposition. Either {@see ContentDispositionHeader::ATTACHMENT}
     * or {@see ContentDispositionHeader::INLINE}. Default is {@see {@see ContentDispositionHeader::ATTACHMENT}.
     * @param string $mimeType The MIME type of the content. Default is {@see MIME_APPLICATION_OCTET_STREAM}.
     */
    public function sendContentAsFile(
        string $content,
        string $attachmentName,
        string $disposition = ContentDispositionHeader::ATTACHMENT,
        string $mimeType = self::MIME_APPLICATION_OCTET_STREAM,
    ): ResponseInterface
    {
        return $this->sendStreamAsFile(
            stream: $this->streamFactory->createStream($content),
            attachmentName: $attachmentName,
            disposition: $disposition,
            mimeType: $mimeType,
        );
    }

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
                'Disposition value must be either %s or %s, %s given.',
                ContentDispositionHeader::class . '::ATTACHMENT',
                ContentDispositionHeader::class . '::INLINE',
                $disposition,
            ));
        }
    }
}
