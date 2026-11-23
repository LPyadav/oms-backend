<?php

declare(strict_types=1);

namespace App\Http\Responses;

use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CsvDownload
{
    /**
     * @param  list<string>  $columns
     * @param  iterable<list<string>>  $rows
     */
    public function download(string $filename, array $columns, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(
            static function () use ($columns, $rows): void {
                $output = fopen('php://output', 'wb');
                if ($output === false) {
                    throw new RuntimeException('Unable to open the CSV output.');
                }

                try {
                    fwrite($output, "\xEF\xBB\xBF");
                    fputcsv($output, $columns, escape: '', eol: "\r\n");

                    foreach ($rows as $row) {
                        fputcsv($output, $row, escape: '', eol: "\r\n");
                    }
                } finally {
                    fclose($output);
                }
            },
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private'],
        );
    }

    public static function safeText(string $value): string
    {
        // CSV quoting alone does not stop spreadsheets from interpreting a formula.
        return preg_match('/\A(?:[\x00-\x1f\x7f]|[\s\p{Z}]*[=+\-@＝＋－＠])/u', $value) === 1
            ? "'".$value
            : $value;
    }

    public static function decimalAmount(int $minorUnits): string
    {
        return sprintf('%s%d.%02d', $minorUnits < 0 ? '-' : '', abs(intdiv($minorUnits, 100)), abs($minorUnits % 100));
    }
}
