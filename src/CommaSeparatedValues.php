<?php

namespace PHPTools\CommaSeparatedValues;

use PHPTools\CommaSeparatedValues\Contracts\CommaSeparatedValuesInterface;

class CommaSeparatedValues extends \SplFileObject implements CommaSeparatedValuesInterface
{
    protected array $options = [];

    protected bool $withBom;

    protected array $encoding = [];

    protected array $headers;

    protected self $converted;

    public function __construct($filename, $mode = 'r', $useIncludePath = false, $context = null)
    {
        parent::__construct($filename, $mode, $useIncludePath, $context);

        // fix pest test:
        // the $escape parameter must be provided, as its default value will change,
        // either explicitly or via SplFileObject::setCsvControl()
        $this->setCsvControl(',', '"', '\\');

        $this->setOptions();
    }

    public function __destruct()
    {
        if (isset($this->converted)) {
            @\unlink($this->converted->getRealPath());
        }
    }

    public function setOptions(array $options = []): self
    {
        $options = \array_merge($this->options, $options);

        $this->options[static::OPTION_ENCODING_LIST] = \array_merge(
            [static::DEFAULT_ENCODING],
            (array) ($options[static::OPTION_ENCODING_LIST] ?? \mb_list_encodings())
        );
        // Ensure at least 1 row is checked
        $this->options[static::OPTION_DETECT_ENCODING_ROWS] = \max(
            \intval($options[static::OPTION_DETECT_ENCODING_ROWS] ?? 10),
            1
        );
        $this->options[static::OPTION_WITH_HEADER] = \boolval($options[static::OPTION_WITH_HEADER] ?? true);
        $this->options[static::OPTION_TRIM] = \boolval($options[static::OPTION_TRIM] ?? true);
        $this->options[static::OPTION_EMPTY_TO_NULL] = \boolval($options[static::OPTION_EMPTY_TO_NULL] ?? true);
        $this->options[static::OPTION_SKIP_EMPTY_ROW] = \boolval($options[static::OPTION_SKIP_EMPTY_ROW] ?? true);

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

        $this->rewind();

        $firstRow = $this->fgets();
        $withoutBomFirstRow = $this->removeBom($firstRow);
        $withBom = \strlen($withoutBomFirstRow) !== \strlen($firstRow);

        return $this->withBom = $withBom;
    }

    public function getEncoding(): string
    {
        // Ensure at least 1 row is checked
        $detectEncodingRows = $this->options[static::OPTION_DETECT_ENCODING_ROWS] ?? 10;

        if (isset($this->encoding[$detectEncodingRows])) {
            return $this->encoding[$detectEncodingRows];
        } else {
            $this->encoding = [];
        }

        $this->rewind();

        $encodingList = $this->options[static::OPTION_ENCODING_LIST];

        $detectedRows = 0;
        $encodings = [];

        $string = '';

        while (! $this->eof()) {
            if (++$detectedRows > $detectEncodingRows) {
                break;
            }

            $string .= $this->fgets();

            $detected = \mb_detect_encoding($string, $encodingList, true);

            if ($detected === false) {
                continue;
            }

            $encodings[$detected] = $detected;
        }

        $count = \count($encodings);

        if ($count === 0) {
            throw new \RuntimeException('Unable to detect file encoding');
        }

        if ($count > (isset($encodings[static::DEFAULT_ENCODING]) ? 2 : 1)) {
            throw new \RuntimeException('Multiple encodings detected: ' . \implode(', ', $encodings));
        }

        unset($encodings[static::DEFAULT_ENCODING]);

        return $this->encoding[$detectEncodingRows] = \current($encodings) ?: static::DEFAULT_ENCODING;
    }

    public function getHeaders(): array
    {
        if (isset($this->headers)) {
            return $this->headers;
        }

        $backupOptions = $this->getOptions();

        $this->setOptions(
            [
                static::OPTION_WITH_HEADER => false,
                static::OPTION_EMPTY_TO_NULL => false,
                static::OPTION_SKIP_EMPTY_ROW => false,
            ]
        );

        foreach ($this->readRow() as $row) {
            $this->headers = $this->makeHeadersUnique($row);

            break;
        }

        $this->setOptions($backupOptions);

        return $this->headers ??= [];
    }

    public function readRow(array $options = []): \Generator
    {
        $this->setOptions($options);

        $converted = $this->encodingConverted();

        $withHeader = $this->options[static::OPTION_WITH_HEADER];
        $trim = $this->options[static::OPTION_TRIM];
        $emptyToNull = $this->options[static::OPTION_EMPTY_TO_NULL];
        $skipEmptyRow = $this->options[static::OPTION_SKIP_EMPTY_ROW];
        $itemConvertor = $this->itemConvertor($trim, $emptyToNull);

        $headers = [];
        $rowNumber = 0;

        $converted->rewind();

        while (! $converted->eof()) {
            $rowNumber++; // csv file row number is begin with 1

            $row = \array_map($itemConvertor, $converted->fgetcsv());

            if ($skipEmptyRow && empty(\array_filter($row))) {
                continue;
            }

            if (! $withHeader) {
                yield $rowNumber => $row;

                continue;
            }

            if ($rowNumber === 1) {
                $headers = $this->makeHeadersUnique($row);

                continue;
            }

            $mappedRow = [];

            foreach ($row as $index => $value) {
                $mappedRow[$headers[$index] ?? $index] = $value;
            }

            yield $rowNumber => $mappedRow;
        }
    }

    public function readRows(int $size, array $options = []): \Generator
    {
        $chunkIndex = 0;
        $chunk = [];

        foreach ($this->readRow($options) as $rowNumber => $row) {
            $chunk[$rowNumber] = $row;

            if (\count($chunk) === $size) {
                yield $chunkIndex++ => $chunk;

                $chunk = [];
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

    protected function encodingConverted(): self
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

        $converted = new static(\tempnam(\sys_get_temp_dir(), 'csv-'), 'w+');

        $isFirstRow = true;

        $this->rewind();

        while (! $this->eof()) {
            $row = $this->fgets();

            if ($isFirstRow && $withBom) {
                $row = $this->removeBom($row);

                $isFirstRow = false;
            }

            $converted->fwrite(
                $shouldConvertEncoding
                    ? \mb_convert_encoding($row, static::DEFAULT_ENCODING, $encoding)
                    : $row
            );
        }

        return $this->converted = $converted;
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

    protected function makeHeadersUnique(array $headers): array
    {
        $repeated = [];

        foreach ($headers as &$header) {
            $repeated[$header] ??= 0;

            if (++$repeated[$header] > 1) {
                $header = \sprintf('%s (%d)', $header, $repeated[$header]);
            }
        }

        return $headers;
    }
}
