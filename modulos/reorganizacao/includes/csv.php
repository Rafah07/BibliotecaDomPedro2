<?php
/** Exportação CSV simples (UTF-8 com BOM, separador ";" — abre direto no Excel em português). */
function reorg_csv(string $arquivo, array $cabecalho, iterable $linhas): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $arquivo) . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $cabecalho, ';');
    foreach ($linhas as $l) {
        // Evita fórmulas ao abrir no Excel (=, +, -, @ no começo da célula).
        fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $l), ';');
    }
    fclose($out);
    exit;
}
