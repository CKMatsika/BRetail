<?php

namespace App\Services\Tender;

use Illuminate\Support\Facades\Log;
use ZipArchive;

final class DocumentTextExtractor
{
    /**
     * Extracts text and page metadata from a file on disk.
     *
     * @return array{text: string, pages: array<int, string>, format: string, word_count: int}
     */
    public function extract(string $filePath, ?string $mimeType = null, ?string $originalName = null): array
    {
        if (! file_exists($filePath)) {
            return ['text' => '', 'pages' => [], 'format' => 'unknown', 'word_count' => 0];
        }

        $extension = strtolower(pathinfo($originalName ?? $filePath, PATHINFO_EXTENSION));

        try {
            return match ($extension) {
                'pdf' => $this->extractPdf($filePath),
                'docx' => $this->extractDocx($filePath),
                'xlsx' => $this->extractXlsx($filePath),
                'zip' => $this->extractZip($filePath),
                'txt', 'csv', 'json', 'md' => $this->extractPlainText($filePath, $extension),
                'jpg', 'jpeg', 'png' => $this->extractImageOcr($filePath, $extension),
                default => $this->extractFallback($filePath, $extension),
            };
        } catch (\Throwable $e) {
            Log::warning("DocumentTextExtractor failed for {$filePath}: ".$e->getMessage());

            return [
                'text' => '',
                'pages' => [],
                'format' => $extension,
                'word_count' => 0,
            ];
        }
    }

    private function extractPlainText(string $path, string $ext): array
    {
        $content = file_get_contents($path) ?: '';
        $clean = preg_replace('/[^\PC\s]/u', '', $content) ?? $content;

        return [
            'text' => trim($clean),
            'pages' => [1 => trim($clean)],
            'format' => $ext,
            'word_count' => str_word_count($clean),
        ];
    }

    private function extractDocx(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return ['text' => '', 'pages' => [], 'format' => 'docx', 'word_count' => 0];
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (! $xml) {
            return ['text' => '', 'pages' => [], 'format' => 'docx', 'word_count' => 0];
        }

        // Clean XML to plain text paragraphs
        $xml = preg_replace('/<w:p[^>]*>/i', "\n", $xml);
        $xml = preg_replace('/<w:br[^>]*>/i', "\n", $xml);
        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", trim($text));

        // Estimate pages (~400 words per page)
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $chunks = array_chunk($words, 400);
        $pages = [];
        foreach ($chunks as $idx => $chunk) {
            $pages[$idx + 1] = implode(' ', $chunk);
        }

        return [
            'text' => $text,
            'pages' => ! empty($pages) ? $pages : [1 => $text],
            'format' => 'docx',
            'word_count' => count($words),
        ];
    }

    private function extractXlsx(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return ['text' => '', 'pages' => [], 'format' => 'xlsx', 'word_count' => 0];
        }

        // Shared strings
        $stringsXml = $zip->getFromName('xl/sharedStrings.xml');
        $sharedStrings = [];
        if ($stringsXml) {
            $sDom = @simplexml_load_string($stringsXml);
            if ($sDom) {
                foreach ($sDom->si as $val) {
                    $sharedStrings[] = (string) ($val->t ?? ($val->r ? implode('', (array) $val->r->t) : ''));
                }
            }
        }

        $lines = [];
        for ($i = 1; $i <= 10; $i++) {
            $sheetXml = $zip->getFromName("xl/worksheets/sheet{$i}.xml");
            if (! $sheetXml) {
                break;
            }
            $dom = @simplexml_load_string($sheetXml);
            if (! $dom || ! isset($dom->sheetData->row)) {
                continue;
            }

            foreach ($dom->sheetData->row as $row) {
                $rowCells = [];
                foreach ($row->c as $c) {
                    $type = (string) $c['t'];
                    $val = (string) $c->v;
                    if ($type === 's' && isset($sharedStrings[(int) $val])) {
                        $rowCells[] = $sharedStrings[(int) $val];
                    } elseif ($val !== '') {
                        $rowCells[] = $val;
                    }
                }
                if (! empty($rowCells)) {
                    $lines[] = implode(' | ', $rowCells);
                }
            }
        }
        $zip->close();

        $text = implode("\n", $lines);

        return [
            'text' => $text,
            'pages' => [1 => $text],
            'format' => 'xlsx',
            'word_count' => str_word_count($text),
        ];
    }

    private function extractZip(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return ['text' => '', 'pages' => [], 'format' => 'zip', 'word_count' => 0];
        }

        $allText = [];
        $pages = [];
        $pageCounter = 1;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (str_ends_with($filename, '/')) {
                continue;
            }

            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (! in_array($ext, ['txt', 'csv', 'json', 'md', 'docx'], true)) {
                continue;
            }

            $stream = $zip->getStream($filename);
            if (! $stream) {
                continue;
            }

            $content = stream_get_contents($stream);
            fclose($stream);

            if ($ext === 'docx') {
                $tempPath = tempnam(sys_get_temp_dir(), 'docx_');
                file_put_contents($tempPath, $content);
                $docRes = $this->extractDocx($tempPath);
                @unlink($tempPath);
                $fileText = "[File: {$filename}]\n".$docRes['text'];
            } else {
                $fileText = "[File: {$filename}]\n".$content;
            }

            $allText[] = $fileText;
            $pages[$pageCounter++] = $fileText;
        }
        $zip->close();

        $combined = implode("\n\n---\n\n", $allText);

        return [
            'text' => $combined,
            'pages' => $pages,
            'format' => 'zip',
            'word_count' => str_word_count($combined),
        ];
    }

    private function extractPdf(string $path): array
    {
        // 1. Try pdftotext CLI if installed (Windows or Linux)
        $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $checkCmd = $isWin ? 'where pdftotext 2>nul' : 'which pdftotext 2>/dev/null';
        $pdfToText = trim(@shell_exec($checkCmd) ?: '');
        if ($pdfToText) {
            $bin = $isWin ? explode("\r\n", $pdfToText)[0] : $pdfToText;
            if (file_exists($bin)) {
                $escapedBin = escapeshellarg($bin);
                $escaped = escapeshellarg($path);
                $out = @shell_exec("{$escapedBin} {$escaped} -");
                if ($out && strlen(trim($out)) > 10) {
                    return [
                        'text' => trim($out),
                        'pages' => [1 => trim($out)],
                        'format' => 'pdf',
                        'word_count' => str_word_count($out),
                    ];
                }
            }
        }

        // 2. Pure PHP PDF Stream Text Parser
        $content = file_get_contents($path) ?: '';
        if (empty($content)) {
            return ['text' => '', 'pages' => [], 'format' => 'pdf', 'word_count' => 0];
        }

        $pages = [];
        $extractedText = [];

        // Locate PDF object streams
        preg_match_all('/stream[\r\n]+(.*?)[\r\n]+endstream/s', $content, $matches);
        $streamIndex = 1;

        foreach ($matches[1] as $stream) {
            $data = $stream;
            // Attempt decompression
            $uncompressed = @gzuncompress($stream);
            if ($uncompressed !== false) {
                $data = $uncompressed;
            }

            // Extract Text between BT and ET
            if (preg_match_all('/BT[\r\n]+(.*?)[\r\n]+ET/s', $data, $textBlocks)) {
                $pageLines = [];
                foreach ($textBlocks[1] as $block) {
                    // Extract strings in parentheses (Text)
                    if (preg_match_all('/\((.*?)\)\s*T[jJ]/s', $block, $strings)) {
                        $pageLines[] = implode(' ', $strings[1]);
                    }
                    // Extract arrays [(...)] TJ
                    if (preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $arrays)) {
                        foreach ($arrays[1] as $arr) {
                            if (preg_match_all('/\((.*?)\)/s', $arr, $subStrings)) {
                                $pageLines[] = implode('', $subStrings[1]);
                            }
                        }
                    }
                }

                if (! empty($pageLines)) {
                    $cleaned = preg_replace('/\\\\[\r\n]/', '', implode("\n", $pageLines));
                    $cleaned = preg_replace('/\\\([()\\\\])/', '$1', $cleaned);
                    $extractedText[] = $cleaned;
                    $pages[$streamIndex++] = $cleaned;
                }
            }
        }

        $fullText = implode("\n\n", $extractedText);

        // Fallback: If compressed streams didn't yield text (e.g. font dictionary encoding), extract readable ASCII chunks
        if (strlen(trim($fullText)) < 30) {
            preg_match_all('/[\x20-\x7E\r\n\t]{6,}/', $content, $asciiMatches);
            $fullText = implode("\n", array_filter($asciiMatches[0], fn ($s) => ! str_starts_with($s, '<<') && ! str_starts_with($s, 'obj') && ! str_starts_with($s, 'endobj')));
        }

        return [
            'text' => trim($fullText),
            'pages' => ! empty($pages) ? $pages : [1 => trim($fullText)],
            'format' => 'pdf',
            'word_count' => str_word_count($fullText),
        ];
    }

    private function extractImageOcr(string $path, string $ext): array
    {
        // Try Tesseract OCR if available
        $tesseract = trim(@shell_exec('where tesseract 2>nul') ?: '');
        if ($tesseract && file_exists($tesseract)) {
            $escaped = escapeshellarg($path);
            $out = @shell_exec("{$tesseract} {$escaped} stdout --oem 1 -l eng 2>nul");
            if ($out && strlen(trim($out)) > 10) {
                return [
                    'text' => trim($out),
                    'pages' => [1 => trim($out)],
                    'format' => $ext,
                    'word_count' => str_word_count($out),
                ];
            }
        }

        // Graceful metadata notice when Tesseract is not installed locally
        $size = @filesize($path) ?: 0;
        $notice = "[Scanned image: ".basename($path)." (".number_format($size / 1024, 1)." KB). Document visual layout registered. OCR binary not detected on host system.]";

        return [
            'text' => $notice,
            'pages' => [1 => $notice],
            'format' => $ext,
            'word_count' => 15,
        ];
    }

    private function extractFallback(string $path, string $ext): array
    {
        $raw = file_get_contents($path) ?: '';
        preg_match_all('/[\x20-\x7E\r\n\t]{5,}/', $raw, $matches);
        $text = implode("\n", $matches[0] ?? []);

        return [
            'text' => trim($text),
            'pages' => [1 => trim($text)],
            'format' => $ext,
            'word_count' => str_word_count($text),
        ];
    }
}

