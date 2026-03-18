# php-tools/comma-separated-values

CSV 处理工具类库，继承自 `SplFileObject`，支持 BOM 检测、编码自动识别与转换、header 映射、分块读取等功能。

## 主要特性

- 自动检测文件编码（通过 `mb_detect_encoding`）
- 支持带 BOM 的 UTF-8 文件，读取时自动剥离
- 非 UTF-8 文件（如 Shift_JIS）自动转换为 UTF-8 后处理
- 通过 Generator 流式读取，适合大文件
- 支持多个 Generator 并发迭代，互不干扰
- 可配置 header、trim、空值、空行跳过、offset/limit 等选项
- `setOptions()` 增量合并，只更新传入的选项

## 要求

- PHP >= 8.1
- `ext-mbstring`

## 安装

```bash
composer require php-tools/comma-separated-values
```

## 默认选项

```php
use PHPTools\CommaSeparatedValues\CommaSeparatedValues;

CommaSeparatedValues::DEFAULT_OPTIONS = [
    'encoding_list'        => ['UTF-8'],  // 优先检测的编码列表（内部始终合并全量编码，此处指定的编码排在最前面）
    'detect_encoding_rows' => 10,         // 用于编码检测的行数
    'with_header'          => true,       // 第一行作为 header，数据行映射为关联数组
    'trim'                 => true,       // 对每个字段执行 trim()
    'empty_to_null'        => true,       // 空字符串转为 null
    'skip_empty_row'       => true,       // 跳过全空数据行
    'offset'               => 0,          // 跳过前 N 个有效数据行
    'limit'                => 0,          // 最多返回 N 行（0 = 不限制）
];
```

## 选项说明

| 选项常量 | 类型 | 默认值 | 说明 |
|---|---|---|---|
| `OPTION_ENCODING_LIST` | `array` | `['UTF-8']` | 编码检测候选列表。内部始终合并 `mb_list_encodings()` 全量列表，此处指定的编码会被排在列表最前面，`mb_detect_encoding` 将优先尝试这些编码 |
| `OPTION_DETECT_ENCODING_ROWS` | `int` | `10` | 读取前 N 行用于编码检测，必须 > 0 |
| `OPTION_WITH_HEADER` | `bool` | `true` | 第一行作为 header，数据行返回关联数组；`false` 时返回索引数组 |
| `OPTION_TRIM` | `bool` | `true` | 对每个字段执行 `trim()` |
| `OPTION_EMPTY_TO_NULL` | `bool` | `true` | trim 后空字符串转为 `null` |
| `OPTION_SKIP_EMPTY_ROW` | `bool` | `true` | 跳过所有字段均为空/null 的数据行 |
| `OPTION_OFFSET` | `int` | `0` | 跳过前 N 个有效数据行，必须 >= 0 |
| `OPTION_LIMIT` | `int` | `0` | 最多返回 N 行，`0` 表示不限制，必须 >= 0 |

## API

### `__construct`

```php
new CommaSeparatedValues(
    string $filename,
    string $mode = 'r',
    bool $useIncludePath = false,
    $context = null,
    array $options = []
)
```

### `setOptions(array $options): static`

增量更新选项，只更新传入的 key，未传入的 key 保留当前值。
当 `encoding_list` 或 `detect_encoding_rows` 变更时，自动清除编码缓存和 header 缓存。
当 `trim` 变更时，自动清除 header 缓存。

### `getOptions(): array`

返回当前完整选项数组。

### `withBom(): bool`

检测文件是否以 UTF-8 BOM 开头，结果缓存。不改变当前游标位置。

### `getEncoding(): string`

自动检测文件编码，结果按选项缓存。不改变当前游标位置。
若检测到多种非 UTF-8 编码则抛出 `RuntimeException`。

### `getHeaders(): array`

读取第一行并返回处理后的 header 数组，结果缓存。
重复的 header 会加上序号，如 `name (2)`；空 header 使用列索引替代。

### `readRow(array $options = []): Generator`

流式逐行读取，`yield` 的 key 为 CSV 文件中的实际行号（从 1 开始）。
支持传入临时 options 覆盖当前实例选项（仅对本次调用生效）。
多次调用或并发迭代互不干扰，均从文件头开始读取。

### `readRows(int $size, array $options = []): Generator`

分块读取，每次 `yield` 一个最多 `$size` 行的数组，key 为块索引（从 0 开始）。

## 使用示例

### 基本读取

```php
use PHPTools\CommaSeparatedValues\CommaSeparatedValues;

$csv = new CommaSeparatedValues('data.csv');

foreach ($csv->readRow() as $rowNumber => $row) {
    // $row 为关联数组：['id' => '1', 'name' => 'Alice', ...]
}
```

### 构造时传入选项

```php
$csv = new CommaSeparatedValues('data.csv', options: [
    CommaSeparatedValues::OPTION_WITH_HEADER   => false,
    CommaSeparatedValues::OPTION_TRIM          => false,
    CommaSeparatedValues::OPTION_EMPTY_TO_NULL => false,
]);
```

### 动态更新选项

```php
$csv = new CommaSeparatedValues('data.csv');
$csv->setOptions([CommaSeparatedValues::OPTION_SKIP_EMPTY_ROW => false]);
```

### ShiftJIS 文件

`encoding_list` 中指定的编码会在检测时优先匹配：

```php
$csv = new CommaSeparatedValues('shiftjis.csv', options: [
    CommaSeparatedValues::OPTION_ENCODING_LIST => ['SJIS-win'],
]);

echo $csv->getEncoding(); // 'SJIS-win'
```

文件内容会自动转换为 UTF-8 后读取，无需手动处理编码。

### Offset / Limit

```php
// 跳过前 10 行，最多读取 5 行
foreach ($csv->readRow([
    CommaSeparatedValues::OPTION_OFFSET => 10,
    CommaSeparatedValues::OPTION_LIMIT  => 5,
]) as $row) {
    // ...
}
```

### 分块读取大文件

```php
foreach ($csv->readRows(100) as $chunkIndex => $chunk) {
    // $chunk 为最多 100 行的关联数组
}
```

### 读取 header

```php
$headers = $csv->getHeaders();
// ['id', 'name', 'city', 'score', 'remark']
```

### 检测文件信息

```php
echo $csv->withBom()      ? 'has BOM' : 'no BOM';
echo $csv->getEncoding(); // 'UTF-8' / 'SJIS-win' / ...
```

### 并发迭代

多个 Generator 可以同时迭代同一个实例：

```php
$gen1 = $csv->readRow();
$gen2 = $csv->readRow();

$gen1->current(); // 读取第一个 generator 的第 1 行
$gen2->current(); // 读取第二个 generator 的第 1 行，不受 gen1 影响
```

## 异常

| 异常类型 | 触发条件 |
|---|---|
| `RuntimeException` | 无法检测到文件编码 |
| `RuntimeException` | 检测到多种非 UTF-8 编码 |
| `RuntimeException` | `json_encode` 生成缓存 key 失败 |
| `InvalidArgumentException` | 选项类型不匹配 |
| `InvalidArgumentException` | `detect_encoding_rows` <= 0 |
| `InvalidArgumentException` | `offset` 或 `limit` < 0 |
| `InvalidArgumentException` | `readRows` 的 `$size` <= 0 |

## License

MIT
