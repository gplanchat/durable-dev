<?php

declare(strict_types=1);

/*
 * The Magento types an admin block stands on, reduced to what `ProcessDetail` and `ProcessHistory`
 * call. Magento is not installed in the root suite; declared only when absent, so a Magento
 * checkout keeps its own.
 */

namespace Magento\Framework\App;

if (!interface_exists(RequestInterface::class)) {
    interface RequestInterface
    {
        public function getParam(string $name): mixed;
    }
}

namespace Magento\Backend\Block\Template;

use Magento\Framework\App\RequestInterface;

if (!class_exists(Context::class)) {
    class Context
    {
        public function __construct(public readonly RequestInterface $request) {}
    }
}

namespace Magento\Backend\Block;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\RequestInterface;

if (!class_exists(Template::class)) {
    class Template
    {
        /**
         * @param array<string, mixed> $data
         */
        public function __construct(private readonly Context $context, protected array $data = []) {}

        public function getRequest(): RequestInterface
        {
            return $this->context->request;
        }
    }
}
