<?php

declare(strict_types=1);

/*
 * `Ddl\Table`, reduced to what the journal's schema calls: the type names it uses and a table that
 * records its columns and indexes. Declared only when absent, so a Magento checkout keeps its own.
 */

namespace Magento\Framework\DB\Ddl;

if (!class_exists(Table::class)) {
    class Table
    {
        public const TYPE_BOOLEAN = 'boolean';
        public const TYPE_BIGINT = 'bigint';
        public const TYPE_DATETIME = 'datetime';
        public const TYPE_TEXT = 'text';

        /** @var list<string> */
        public array $columns = [];

        public function __construct(public string $name = '') {}

        public function setComment(string $comment): static
        {
            return $this;
        }

        /**
         * @param array<string, mixed> $options
         */
        public function addColumn(string $name, string $type, int|string|null $size = null, array $options = [], ?string $comment = null): static
        {
            $this->columns[] = $name;

            return $this;
        }

        /**
         * @param list<string>         $fields
         * @param array<string, mixed> $options
         */
        public function addIndex(string $indexName, array $fields, array $options = []): static
        {
            return $this;
        }
    }
}
