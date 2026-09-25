<?php

declare(strict_types=1);

/*
 * The two Magento types the listing data provider stands on, reduced to what it calls. Magento is
 * not installed in the root suite; declared only when absent, so a Magento checkout keeps its own.
 */

namespace Magento\Ui\DataProvider;

if (!class_exists(AbstractDataProvider::class)) {
    abstract class AbstractDataProvider
    {
        /**
         * Kept as Magento keeps them: protected properties a subclass may read.
         *
         * @param array<string, mixed> $meta
         * @param array<string, mixed> $data
         */
        public function __construct(
            protected string $name,
            protected string $primaryFieldName,
            protected string $requestFieldName,
            protected array $meta = [],
            protected array $data = [],
        ) {}
    }
}

namespace Magento\Framework\Api;

if (!class_exists(Filter::class)) {
    class Filter
    {
        public function getField(): string
        {
            return '';
        }

        public function getValue(): mixed
        {
            return null;
        }
    }
}
