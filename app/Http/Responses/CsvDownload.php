<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class CsvDownload
{
    private const int STREAM_CHUNK_BYTES = 64 * 1024;

    /**
     * @param  list<string>  $columns
     * @param  iterable<list<string>>  $rows
     */
    public function download(string $filename, array $columns, iterable $rows, int $limit): StreamedResponse
    {
        $file = tmpfile();
        if ($file === false) {
            throw new RuntimeException('Unable to open a temporary CSV file.');
        }

        // Prepare the download before sending headers so overflow can return a JSON validation error.
        try {
            if (fwrite($file, "\xEF\xBB\xBF") !== 3) {
                throw new RuntimeException('Unable to write the CSV byte order mark.');
            }
            $this->writeRow($file, $columns);

            $rowsWritten = 0;
            foreach ($rows as $row) {
                if ($rowsWritten === $limit) {
                    throw ValidationException::withMessages([
                        'export' => Lang::string('api.orders.export_limit', ['limit' => $limit]),
                    ]);
                }

                $this->writeRow($file, $row);
                $rowsWritten++;
            }

            if (! rewind($file)) {
                throw new RuntimeException('Unable to rewind the CSV file.');
            }
        } catch (Throwable $exception) {
            fclose($file);

            throw $exception;
        }

        return response()->streamDownload(
            static function () use ($file): void {
                try {
                    while (! feof($file)) {
                        $chunk = fread($file, self::STREAM_CHUNK_BYTES);
                        if ($chunk === false) {
                            throw new RuntimeException('Unable to read the CSV file.');
                        }

                        echo $chunk;
                    }
                } finally {
                    fclose($file);
                }
            },
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private'],
        );
    }

    /**
     * @param  resource  $file
     * @param  list<string>  $row
     */
    private function writeRow($file, array $row): void
    {
        if (fputcsv($file, $row, escape: '', eol: "\r\n") === false) {
            throw new RuntimeException('Unable to write a CSV row.');
        }
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
