<?php

namespace App\Imports;

use App\Exceptions\BusinessRuleException;
use DateTimeInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Throwable;

/**
 * Reads the first sheet of an .xlsx or .csv file into a header row and plain string cells.
 */
class SpreadsheetReader
{
    public const MAX_ROWS = 50000;

    /**
     * @return array{headers: array<int, string>, rows: array<int, array{line: int, cells: array<int, string>}>}
     */
    public function read(string $path, string $originalName): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $reader = match ($extension) {
            'xlsx' => new XlsxReader,
            'csv', 'txt' => new CsvReader($this->csvOptions($path)),
            default => throw new BusinessRuleException('نوع الملف غير مدعوم. ارفع ملف Excel بصيغة ‎.xlsx‎ أو ‎.csv‎. (الملفات القديمة ‎.xls‎: افتحها في Excel واحفظها بصيغة ‎.xlsx‎.)'),
        };

        try {
            $reader->open($path);
            $headers = null;
            $rows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                $line = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    $cells = $this->cells($row);
                    if (implode('', $cells) === '') {
                        continue;
                    }
                    if ($headers === null) {
                        $headers = array_map(fn ($h) => trim(preg_replace('/^\x{FEFF}/u', '', $h)), $cells);

                        continue;
                    }
                    $rows[] = ['line' => $line, 'cells' => $cells];
                    if (count($rows) > self::MAX_ROWS) {
                        throw new BusinessRuleException('الملف أكبر من '.number_format(self::MAX_ROWS).' صف. قسّمه إلى أكثر من ملف.');
                    }
                }
                break; // first sheet only
            }
        } catch (BusinessRuleException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new BusinessRuleException('تعذّرت قراءة الملف. تأكد أنه ملف Excel سليم.', previous: $e);
        } finally {
            $reader->close();
        }

        if ($headers === null) {
            throw new BusinessRuleException('الملف فارغ.');
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Writes a ready-to-fill template: a data sheet with the given headers and examples, and an instructions sheet.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $examples
     * @param  array<int, string>  $instructions
     */
    public function writeTemplate(string $path, array $headers, array $examples, array $instructions): void
    {
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $rtl = (new SheetView)->setRightToLeft(true);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('المشتركون');
        $sheet->setSheetView($rtl);
        $sheet->setColumnWidthForRange(22, 1, count($headers));
        $writer->addRow(Row::fromValues($headers));
        foreach ($examples as $example) {
            $writer->addRow(Row::fromValues($example));
        }

        $writer->addNewSheetAndMakeItCurrent();
        $help = $writer->getCurrentSheet();
        $help->setName('التعليمات');
        $help->setSheetView((new SheetView)->setRightToLeft(true));
        $help->setColumnWidth(110, 1);
        foreach ($instructions as $line) {
            $writer->addRow(Row::fromValues([$line]));
        }
        $writer->close();
    }

    /**
     * @return array<int, string>
     */
    private function cells(Row $row): array
    {
        return array_map(function ($value) {
            if ($value instanceof DateTimeInterface) {
                return $value->format('Y-m-d');
            }
            if (is_float($value) && floor($value) === $value) {
                return (string) (int) $value;
            }

            return trim((string) $value);
        }, $row->toArray());
    }

    private function csvOptions(string $path): CsvOptions
    {
        $options = new CsvOptions;
        $handle = fopen($path, 'r');
        $first = (string) fgets($handle);
        fclose($handle);
        $counts = ["\t" => substr_count($first, "\t"), ';' => substr_count($first, ';'), ',' => substr_count($first, ',')];
        arsort($counts);
        $options->FIELD_DELIMITER = (string) array_key_first($counts);

        return $options;
    }
}
