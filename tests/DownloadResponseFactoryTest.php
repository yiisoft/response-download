<?php

declare(strict_types=1);


namespace Yiisoft\ResponseDownload\Tests;

use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\StreamFactory;
use PHPUnit\Framework\TestCase;
use Yiisoft\ResponseDownload\DownloadResponseFactory;

final class DownloadResponseFactoryTest extends TestCase
{
    public function testXSendFileDefaults(): void
    {
        $downloadResponseFactory = $this->getDownloadResponseFactory();

        $filePath = __DIR__ . '/answer.txt';
        $response = $downloadResponseFactory->xSendFile($filePath);

        $this->assertEquals($filePath, $response->getHeaderLine('X-Sendfile'));
        $this->assertEquals('attachment; filename="answer.txt"', $response->getHeaderLine('Content-Disposition'));
        $this->assertEquals('text/plain', $response->getHeaderLine('Content-Type'));
    }

    public function testSendStreamAsFileDefaults(): void
    {
        $stream = (new StreamFactory())->createStream('42');
        $downloadResponseFactory = $this->getDownloadResponseFactory();
        $response = $downloadResponseFactory->sendStreamAsFile($stream, 'answer.txt');

        $this->assertEquals('attachment; filename="answer.txt"', $response->getHeaderLine('Content-Disposition'));
        $this->assertEquals('application/octet-stream', $response->getHeaderLine('Content-Type'));
        $this->assertEquals('42', $response->getBody());
    }

    public function testSendFileDefaults(): void
    {
        $downloadResponseFactory = $this->getDownloadResponseFactory();

        $filePath = __DIR__ . '/answer.txt';
        $response = $downloadResponseFactory->sendFile($filePath);

        $this->assertEquals('attachment; filename="answer.txt"', $response->getHeaderLine('Content-Disposition'));
        $this->assertEquals('text/plain', $response->getHeaderLine('Content-Type'));
        $this->assertEquals('42', $response->getBody());
    }

    public function testSendContentAsFileDefaults(): void
    {
        $downloadResponseFactory = $this->getDownloadResponseFactory();

        $response = $downloadResponseFactory->sendContentAsFile('42', 'answer.txt');

        $this->assertEquals('attachment; filename="answer.txt"', $response->getHeaderLine('Content-Disposition'));
        $this->assertEquals('application/octet-stream', $response->getHeaderLine('Content-Type'));
        $this->assertEquals('42', $response->getBody());
    }

    private function getDownloadResponseFactory(): DownloadResponseFactory
    {
        $responseFactory = new ResponseFactory();
        $streamFactory = new StreamFactory();

        return new DownloadResponseFactory($responseFactory, $streamFactory);
    }
}
