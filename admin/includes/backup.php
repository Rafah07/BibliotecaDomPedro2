<?php
/**
 * Cópias de segurança no painel (migração 150): pasta protegida do servidor, última cópia conhecida,
 * aviso antes de operações de alto impacto e cópia automática opcional antes delas.
 *
 * - Pasta: storage/backups (ou BACKUP_DIR no .env). Fica fora do acesso público: o .htaccess da raiz
 *   bloqueia /storage e a própria pasta recebe "Require all denied". O download passa pelo painel
 *   (somente quem tem sistema.manutencao).
 * - Nada aqui restaura cópias: a restauração é manual, num banco VAZIO, com as instruções da página
 *   Manutenção (restaurar automaticamente sobre os dados atuais seria perigoso).
 */
require_once __DIR__ . '/componentes.php';

use Biblioteca\Services\BackupService;

/** Horas sem cópia a partir das quais as operações de alto impacto mostram o aviso. */
const BACKUP_RECENTE_HORAS = 24;

function backup_pasta(): string
{
    $env = env('BACKUP_DIR');
    return $env !== null && trim($env) !== '' ? rtrim(trim($env), '/\\') : APP_RAIZ . '/storage/backups';
}

function backup_servico(): BackupService
{
    static $s = null;
    return $s ??= new BackupService(db());
}

/** Última cópia registrada (null se nenhuma ou se a tabela ainda não existe). */
function backup_ultimo(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    try {
        return $cache = backup_servico()->ultima();
    } catch (Throwable) {
        return $cache = null;
    }
}

function backup_recente(int $horas = BACKUP_RECENTE_HORAS): bool
{
    $u = backup_ultimo();
    return $u !== null && strtotime((string)$u['criado_em']) >= time() - $horas * 3600;
}

/** Texto "última cópia: 14/11/2026 18:20 por Ana (salva no servidor e conferida)". */
function backup_ultimo_texto(): string
{
    $u = backup_ultimo();
    if (!$u) {
        return 'Nenhuma cópia de segurança registrada ainda.';
    }
    $origem = ['servidor' => 'salva no servidor e conferida', 'automatico' => 'automática, salva no servidor e conferida', 'migracao' => 'automática antes de migração',
        'download' => 'baixada pelo navegador'][$u['origem']] ?? $u['origem'];
    return 'Última cópia: ' . admin_data_hora($u['criado_em']) . ($u['administrador_nome'] ? ' por ' . $u['administrador_nome'] : '') . ' (' . $origem . ').';
}

/** Aviso para operações importantes iniciadas sem cópia recente. $mostrar = a operação é de alto impacto. */
function backup_aviso_html(bool $mostrar = true): string
{
    if (!$mostrar || backup_recente()) {
        return '';
    }
    $quem = auth_pode('sistema.manutencao')
        ? 'Faça uma cópia em <a class="adm-link" href="' . e(admin_url('manutencao')) . '#titulo-backup">Configurações → Manutenção</a> ou marque a cópia automática abaixo, quando houver.'
        : 'Peça ao administrador do sistema para fazer uma cópia em Configurações → Manutenção antes de continuar.';
    return '<div class="adm-notice adm-notice--amber adm-backup-aviso" role="note">' . icon('database-backup')
        . '<div><strong>Sem cópia de segurança recente.</strong><p>' . e(backup_ultimo_texto()) . ' Esta operação altera muitos registros. ' . $quem . '</p></div></div>';
}

/**
 * Cópia automática ANTES de uma operação de alto impacto (somente administrador). Se a cópia falhar,
 * a operação não continua: a exceção chega a quem chamou com uma mensagem clara.
 */
function backup_antes_de(array $usuario, string $motivo): array
{
    if (!auth_pode('sistema.manutencao')) {
        throw new Biblioteca\Services\AcervoException('Só o administrador do sistema faz cópias de segurança.');
    }
    @set_time_limit(0);
    try {
        $r = backup_servico()->salvarNoServidor(backup_pasta(), $usuario['id'], 'automatico', $motivo);
    } catch (Throwable $e) {
        error_log('[biblioteca] Falha na cópia automática: ' . get_class($e) . ' ' . $e->getMessage());
        throw new Biblioteca\Services\AcervoException('A cópia de segurança automática falhou, por isso a operação NÃO foi feita. Confira a pasta de backups em Configurações → Manutenção.');
    }
    registrar_acao('sistema.backup', 'sistema', null, ['origem' => 'automatico', 'arquivo' => $r['arquivo'], 'motivo' => $motivo, 'tabelas' => $r['tabelas'], 'linhas' => $r['linhas']]);
    return $r;
}
