# php-tools/comma-separated-values

CSV 处理工具类库，支持 BOM、编码检测、分块读取等功能。

## 主要特性
- 自动检测文件编码
- 支持带 BOM 的 UTF-8 文件
- 支持分块读取大文件
- 可配置 header、空值处理等

## 安装

```bash
composer require php-tools/comma-separated-values
```

## 使用示例

```php
use PHPTools\CommaSeparatedValues\CommaSeparatedValues;

$csv = new CommaSeparatedValues('path/to/file.csv');

foreach ($csv->readRow() as $rowNumber => $row) {
    // 处理每一行
}
```

## License
MIT
