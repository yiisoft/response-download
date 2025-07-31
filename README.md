<p align="center">
    <a href="https://github.com/yiisoft" target="_blank">
        <img src="https://yiisoft.github.io/docs/images/yii_logo.svg" height="100px" alt="Yii">
    </a>
    <h1 align="center">Yii PSR-7 Download Response Factory</h1>
    <br>
</p>

[![Latest Stable Version](https://poser.pugx.org/yiisoft/response-download/v/stable.png)](https://packagist.org/packages/yiisoft/response-download)
[![Total Downloads](https://poser.pugx.org/yiisoft/response-download/downloads.png)](https://packagist.org/packages/yiisoft/response-download)
[![Build status](https://github.com/yiisoft/response-download/workflows/build/badge.svg)](https://github.com/yiisoft/response-download/actions?query=workflow%3Abuild)
[![Code Coverage](https://codecov.io/gh/yiisoft/response-download/branch/master/graph/badge.svg)](https://codecov.io/gh/yiisoft/response-download)
[![Mutation testing badge](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Fyiisoft%2Fresponse-download%2Fmaster)](https://dashboard.stryker-mutator.io/reports/github.com/yiisoft/response-download/master)
[![static analysis](https://github.com/yiisoft/response-download/workflows/static%20analysis/badge.svg)](https://github.com/yiisoft/response-download/actions?query=workflow%3A%22static+analysis%22)
[![type-coverage](https://shepherd.dev/github/yiisoft/response-download/coverage.svg)](https://shepherd.dev/github/yiisoft/response-download)
[![psalm-level](https://shepherd.dev/github/yiisoft/response-download/level.svg)](https://shepherd.dev/github/yiisoft/response-download)

The package provides a factory to help forming file download PSR-7 response.

## Requirements

- PHP 8.1 or higher.

## Installation

The package could be installed with [Composer](https://getcomposer.org):

```shell
composer require yiisoft/response-download
```

## General usage

Use the factory to form a response:

```php
use Psr\Http\Message\ResponseInterface;
use Yiisoft\ResponseDownload\DownloadResponseFactory;

final class MyController
{
    public function __construct(
        private readonly DownloadResponseFactory $downloadResponseFactory,
    )
    {    
    }

    public function sendMyContentAsFile(): ResponseInterface
    {
        return $this->downloadResponseFactory->sendContentAsFile('Hello!', 'message.txt');
    }
    
    public function sendMyFile(): ResponseInterface
    {
        return $this->downloadResponseFactory->sendFile('message.txt');
    }
    
    public function xSendMyFile(): ResponseInterface
    {
        return $this->downloadResponseFactory->xSendFile('message.txt');
    }
    
    public function sendMyStreamAsFile(): ResponseInterface
    {
        $stream = new MyStream();
        
        return $this->downloadResponseFactory->sendStreamAsFile($stream, 'message.txt');
    }
}
```

Note the `xSendFile()`. It is a special method that delegates the hard work to the web server instead of serving the
file using PHP.

Optional arguments and defaults:

- If attachment name is not specified in `sendFile()` or `xSendFile()`, it will be taken from the name of the file
served.
- Each file sending method could also be provided with optional mime type and optional content disposition.
- If mime type is omitted, for `sendContentAsFile()`, `sendFile()` and `xSendFile()` it will be determined based on
the file content. For other methods or when unable to determine the mime type, "application/octet-stream" will be used.
- Content disposition is "attachment" by default. It will trigger browser's download dialog. If you want the content
of the file to be displayed inline, set it to `Yiisoft\Http\ContentDispositionHeader\ContentDispositionHeader::INLINE`.

## Documentation

- [Internals](docs/internals.md)

If you need help or have a question, the [Yii Forum](https://forum.yiiframework.com/c/yii-3-0/63) is a good place for that.
You may also check out other [Yii Community Resources](https://www.yiiframework.com/community).

## License

The Yii PSR-7 Download Response Factory is free software. It is released under the terms of the BSD License.
Please see [`LICENSE`](./LICENSE.md) for more information.

Maintained by [Yii Software](https://www.yiiframework.com/).

## Support the project

[![Open Collective](https://img.shields.io/badge/Open%20Collective-sponsor-7eadf1?logo=open%20collective&logoColor=7eadf1&labelColor=555555)](https://opencollective.com/yiisoft)

## Follow updates

[![Official website](https://img.shields.io/badge/Powered_by-Yii_Framework-green.svg?style=flat)](https://www.yiiframework.com/)
[![Twitter](https://img.shields.io/badge/twitter-follow-1DA1F2?logo=twitter&logoColor=1DA1F2&labelColor=555555?style=flat)](https://twitter.com/yiiframework)
[![Telegram](https://img.shields.io/badge/telegram-join-1DA1F2?style=flat&logo=telegram)](https://t.me/yii3en)
[![Facebook](https://img.shields.io/badge/facebook-join-1DA1F2?style=flat&logo=facebook&logoColor=ffffff)](https://www.facebook.com/groups/yiitalk)
[![Slack](https://img.shields.io/badge/slack-join-1DA1F2?style=flat&logo=slack)](https://yiiframework.com/go/slack)
