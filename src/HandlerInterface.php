<?php

namespace Phambda;

interface HandlerInterface
{
    public function handle(array $event, array $context): string;
}