<?php

declare(strict_types=1);

use PHPTools\CommaSeparatedValues\CommaSeparatedValues;

beforeEach(function () {
    $this->file = __DIR__ . '/../fixtures/offset-limit.csv';

    $this->csv = new CommaSeparatedValues($this->file);
});

describe('CommaSeparatedValues with offset, limit combinations', function () {

    describe('skip empty row', function () {

        test('offset 0, limit 0 - returns all data rows', function () {
            $rows = \iterator_to_array($this->csv->readRow([
                CommaSeparatedValues::OPTION_SKIP_EMPTY_ROW => true,
                CommaSeparatedValues::OPTION_OFFSET => 0,
                CommaSeparatedValues::OPTION_LIMIT => 0,
            ]));

            expect(\count($rows))->toBe(10);
            expect(\array_keys($rows))->toBe([2, 3, 5, 7, 8, 10, 11, 13, 14, 15]);
        });

        test('offset 0, limit 1 - returns first data row only', function () {
            $rows = \iterator_to_array($this->csv->readRow([
                CommaSeparatedValues::OPTION_SKIP_EMPTY_ROW => true,
                CommaSeparatedValues::OPTION_OFFSET => 0,
                CommaSeparatedValues::OPTION_LIMIT => 1,
            ]));

            expect(\count($rows))->toBe(1);
            expect(\array_keys($rows))->toBe([2]);
            $firstRow = \array_values($rows)[0];
            expect($firstRow)->toBe(['id' => '1', 'name' => 'Alice', 'department' => 'Engineering', 'salary' => '75000', 'status' => 'Active']);
        });

        test('offset 1, limit 0 - skips first data row, returns rest', function () {
            $rows = \iterator_to_array($this->csv->readRow([
                CommaSeparatedValues::OPTION_SKIP_EMPTY_ROW => true,
                CommaSeparatedValues::OPTION_OFFSET => 1,
                CommaSeparatedValues::OPTION_LIMIT => 0,
            ]));

            expect(\count($rows))->toBe(9);
            expect(\array_keys($rows))->toBe([3, 5, 7, 8, 10, 11, 13, 14, 15]);
        });

        test('offset 1, limit 1 - skips first, returns second data row only', function () {
            $rows = \iterator_to_array($this->csv->readRow([
                CommaSeparatedValues::OPTION_SKIP_EMPTY_ROW => true,
                CommaSeparatedValues::OPTION_OFFSET => 1,
                CommaSeparatedValues::OPTION_LIMIT => 1,
            ]));

            expect(\count($rows))->toBe(1);
            expect(\array_keys($rows))->toBe([3]);
            $firstRow = \array_values($rows)[0];
            expect($firstRow)->toBe(['id' => '2', 'name' => 'Bob', 'department' => 'Marketing', 'salary' => '65000', 'status' => 'Active']);
        });

        test('offset 15 (exceeds total) - returns empty', function () {
            $rows = \iterator_to_array($this->csv->readRow([
                CommaSeparatedValues::OPTION_SKIP_EMPTY_ROW => true,
                CommaSeparatedValues::OPTION_OFFSET => 15,
                CommaSeparatedValues::OPTION_LIMIT => 0,
            ]));

            expect(\count($rows))->toBe(0);
        });
    });

    describe('not skip empty row', function () {

        test('offset 0, limit 0 - returns all rows including empty rows', function () {
            $rows = \iterator_to_array($this->csv->readRow([
                CommaSeparatedValues::OPTION_SKIP_EMPTY_ROW => false,
                CommaSeparatedValues::OPTION_OFFSET => 0,
                CommaSeparatedValues::OPTION_LIMIT => 0,
            ]));

            expect(\count($rows))->toBe(14); // 10 data rows + 4 empty rows
            expect(\array_keys($rows))->toBe([2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15]);
        });

        test('offset 0, limit 1 - returns first row (data row)', function () {
            $rows = \iterator_to_array($this->csv->readRow([
                CommaSeparatedValues::OPTION_SKIP_EMPTY_ROW => false,
                CommaSeparatedValues::OPTION_OFFSET => 0,
                CommaSeparatedValues::OPTION_LIMIT => 1,
            ]));

            expect(\count($rows))->toBe(1);
            expect(\array_keys($rows))->toBe([2]);
            $firstRow = \array_values($rows)[0];
            expect($firstRow)->toBe(['id' => '1', 'name' => 'Alice', 'department' => 'Engineering', 'salary' => '75000', 'status' => 'Active']);
        });

        test('offset 1, limit 0 - skips first row, returns rest', function () {
            $rows = \iterator_to_array($this->csv->readRow([
                CommaSeparatedValues::OPTION_SKIP_EMPTY_ROW => false,
                CommaSeparatedValues::OPTION_OFFSET => 1,
                CommaSeparatedValues::OPTION_LIMIT => 0,
            ]));

            expect(\count($rows))->toBe(13);
            expect(\array_keys($rows))->toBe([3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15]);
        });

        test('offset 1, limit 1 - skips first, returns second row (Bob)', function () {
            $rows = \iterator_to_array($this->csv->readRow([
                CommaSeparatedValues::OPTION_SKIP_EMPTY_ROW => false,
                CommaSeparatedValues::OPTION_OFFSET => 1,
                CommaSeparatedValues::OPTION_LIMIT => 1,
            ]));

            expect(\count($rows))->toBe(1);
            expect(\array_keys($rows))->toBe([3]);
            $firstRow = \array_values($rows)[0];
            expect($firstRow)->toBe(['id' => '2', 'name' => 'Bob', 'department' => 'Marketing', 'salary' => '65000', 'status' => 'Active']);
        });

        test('offset 20 (exceeds total) - returns empty', function () {
            $rows = \iterator_to_array($this->csv->readRow([
                CommaSeparatedValues::OPTION_SKIP_EMPTY_ROW => false,
                CommaSeparatedValues::OPTION_OFFSET => 20,
                CommaSeparatedValues::OPTION_LIMIT => 1,
            ]));

            expect(\count($rows))->toBe(0);
        });
    });
});
