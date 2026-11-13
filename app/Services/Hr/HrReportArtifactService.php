<?php

namespace App\Services\Hr;

use Barryvdh\DomPDF\Facade\Pdf;
use RuntimeException;
use ZipArchive;

/** Renders a governed HR report dataset without accessing the database. */
class HrReportArtifactService
{
    public function render(string $format, array $columns, array $rows, string $title, array $metadata = []): array
    {
        return match ($format) {
            'csv' => $this->csv($columns, $rows, $metadata),
            'pdf' => $this->pdf($columns, $rows, $title, $metadata),
            'xlsx' => $this->xlsx($columns, $rows, $title, $metadata),
            default => throw new RuntimeException('Unsupported HR report format.'),
        };
    }

    private function csv(array $columns, array $rows, array $metadata): array
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $columns);
        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn ($column) => $this->csvSafeValue($this->scalar($row[$column] ?? null)), $columns));
        }
        fputcsv($stream, []);
        fputcsv($stream, ['# Report metadata', 'Value']);
        foreach ($metadata as $label => $value) {
            fputcsv($stream, ['# '.$label, $this->csvSafeValue($this->scalar($value))]);
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return ['content' => $content, 'extension' => 'csv', 'mime' => 'text/csv; charset=UTF-8'];
    }

    private function pdf(array $columns, array $rows, string $title, array $metadata): array
    {
        $content = Pdf::loadView('hr.report-artifact', compact('title', 'columns', 'rows', 'metadata'))
            ->setPaper('a4', 'landscape')
            ->output();

        return ['content' => $content, 'extension' => 'pdf', 'mime' => 'application/pdf'];
    }

    private function xlsx(array $columns, array $rows, string $title, array $metadata): array
    {
        abort_unless(class_exists(ZipArchive::class), 500, 'The PHP ZIP extension is required for XLSX generation.');
        $sheetRows = array_merge(
            [['Report metadata', 'Value']],
            array_map(fn ($label, $value) => [$label, $this->scalar($value)], array_keys($metadata), array_values($metadata)),
            [[], [$title], $columns],
            array_map(fn ($row) => array_map(fn ($column) => $this->scalar($row[$column] ?? null), $columns), $rows),
        );
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($sheetRows as $rowIndex => $row) {
            $xml .= '<row r="'.($rowIndex + 1).'">';
            foreach (array_values($row) as $columnIndex => $value) {
                $cell = $this->column($columnIndex).($rowIndex + 1);
                $xml .= '<c r="'.$cell.'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';

        $temporaryFile = tempnam(sys_get_temp_dir(), 'hr-report-');
        if ($temporaryFile === false) {
            throw new RuntimeException('Could not allocate temporary XLSX file.');
        }
        $archive = new ZipArchive();
        $archiveOpened = false;
        try {
            if ($archive->open($temporaryFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create XLSX archive.');
            }
            $archiveOpened = true;
            $archive->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
            $archive->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $archive->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="HR Report" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $archive->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
            $archive->addFromString('xl/worksheets/sheet1.xml', $xml);
            $archive->close();
            $archiveOpened = false;
            $content = file_get_contents($temporaryFile);
        } finally {
            if ($archiveOpened) {
                $archive->close();
            }
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }

        return ['content' => $content, 'extension' => 'xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }

    private function csvSafeValue(string $value): string
    {
        return preg_match('/^[\s\x{FEFF}]*[=+\-@]/u', $value) === 1 ? "'".$value : $value;
    }

    private function scalar(mixed $value): string
    {
        return is_array($value) || is_object($value)
            ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            : (string) ($value ?? '');
    }

    private function column(int $number): string
    {
        $name = '';
        do {
            $name = chr(65 + ($number % 26)).$name;
            $number = intdiv($number, 26) - 1;
        } while ($number >= 0);

        return $name;
    }
}
