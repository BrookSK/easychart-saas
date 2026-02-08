<?php

class DataIngestionHelper
{
    public static function ingestFile(string $filePath, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($ext === 'csv') {
            return self::ingestCsv($filePath);
        }

        if ($ext === 'xml') {
            return self::ingestXml($filePath);
        }

        if ($ext === 'xlsx') {
            return self::ingestXlsx($filePath);
        }

        if ($ext === 'pdf') {
            return self::ingestPdfBestEffort($filePath);
        }

        return [
            'ok' => false,
            'error' => 'Tipo de arquivo não suportado para extração estruturada.',
            'table' => null,
            'notes' => ['ignored' => true],
        ];
    }

    private static function ingestCsv(string $filePath): array
    {
        if (!is_file($filePath)) {
            return ['ok' => false, 'error' => 'Arquivo não encontrado.', 'table' => null, 'notes' => []];
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return ['ok' => false, 'error' => 'Falha ao abrir CSV.', 'table' => null, 'notes' => []];
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            return ['ok' => false, 'error' => 'CSV vazio.', 'table' => null, 'notes' => []];
        }

        $delimiter = self::detectCsvDelimiter($firstLine);
        $headers = str_getcsv($firstLine, $delimiter);

        if (!$headers || !is_array($headers)) {
            fclose($handle);
            return ['ok' => false, 'error' => 'CSV sem cabeçalho válido.', 'table' => null, 'notes' => []];
        }

        // Remove BOM UTF-8 no primeiro header, se houver
        if (!empty($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
        }

        $rows = [];
        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }
            $row = str_getcsv($line, $delimiter);
            $rows[] = $row;
        }
        fclose($handle);

        return [
            'ok' => true,
            'error' => null,
            'table' => [
                'headers' => array_map(fn($h) => trim((string)$h), $headers),
                'rows' => $rows,
            ],
            'notes' => ['csv' => ['delimiter' => $delimiter]],
        ];
    }

    private static function detectCsvDelimiter(string $line): string
    {
        $candidates = [',', ';', "\t"];
        $best = ',';
        $bestCount = -1;
        foreach ($candidates as $d) {
            $count = substr_count($line, $d);
            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $d;
            }
        }
        return $best;
    }

    private static function ingestXml(string $filePath): array
    {
        if (!is_file($filePath)) {
            return ['ok' => false, 'error' => 'Arquivo não encontrado.', 'table' => null, 'notes' => []];
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($filePath);
        if ($xml === false) {
            libxml_clear_errors();
            return ['ok' => false, 'error' => 'XML inválido.', 'table' => null, 'notes' => []];
        }

        $items = [];
        foreach ($xml->children() as $child) {
            $item = [];
            foreach ($child->attributes() as $k => $v) {
                $item[(string)$k] = (string)$v;
            }
            foreach ($child->children() as $ck => $cv) {
                $item[(string)$ck] = (string)$cv;
            }
            if (!empty($item)) {
                $items[] = $item;
            }
        }
        libxml_clear_errors();

        if (empty($items)) {
            return ['ok' => false, 'error' => 'XML não contém itens tabulares simples.', 'table' => null, 'notes' => []];
        }

        $headers = [];
        foreach ($items as $it) {
            foreach (array_keys($it) as $k) {
                $headers[$k] = true;
            }
        }
        $headers = array_keys($headers);

        $rows = [];
        foreach ($items as $it) {
            $row = [];
            foreach ($headers as $h) {
                $row[] = $it[$h] ?? '';
            }
            $rows[] = $row;
        }

        return [
            'ok' => true,
            'error' => null,
            'table' => [
                'headers' => $headers,
                'rows' => $rows,
            ],
            'notes' => [],
        ];
    }

    private static function ingestXlsx(string $filePath): array
    {
        if (!is_file($filePath)) {
            return ['ok' => false, 'error' => 'Arquivo não encontrado.', 'table' => null, 'notes' => []];
        }

        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'error' => 'ZipArchive indisponível no PHP. Não foi possível ler XLSX.', 'table' => null, 'notes' => []];
        }

        $zip = new ZipArchive();
        $res = $zip->open($filePath);
        if ($res !== true) {
            return ['ok' => false, 'error' => 'Falha ao abrir XLSX (zip).', 'table' => null, 'notes' => []];
        }

        $sharedStrings = [];
        $sharedPath = 'xl/sharedStrings.xml';
        $sharedXml = $zip->getFromName($sharedPath);
        if ($sharedXml !== false) {
            $sx = simplexml_load_string($sharedXml);
            if ($sx !== false && isset($sx->si)) {
                foreach ($sx->si as $si) {
                    if (isset($si->t)) {
                        $sharedStrings[] = (string)$si->t;
                    } elseif (isset($si->r)) {
                        $parts = [];
                        foreach ($si->r as $r) {
                            $parts[] = (string)($r->t ?? '');
                        }
                        $sharedStrings[] = implode('', $parts);
                    } else {
                        $sharedStrings[] = '';
                    }
                }
            }
        }

        $sheetPath = self::findFirstWorksheetPath($zip);
        if (!$sheetPath) {
            $sheetPath = 'xl/worksheets/sheet1.xml';
        }

        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false) {
            $zip->close();
            return ['ok' => false, 'error' => 'XLSX sem worksheet acessível (primeira aba não encontrada).', 'table' => null, 'notes' => []];
        }

        $sheet = simplexml_load_string($sheetXml);
        if ($sheet === false || !isset($sheet->sheetData)) {
            $zip->close();
            return ['ok' => false, 'error' => 'Estrutura XLSX inválida (sheetData).', 'table' => null, 'notes' => []];
        }

        $rowsByIndex = [];
        foreach ($sheet->sheetData->row as $row) {
            $rIndex = (int)($row['r'] ?? 0);
            if ($rIndex <= 0) {
                continue;
            }
            $cells = [];
            foreach ($row->c as $c) {
                $ref = (string)($c['r'] ?? '');
                $colLetters = preg_replace('/\d+/', '', $ref);
                $colIndex = self::excelColToIndex($colLetters);

                $type = (string)($c['t'] ?? '');
                $v = isset($c->v) ? (string)$c->v : '';

                if ($type === 's') {
                    $si = (int)$v;
                    $val = $sharedStrings[$si] ?? '';
                } else {
                    $val = $v;
                }

                $cells[$colIndex] = $val;
            }
            if (!empty($cells)) {
                $rowsByIndex[$rIndex] = $cells;
            }
        }
        $zip->close();

        if (empty($rowsByIndex)) {
            return ['ok' => false, 'error' => 'XLSX sem dados na primeira aba.', 'table' => null, 'notes' => []];
        }

        ksort($rowsByIndex);
        $firstRow = reset($rowsByIndex);
        $maxCol = max(array_keys($firstRow));
        foreach ($rowsByIndex as $cells) {
            if (!empty($cells)) {
                $maxCol = max($maxCol, max(array_keys($cells)));
            }
        }

        $rKeys = array_keys($rowsByIndex);
        $headerCells = $rowsByIndex[$rKeys[0]];
        $headers = [];
        for ($c = 1; $c <= $maxCol; $c++) {
            $headers[] = trim((string)($headerCells[$c] ?? ''));
        }

        $rows = [];
        for ($i = 1; $i < count($rKeys); $i++) {
            $cells = $rowsByIndex[$rKeys[$i]];
            $row = [];
            for ($c = 1; $c <= $maxCol; $c++) {
                $row[] = (string)($cells[$c] ?? '');
            }
            $rows[] = $row;
        }

        $nonEmptyHeaders = array_filter($headers, fn($h) => $h !== '');
        if (empty($nonEmptyHeaders)) {
            $headers = [];
            for ($c = 1; $c <= $maxCol; $c++) {
                $headers[] = 'col_' . $c;
            }
        }

        return [
            'ok' => true,
            'error' => null,
            'table' => [
                'headers' => $headers,
                'rows' => $rows,
            ],
            'notes' => ['xlsx' => ['worksheet' => $sheetPath]],
        ];
    }

    private static function findFirstWorksheetPath(ZipArchive $zip): ?string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        if ($workbookXml === false) {
            return null;
        }

        $workbook = simplexml_load_string($workbookXml);
        if ($workbook === false || empty($workbook->sheets) || empty($workbook->sheets->sheet)) {
            return null;
        }

        $rid = null;
        foreach ($workbook->sheets->sheet as $sheet) {
            $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            if ($attrs && isset($attrs['id'])) {
                $rid = (string)$attrs['id'];
                break;
            }
        }
        if (!$rid) {
            return null;
        }

        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($relsXml === false) {
            return null;
        }

        $rels = simplexml_load_string($relsXml);
        if ($rels === false) {
            return null;
        }

        foreach ($rels->Relationship as $rel) {
            $id = (string)($rel['Id'] ?? '');
            if ($id === $rid) {
                $target = (string)($rel['Target'] ?? '');
                if ($target === '') {
                    return null;
                }
                $target = str_replace('\\', '/', $target);
                if (strpos($target, 'xl/') === 0) {
                    return $target;
                }
                return 'xl/' . ltrim($target, '/');
            }
        }

        return null;
    }

    private static function ingestPdfBestEffort(string $filePath): array
    {
        if (!is_file($filePath)) {
            return ['ok' => false, 'error' => 'Arquivo não encontrado.', 'table' => null, 'notes' => []];
        }

        // Best-effort: tenta usar pdftotext (quando disponível no servidor)
        // e inferir uma tabela a partir de separadores comuns (tab/; , ou múltiplos espaços).
        $tmpOut = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'easychart_pdf_' . uniqid('', true) . '.txt';

        $cmd = 'pdftotext -layout ' . escapeshellarg($filePath) . ' ' . escapeshellarg($tmpOut) . ' 2>&1';
        $out = null;
        if (function_exists('shell_exec')) {
            $out = @shell_exec($cmd);
        }

        if (!is_file($tmpOut)) {
            return [
                'ok' => false,
                'error' => 'PDF estruturado: extração não disponível (pdftotext ausente ou desabilitado).',
                'table' => null,
                'notes' => ['pdf' => ['pdftotext' => 'unavailable', 'cmd_out' => $out]],
            ];
        }

        $text = @file_get_contents($tmpOut);
        @unlink($tmpOut);
        if ($text === false || trim($text) === '') {
            return [
                'ok' => false,
                'error' => 'PDF estruturado: texto extraído vazio.',
                'table' => null,
                'notes' => ['pdf' => ['pdftotext' => 'ok']],
            ];
        }

        $lines = preg_split('/\R/u', $text);
        $lines = array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== ''));

        // tenta detectar linhas tabulares
        $candidates = [];
        foreach ($lines as $l) {
            $parts = null;
            if (strpos($l, "\t") !== false) {
                $parts = preg_split('/\t+/', $l);
            } elseif (substr_count($l, ';') >= 2) {
                $parts = explode(';', $l);
            } elseif (substr_count($l, ',') >= 2) {
                $parts = explode(',', $l);
            } else {
                // múltiplos espaços => colunas
                $parts = preg_split('/\s{2,}/', $l);
            }

            $parts = array_map('trim', $parts ?: []);
            $parts = array_values(array_filter($parts, fn($p) => $p !== ''));

            if (count($parts) >= 2) {
                $candidates[] = $parts;
            }
        }

        if (count($candidates) < 2) {
            return [
                'ok' => false,
                'error' => 'PDF estruturado: não foi possível inferir tabela (poucas linhas tabulares).',
                'table' => null,
                'notes' => ['pdf' => ['pdftotext' => 'ok', 'lines' => count($lines)]],
            ];
        }

        $maxCols = 0;
        foreach ($candidates as $p) {
            $maxCols = max($maxCols, count($p));
        }

        // mantém apenas linhas com colunas próximas do máximo
        $tableRows = [];
        foreach ($candidates as $p) {
            if (count($p) >= max(2, $maxCols - 1)) {
                while (count($p) < $maxCols) {
                    $p[] = '';
                }
                $tableRows[] = $p;
            }
        }

        if (count($tableRows) < 2) {
            return [
                'ok' => false,
                'error' => 'PDF estruturado: não foi possível estabilizar a estrutura tabular.',
                'table' => null,
                'notes' => ['pdf' => ['pdftotext' => 'ok']],
            ];
        }

        $headers = $tableRows[0];
        $rows = array_slice($tableRows, 1);

        // fallback se cabeçalho vier vazio
        $nonEmpty = 0;
        foreach ($headers as $h) {
            if (trim((string)$h) !== '') {
                $nonEmpty++;
            }
        }
        if ($nonEmpty === 0) {
            $headers = [];
            for ($i = 1; $i <= $maxCols; $i++) {
                $headers[] = 'col_' . $i;
            }
        }

        return [
            'ok' => true,
            'error' => null,
            'table' => [
                'headers' => $headers,
                'rows' => $rows,
            ],
            'notes' => ['pdf' => ['pdftotext' => 'ok', 'cols' => $maxCols, 'rows' => count($rows)]],
        ];
    }

    private static function excelColToIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $n = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $ch = ord($letters[$i]);
            if ($ch < 65 || $ch > 90) {
                continue;
            }
            $n = $n * 26 + ($ch - 64);
        }
        return $n;
    }
}
