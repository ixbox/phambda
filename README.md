# PHP Runtime for AWS Lambda

## Feature

This package provides simple way to implement function for PHP on AWS Lambda.

## Installation

```console
composer require ixbox/phambda
````

## Dependencies
- PHP 8.1 or later

## Sample Implementation

```php
#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use Nyholm\Psr7\Response;
use Phambda\Http\Runtime;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

$handler = new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface {
        return new Response(body: "Hello World!");
    }
};
$runtime = new Runtime($handler);
$runtime->run();
```

