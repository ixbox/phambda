# aws-lambda

```php
#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Psr7\Response;
use Phambda\Http\Psr\Runtime;
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
