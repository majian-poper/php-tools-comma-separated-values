<?php

namespace PHPTools\CommaSeparatedValues;

use PHPTools\CommaSeparatedValues\Contracts\CommaSeparatedValuesInterface;

class CommaSeparatedValues extends \SplFileObject implements CommaSeparatedValuesInterface
{
    public const DEFAULT_OPTIONS = [
        self::OPTION_ENCODING_LIST => [self::DEFAULT_ENCODING],
        self::OPTION_DETECT_ENCODING_ROWS => 10,
        self::OPTION_WITH_HEADER => true,
        self::OPTION_TRIM => true,
        self::OPTION_EMPTY_TO_NULL => true,
        self::OPTION_SKIP_EMPTY_ROW => true,
        self::OPTION_OFFSET => 0,
        self::OPTION_LIMIT => 0,
    ];

    protected array $options = self::DEFAULT_OPTIONS;

    protected bool $withBom;

    protected array $encoding = [];

    protected array $headers;

    protected self $converted;

    private array $recordedPositions = [];

    private int $readRowBatch = 1;

    public function __construct($filename, $mode = 'r', $useIncludePath = false, $context = null, array $options = [])
    {
        parent::__construct($filename, $mode, $useIncludePath, $context);

        // fix pest test:
        // the $escape parameter must be provided, as its default value will change,
        // either explicitly or via SplFileObject::setCsvControl()
        $this->setCsvControl(',', '"', '\\');

        $this->setOptions($options);
    }

    public function __destruct()
    {
        $this->clearEncodingConverted();
    }

    public function setOptions(array $options = []): static
    {
        $formated = $this->formatOptions($options);

        if (
            $formated[static::OPTION_ENCODING_LIST] !== $this->options[static::OPTION_ENCODING_LIST]
            || $formated[static::OPTION_DETECT_ENCODING_ROWS] !== $this->options[static::OPTION_DETECT_ENCODING_ROWS]
        ) {
            // Clear cached encodings if related options have changed
            $this->clearEncodingConverted();

            unset($this->headers);
        }

        if ($formated[static::OPTION_TRIM] !== $this->options[static::OPTION_TRIM]) {
            unset($this->headers);
        }

        $this->options = $formated;

        return $this;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function withBom(): bool
    {
        if (isset($this->withBom)) {
            return $this->withBom;
        }

        $this->recordPosition(__METHOD__);
        $this->rewind();

        try {
            $firstLine = $this->fgets();

            if ($firstLine === false) {
                // try finally for empty file, as empty file should be considered as without BOM
                return $this->withBom = false;
            }

            $withoutBomFirstLine = $this->removeBom($firstLine);
            $withBom = \strlen($withoutBomFirstLine) !== \strlen($firstLine);
        } finally {
            $this->restorePosition(__METHOD__, clear: true);
        }

        return $this->withBom = $withBom;
    }

    public function getEncoding(): string
    {
        $detectEncodingRows = $this->options[static::OPTION_DETECT_ENCODING_ROWS];
        $encodingList = $this->options[static::OPTION_ENCODING_LIST];

        $cacheKeyJson = \json_encode(
            [
                static::OPTION_DETECT_ENCODING_ROWS => $detectEncodingRows,
                static::OPTION_ENCODING_LIST => $encodingList,
            ]
        );

        if ($cacheKeyJson === false) {
            throw new \RuntimeException('Failed to generate cache key for encoding detection.');
        }

        $cacheKey = \md5($cacheKeyJson);

        if (isset($this->encoding[$cacheKey])) {
            return $this->encoding[$cacheKey];
        }

        $detectedRows = 0;
        $string = '';
        $detectedEncodings = [];

        $this->recordPosition(__METHOD__);
        $this->rewind();

        while (! $this->eof() && $detectedRows < $detectEncodingRows) {
            $line = $this->fgets();

            if ($line === false) {
                break;
            }

            $detectedRows++;
            $string .= $line;

            $detected = \mb_detect_encoding($string, $encodingList, true);

            if ($detected === false) {
                continue;
            }

            $detectedEncodings[$detected] = $detected;
        }

        $this->restorePosition(__METHOD__, clear: true);

        $count = \count($detectedEncodings);

        if ($count === 0) {
            throw new \RuntimeException('Unable to detect file encoding for: '.$this->getRealPath());
        }

        if ($count > (\in_array(static::DEFAULT_ENCODING, $detectedEncodings, true) ? 2 : 1)) {
            throw new \RuntimeException(
                \sprintf(
                    'Multiple encodings detected for file: %s. Detected encodings: %s',
                    $this->getRealPath(),
                    \implode(', ', $detectedEncodings)
                )
            );
        }

        unset($detectedEncodings[static::DEFAULT_ENCODING]);

        return $this->encoding[$cacheKey] = \array_key_first($detectedEncodings) ?: static::DEFAULT_ENCODING;
    }

    public function getHeaders(): array
    {
        if (isset($this->headers)) {
            return $this->headers;
        }

        $options = [
            static::OPTION_WITH_HEADER => false,
            static::OPTION_EMPTY_TO_NULL => false,
            static::OPTION_SKIP_EMPTY_ROW => false,
            static::OPTION_OFFSET => 0,
            static::OPTION_LIMIT => 0,
        ];

        foreach ($this->readRow($options) as $row) {
            $this->headers = $this->uniqueHeaders($row);

            break;
        }

        return $this->headers ??= [];
    }

    public function readRow(array $options = []): \Generator
    {
        $formated = $this->formatOptions($options);

        $withHeader = $formated[static::OPTION_WITH_HEADER];
        $trim = $formated[static::OPTION_TRIM];
        $emptyToNull = $formated[static::OPTION_EMPTY_TO_NULL];
        $skipEmptyRow = $formated[static::OPTION_SKIP_EMPTY_ROW];
        $offset = $formated[static::OPTION_OFFSET];
        $limit = $formated[static::OPTION_LIMIT];

        $itemConvertor = $this->itemConvertor($trim, $emptyToNull);

        $headers = [];
        $rowNumber = $validRowCount = $outputRowCount = 0;

        $converted = $this->encodingConverted();

        $readRowBatch = $this->readRowBatch++;
        $methodTag = __METHOD__.'#'.$readRowBatch;
        $generatorTag = __METHOD__.'#'.$readRowBatch.':generator';

        $converted->recordPosition($methodTag);
        $converted->rewind();

        $eof = false;

        try {
            while (! $eof) {
                $converted->restorePosition($generatorTag);

                $row = $converted->fgetcsv();
                $eof = $converted->eof();

                $converted->recordPosition($generatorTag);
                $converted->restorePosition($methodTag);

                if (! \is_array($row)) {
                    break;
                }

                $rowNumber++; // csv file row number is begin with 1

                $row = \array_map($itemConvertor, $row);

                if ($rowNumber === 1 && $withHeader) {
                    $headers = $this->uniqueHeaders($row);

                    continue;
                }

                if ($skipEmptyRow && empty(\array_filter($row))) {
                    continue;
                }

                // if the valid row count does not reach the offset, skip
                if (++$validRowCount <= $offset) {
                    continue;
                }

                yield $rowNumber => $withHeader
                    ? $this->mapRowWithHeaders($row, $headers, $emptyToNull)
                    : $row;

                $converted->recordPosition($methodTag);

                if ($limit > 0 && ++$outputRowCount >= $limit) {
                    break;
                }
            }
        } finally {
            // Clear recorded positions to prevent potential memory leak if the generator is not fully consumed
            $converted->clearPosition($generatorTag);
            $converted->restorePosition($methodTag, clear: true);
        }
    }

    public function readRows(int $size, array $options = []): \Generator
    {
        if ($size <= 0) {
            throw new \InvalidArgumentException('Size must be a positive integer. Given: '.$size);
        }

        $chunkIndex = 0;
        $chunk = [];
        $count = 0;

        foreach ($this->readRow($options) as $rowNumber => $row) {
            $chunk[$rowNumber] = $row;
            $count++;

            if ($count === $size) {
                yield $chunkIndex++ => $chunk;

                $chunk = [];
                $count = 0;
            }
        }

        if (! empty($chunk)) {
            yield $chunkIndex => $chunk;
        }
    }

    protected function removeBom(string $string): string
    {
        if (\strlen($string) < 3) {
            return $string;
        }

        if (\ord($string[0]) !== 0xEF || \ord($string[1]) !== 0xBB || \ord($string[2]) !== 0xBF) {
            return $string;
        }

        return \substr($string, 3);
    }

    protected function encodingConverted(): static
    {
        if (isset($this->converted)) {
            return $this->converted;
        }

        $withBom = $this->withBom();
        $encoding = $this->getEncoding();

        $shouldConvertEncoding = $encoding !== static::DEFAULT_ENCODING;

        if (! $withBom && ! $shouldConvertEncoding) {
            return $this;
        }

        $this->recordPosition(__METHOD__);
        $this->rewind();

        $converted = new static(\tempnam(\sys_get_temp_dir(), 'csv-'), 'w+');

        $count = 0;

        while (! $this->eof()) {
            $line = $this->fgets();

            if ($line === false) {
                break;
            }

            // Only remove BOM from the first line, as BOM should only appear at file start
            if (++$count === 1 && $withBom) {
                $line = $this->removeBom($line);
            }

            $converted->fwrite(
                $shouldConvertEncoding
                    ? \mb_convert_encoding($line, static::DEFAULT_ENCODING, $encoding)
                    : $line
            );
        }

        $this->restorePosition(__METHOD__, clear: true);

        $converted->rewind();

        return $this->converted = $converted;
    }

    protected function clearEncodingConverted(): void
    {
        $this->encoding = [];

        if (isset($this->converted)) {
            @\unlink($this->converted->getRealPath());

            unset($this->converted);
        }
    }

    protected function itemConvertor(bool $trim, bool $emptyToNull): \Closure
    {
        return static function (?string $item) use ($trim, $emptyToNull): ?string {
            if (\is_null($item)) {
                return null;
            }

            if ($trim) {
                $item = \trim($item);
            }

            if ($emptyToNull && $item === '') {
                return null;
            }

            return $item;
        };
    }

    protected function uniqueHeaders(array $headers): array
    {
        $repeated = [];

        foreach ($headers as $index => $header) {
            if (\is_null($header) || $header === '') {
                $headers[$index] = $index;

                continue;
            }

            $repeated[$header] ??= 0;

            if (++$repeated[$header] > 1) {
                $headers[$index] = \sprintf('%s (%d)', $header, $repeated[$header]);
            }
        }

        return $headers;
    }

    protected function mapRowWithHeaders(array $row, array $headers, bool $emptyToNull = false): array
    {
        if (empty($headers)) {
            return $row;
        }

        $mappedRow = [];

        $indexes = \range(0, \max(\count($row), \count($headers)) - 1);

        foreach ($indexes as $index) {
            // if header is missing for the column, use the column index as header name
            $headerName = $headers[$index] ?? $index;

            $mappedRow[$headerName] = $row[$index] ?? ($emptyToNull ? null : '');
        }

        return $mappedRow;
    }

    protected function formatOptions(array $options): array
    {
        $formated = [];

        foreach (static::DEFAULT_OPTIONS as $key => $default) {
            if (! \array_key_exists($key, $options)) {
                $formated[$key] = $this->options[$key] ?? $default;

                continue;
            }

            $option = $options[$key];

            $optionType = \get_debug_type($option);
            $defaultType = \get_debug_type($default);

            if ($optionType !== $defaultType) {
                throw new \InvalidArgumentException(
                    \sprintf(
                        'Option "%s" must be of type %s. Given: %s',
                        $key,
                        $defaultType,
                        $optionType
                    )
                );
            }

            $formated[$key] = $option;
        }

        $encodingList = \mb_list_encodings();

        $formated[static::OPTION_ENCODING_LIST] = \array_unique(
            \array_filter(
                \array_merge([static::DEFAULT_ENCODING], $formated[static::OPTION_ENCODING_LIST], $encodingList),
                static function (mixed $encoding) use ($encodingList): bool {
                    return \is_string($encoding) && \in_array($encoding, $encodingList, true);
                }
            )
        );

        $detectEncodingRows = $formated[static::OPTION_DETECT_ENCODING_ROWS];

        if ($detectEncodingRows <= 0) {
            throw new \InvalidArgumentException(
                \sprintf(
                    'Option "%s" must be a positive integer. Given: %s',
                    static::OPTION_DETECT_ENCODING_ROWS,
                    $detectEncodingRows
                )
            );
        }

        foreach ([static::OPTION_OFFSET, static::OPTION_LIMIT] as $key) {
            if ($formated[$key] < 0) {
                throw new \InvalidArgumentException(
                    \sprintf(
                        'Option "%s" must be a non-negative integer. Given: %s',
                        $key,
                        $formated[$key]
                    )
                );
            }
        }

        return $formated;
    }

    private function recordPosition(string $tag): void
    {
        $this->recordedPositions[$tag] = $this->ftell();
    }

    private function restorePosition(string $tag, bool $clear = false): void
    {
        if (! isset($this->recordedPositions[$tag])) {
            return;
        }

        $this->fseek($this->recordedPositions[$tag]);

        if ($clear) {
            $this->clearPosition($tag);
        }
    }

    private function clearPosition(string $tag): void
    {
        unset($this->recordedPositions[$tag]);
    }
}
