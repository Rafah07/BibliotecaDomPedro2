<?php
/**
 * Atualiza a análise da Central de Qualidade do Acervo fora do horário de trabalho (migração 190).
 *
 * Uso:  C:\xampp\php\php.exe database/analisar-qualidade.php
 *
 * Pode ser agendado no Agendador de Tarefas do Windows (ou no cron da hospedagem), por exemplo uma vez
 * por noite. Só grava as tabelas qualidade_analises e qualidade_pendencias: nenhum dado do acervo muda.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../config/database.php';

try {
    $pdo = db();
    if (!$pdo) {
        fwrite(STDERR, "Banco não configurado (.env).\n");
        exit(1);
    }
    $t = (new \Biblioteca\Services\QualidadeAcervoService($pdo))->analisar(null);
    echo 'Análise concluída: ' . $t['critico'] . ' crítica(s), ' . $t['atencao'] . ' de atenção, ' . $t['informativo'] . ' informativa(s); '
        . $t['novas'] . ' nova(s), ' . $t['resolvidas'] . " resolvida(s).\n";
} catch (Throwable $e) {
    $id = \Biblioteca\Support\RegistroErros::registrar($e, 'análise de qualidade (rotina)');
    fwrite(STDERR, 'A análise não foi concluída (' . $id . '). Confira se a migração 190 foi aplicada e o Diagnóstico do sistema.' . "\n");
    exit(1);
}
