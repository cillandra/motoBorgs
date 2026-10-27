<?php

namespace App\Services\Reporting;

use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvExporter
{
    public function stream(string $filename, array $columns, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $rows): void {
            $stream = fopen('php://output', 'wb');
            $write = function (array $row) use ($stream): void {
                $safe = array_map(function ($value) {
                    if (is_string($value) && preg_match('/^[\x00-\x20]*[=+\-@]/u', $value)) {
                        return "'".$value;
                    }

                    return $value;
                }, $row);
                fputcsv($stream, $safe, ',', '"', '');
            };
            $write($columns);
            foreach ($rows as $row) {
                $write(array_values($row));
            }
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
