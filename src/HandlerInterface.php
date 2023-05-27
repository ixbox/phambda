<?php

namespace Phambda;

interface HandlerInterface
{
    public function handle(Event $event, Context $context): string;
}