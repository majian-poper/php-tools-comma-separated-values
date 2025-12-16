<?php

namespace PHPTools\CommaSeparatedValues\Contracts;

interface CommaSeparatedValuesInterface
{
    public const DEFAULT_ENCODING = 'UTF-8';

    public const OPTION_ENCODING_LIST = 'encoding_list';

    public const OPTION_DETECT_ENCODING_ROWS = 'detect_encoding_rows';

    public const OPTION_WITH_HEADER = 'with_header';

    public const OPTION_TRIM = 'trim';

    public const OPTION_EMPTY_TO_NULL = 'empty_to_null';

    public const OPTION_SKIP_EMPTY_ROW = 'skip_empty_row';

    public function getBasename(string $suffix = ''): string;

    public function withBom(): bool;

    public function getEncoding(): string;

    public function getHeaders(): array;

    public function readRow(array $options = []): \Generator;

    public function readRows(int $size, array $options = []): \Generator;
}
