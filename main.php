#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

\Phambda\Http\Runtime::execute(
    new \Phambda\Example\Http\ExampleHandler(
        new \Phambda\Logger\NanoLogger()
    ),
);
