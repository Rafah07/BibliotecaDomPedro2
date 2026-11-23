<?php

namespace Biblioteca\Services;

use Biblioteca\Support\Migracoes;
use Biblioteca\Support\RegistroErros;
use PDO;
use Throwable;

/**
 * Diagnóstico do sistema (Configurações → Manutenção → Diagnóstico; migração 190).
 *
 * Confere as condições técnicas do funcionamento da biblioteca e mostra resultados compreensíveis.
 * Regras:
 *  - Só leitura: nada é reparado, apagado ou alterado.
 *  - Uma verificação que não pôde ser feita aparece como "Não verificado", nunca como "normal".
 *  - Com o banco fora do ar ($pdo = null), as verificações que não dependem dele (PHP, pastas, disco,
 *    proteções, arquivos de cópia, registro de erros) continuam sendo mostradas.
 *  - Nenhum detalhe traz senha, conteúdo do .env, consulta SQL ou caminho completo do servidor.
 *
 * Cada item: ['titulo', 'situacao' => ok|aviso|erro|nao_verificado, 'detalhe'].
 */
final class DiagnosticoCompleto
{
    /** Tabelas que precisam existir (núcleo do sistema e migração 190). */
    public const TABELAS = ['livros', 'autores', 'livro_autores', 'categorias', 'livro_categorias', 'exemplares', 'exemplar_codigo_seq', 'movimentacoes', 'localizacoes',
        'administradores', 'alunos', 'emprestimos', 'inventarios', 'inventario_itens', 'registros_legados', 'cadastro_lotes', 'lote_eventos', 'backups',
        'livro_verificacoes', 'obra_edicoes', 'requisicoes_idempotentes', 'planilha_bdp_versoes', 'qualidade_pendencias', 'aquisicoes', 'obra_unificacoes'];

    /** Colunas que já causaram falhas quando faltavam (tabela => colunas). */
    public const COLUNAS = [
        'livros' => ['versao', 'dados_verificados_em', 'unificado_em_id'],
        'exemplares' => ['carimbo_status', 'lote_id', 'lote_ordem', 'aquisicao_id', 'baixa_tipo'],
        'localizacoes' => ['estante_id', 'capacidade_estimada'],
        'emprestimos' => ['conservacao_devolucao'],
        'inventarios' => ['tipo'],
        'backups' => ['restauracao_testada_em'],
    ];

    public const ROTULOS = ['ok' => 'Normal', 'aviso' => 'Atenção', 'erro' => 'Problema', 'nao_verificado' => 'Não verificado'];

    public function __construct(private ?PDO $pdo, private string $raiz, private string $pastaBackup)
    {
    }

    /** @return array<string, list<array{titulo: string, situacao: string, detalhe: string}>> seção => itens */
    public function verificar(): array
    {
        return [
            'Servidor e PHP' => $this->servidor(),
            'Banco de dados' => $this->banco(),
            'Migrações' => $this->migracoes(),
            'Estrutura e integridade' => $this->estrutura(),
            'Arquivos e cópias de segurança' => $this->arquivos(),
            'Erros técnicos recentes' => $this->erros(),
        ];
    }

    private function item(string $titulo, string $situacao, string $detalhe): array
    {
        return ['titulo' => $titulo, 'situacao' => $situacao, 'detalhe' => $detalhe];
    }

    private function servidor(): array
    {
        $i = [];
        $ok = version_compare(PHP_VERSION, '8.2.0', '>=');
        $i[] = $this->item('Compatibilidade com o PHP', $ok ? 'ok' : 'erro', 'PHP ' . PHP_VERSION . ($ok ? ': compatível.' : ': o sistema precisa do PHP 8.2 ou mais novo.'));
        foreach (DiagnosticoSistema::EXTENSOES as $ext => $uso) {
            $tem = extension_loaded($ext);
            $i[] = $this->item('Extensão ' . $ext, $tem ? 'ok' : ($ext === 'zip' || $ext === 'fileinfo' ? 'aviso' : 'erro'), $tem ? 'Ativa (' . $uso . ').' : 'Desativada: ative no php.ini (' . $uso . ').');
        }
        $limite = ini_get('memory_limit');
        $i[] = $this->item('Memória do PHP', 'ok', 'memory_limit = ' . $limite . '; max_execution_time = ' . ini_get('max_execution_time') . 's.');
        return $i;
    }

    private function banco(): array
    {
        if (!$this->pdo) {
            return [$this->item('Conexão com o banco', 'erro', 'Não foi possível conectar. Confira se o MySQL/MariaDB está ligado (painel do XAMPP) e os dados do .env. As demais verificações abaixo continuam valendo.')];
        }
        $i = [];
        try {
            $this->pdo->query('SELECT 1')->fetchColumn();
            $versao = (string)$this->pdo->query('SELECT VERSION()')->fetchColumn();
            $maria = stripos($versao, 'mariadb') !== false;
            $num = preg_replace('/-.*$/', '', $versao);
            $i[] = $this->item('Conexão com o banco', 'ok', 'Normal.');
            $compat = $maria ? version_compare($num, '10.4.0', '>=') : version_compare($num, '8.0.0', '>=');
            $i[] = $this->item('Versão do ' . ($maria ? 'MariaDB' : 'MySQL'), $compat ? 'ok' : 'erro', $num . ($compat ? ': compatível.' : ': o sistema precisa do MariaDB 10.4+ ou MySQL 8.0+.'));
            $charset = (string)$this->pdo->query('SELECT @@character_set_database')->fetchColumn();
            $i[] = $this->item('Codificação do banco', str_starts_with($charset, 'utf8mb4') ? 'ok' : 'aviso', str_starts_with($charset, 'utf8mb4') ? 'utf8mb4 (acentos preservados).' : $charset . ': o recomendado é utf8mb4.');
            $pacote = (int)$this->pdo->query('SELECT @@max_allowed_packet')->fetchColumn();
            $espera = (int)$this->pdo->query('SELECT @@global.wait_timeout')->fetchColumn();
            $i[] = $this->item('Limites da conexão', $pacote >= 16 * 1048576 ? 'ok' : 'aviso', 'max_allowed_packet = ' . round($pacote / 1048576) . ' MB, wait_timeout = ' . $espera . ' s.'
                . ($pacote < 16 * 1048576 ? ' Valores baixos podem causar "MySQL server has gone away" em cópias e migrações: use pelo menos 16M no my.ini.' : ''));
            $priv = Migracoes::privilegios($this->pdo);
            $i[] = $this->item('Permissões do usuário do banco', !$priv['verificado'] ? 'nao_verificado' : ($priv['faltando'] ? 'erro' : 'ok'),
                !$priv['verificado'] ? $priv['detalhe'] : ($priv['faltando'] ? 'Permissão necessária não encontrada: ' . implode(', ', $priv['faltando']) . '.' : 'Todas as permissões necessárias estão presentes.'));
            // Tabelas do sistema MySQL corrompidas já fizeram o usuário perder permissões (erro 1142).
            try {
                $tam = (int)$this->pdo->query('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE table_schema = DATABASE()')->fetchColumn();
                $i[] = $this->item('Tamanho do banco', 'ok', number_format($tam / 1048576, 1, ',', '.') . ' MB.');
            } catch (Throwable) {
            }
        } catch (Throwable $e) {
            $i[] = $this->item('Conexão com o banco', 'erro', Migracoes::conexaoPerdida($e) ? 'A conexão foi interrompida durante a verificação ("server has gone away").' : 'O banco não respondeu à consulta de teste.');
        }
        return $i;
    }

    private function migracoes(): array
    {
        $arquivos = array_map('basename', glob($this->raiz . '/database/migrations/[0-9]*_*.sql') ?: []);
        usort($arquivos, 'strnatcmp');
        if (!$this->pdo) {
            return [$this->item('Migrações', 'nao_verificado', count($arquivos) . ' arquivo(s) de migração. Sem conexão com o banco, não foi possível conferir quais foram aplicadas.')];
        }
        try {
            $aplicadas = $this->pdo->query('SELECT arquivo FROM schema_migracoes')->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable) {
            return [$this->item('Migrações', 'erro', 'A tabela de controle das migrações não existe: rode C:\\xampp\\php\\php.exe database/migrar.php.')];
        }
        $pendentes = array_values(array_diff($arquivos, $aplicadas));
        $i = [$this->item('Migrações pendentes', $pendentes ? 'aviso' : 'ok', $pendentes
            ? count($pendentes) . ' pendente(s): ' . implode(', ', $pendentes) . '. Faça uma cópia de segurança e rode C:\\xampp\\php\\php.exe database/migrar.php.'
            : 'Nenhuma: todas as ' . count($arquivos) . ' migrações foram aplicadas.')];
        foreach ($pendentes as $p) {
            try {
                $s = Migracoes::situacaoPendente($this->pdo, (string)file_get_contents($this->raiz . '/database/migrations/' . $p));
                if ($s !== 'nao_iniciada') {
                    $i[] = $this->item('Migração ' . $p, 'aviso', $s === 'parcial' ? 'Parcialmente aplicada (parte da estrutura já existe). Rodar o migrar.php de novo confere a estrutura real e conclui com segurança.'
                        : 'A estrutura já existe, mas a migração não foi registrada como concluída. Rode o migrar.php de novo.');
                }
            } catch (Throwable) {
            }
        }
        try {
            if ($this->pdo->query("SHOW TABLES LIKE 'schema_migracoes_execucoes'")->fetchColumn()) {
                $falhas = $this->pdo->query("SELECT e.arquivo, e.situacao, e.erro, e.iniciado_em FROM schema_migracoes_execucoes e
                                             JOIN (SELECT arquivo, MAX(id) AS id FROM schema_migracoes_execucoes GROUP BY arquivo) u ON u.id = e.id
                                             WHERE e.situacao <> 'concluida' ORDER BY e.id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($falhas as $f) {
                    if (!in_array($f['arquivo'], $aplicadas, true)) {
                        $i[] = $this->item('Tentativa interrompida: ' . $f['arquivo'], 'aviso', ($f['situacao'] === 'interrompida' ? 'Conexão perdida' : 'Falhou') . ' em ' . date('d/m/Y H:i', strtotime($f['iniciado_em'])) . ($f['erro'] ? ' — ' . $f['erro'] : '') . '.');
                    }
                }
            }
        } catch (Throwable) {
        }
        return $i;
    }

    private function estrutura(): array
    {
        if (!$this->pdo) {
            return [$this->item('Tabelas e colunas', 'nao_verificado', 'Sem conexão com o banco.')];
        }
        $i = [];
        try {
            $existentes = array_map('strtolower', $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
            $faltam = array_values(array_diff(self::TABELAS, $existentes));
            $i[] = $this->item('Tabelas necessárias', $faltam ? 'erro' : 'ok', $faltam ? 'Faltam: ' . implode(', ', $faltam) . '. Aplique as migrações pendentes.' : count(self::TABELAS) . ' tabelas conferidas.');
            $faltaColuna = [];
            foreach (self::COLUNAS as $t => $cols) {
                if (!in_array($t, $existentes, true)) {
                    continue;
                }
                foreach ($cols as $c) {
                    if (!Migracoes::colunaExiste($this->pdo, $t, $c)) {
                        $faltaColuna[] = $t . '.' . $c;
                    }
                }
            }
            $i[] = $this->item('Colunas necessárias', $faltaColuna ? 'erro' : 'ok', $faltaColuna ? 'Faltam: ' . implode(', ', $faltaColuna) . '.' : 'Conferidas.');
            $gatilhos = (int)$this->pdo->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()')->fetchColumn();
            $essenciais = ['exemplares_preservar_identidade', 'exemplares_sem_exclusao', 'exemplares_codigo_novo', 'emprestimos_sem_exclusao'];
            $temEss = (int)$this->pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME IN ('" . implode("','", $essenciais) . "')")->fetchColumn();
            $i[] = $this->item('Proteções do banco (gatilhos)', $temEss === count($essenciais) ? 'ok' : 'erro', $gatilhos . ' gatilho(s); ' . ($temEss === count($essenciais) ? 'os que protegem códigos BDP, exemplares e empréstimos estão ativos.' : 'falta algum gatilho de proteção dos exemplares ou empréstimos.'));
            if (in_array('exemplares', $existentes, true)) {
                $seq = $this->pdo->query("SELECT (SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1) AS ultimo,
                                                  (SELECT MAX(CAST(SUBSTRING(codigo_individual, 5) AS UNSIGNED)) FROM exemplares WHERE codigo_individual REGEXP '^BDP-[0-9]{6}$') AS maior")->fetch(PDO::FETCH_ASSOC);
                $okSeq = (int)$seq['maior'] <= (int)$seq['ultimo'];
                $i[] = $this->item('Sequência dos códigos BDP', $okSeq ? 'ok' : 'erro', $okSeq ? 'Último número reservado: ' . (int)$seq['ultimo'] . ' (nenhum código será repetido).' : 'A sequência está atrás do maior código usado: avise a manutenção.');
                $contra = (int)$this->pdo->query("SELECT COUNT(*) FROM exemplares e LEFT JOIN emprestimos m ON m.exemplar_id = e.id AND m.data_devolucao IS NULL
                                                  WHERE (e.situacao = 'emprestado' AND m.id IS NULL) OR (e.situacao <> 'emprestado' AND m.id IS NOT NULL)")->fetchColumn();
                $i[] = $this->item('Integridade dos relacionamentos', $contra ? 'aviso' : 'ok', $contra ? $contra . ' exemplar(es) com situação diferente dos empréstimos. Veja a Central de Qualidade.' : 'Exemplares e empréstimos consistentes.');
            }
        } catch (Throwable $e) {
            $i[] = $this->item('Estrutura do banco', 'nao_verificado', 'Não foi possível concluir a verificação (' . RegistroErros::codigo($e) . ').');
        }
        return $i;
    }

    private function arquivos(): array
    {
        $i = [];
        foreach (['storage/importacoes' => 'planilhas enviadas', 'uploads/capas' => 'capas dos livros', 'storage/logs' => 'registro técnico de erros'] as $pasta => $uso) {
            $c = $this->raiz . '/' . $pasta;
            if (!is_dir($c) && $pasta === 'storage/logs') {
                $i[] = $this->item('Pasta ' . $pasta, 'aviso', 'Ainda não existe; é criada no primeiro erro registrado.');
                continue;
            }
            $ok = is_dir($c) && is_writable($c);
            $i[] = $this->item('Pasta ' . $pasta, $ok ? 'ok' : 'erro', $ok ? 'Gravável (' . $uso . ').' : 'Sem permissão de gravação ou inexistente (' . $uso . ').');
        }
        $okBackup = is_dir($this->pastaBackup) && is_writable($this->pastaBackup);
        $i[] = $this->item('Pasta de backups', $okBackup ? 'ok' : 'erro', $okBackup ? 'Gravável.' : 'Sem permissão de gravação ou inexistente: as cópias de segurança não podem ser salvas.');
        $protegidas = 0;
        foreach (['storage/.htaccess', 'storage/importacoes/.htaccess', 'config/.htaccess', 'src/.htaccess', 'database/.htaccess'] as $ht) {
            $protegidas += is_file($this->raiz . '/' . $ht) ? 1 : 0;
        }
        $backupProt = is_file(rtrim($this->pastaBackup, '/\\') . '/.htaccess') || !str_starts_with(realpath($this->pastaBackup) ?: $this->pastaBackup, realpath($this->raiz) ?: $this->raiz);
        $i[] = $this->item('Proteção contra acesso público', $protegidas === 5 && $backupProt ? 'ok' : 'erro', $protegidas === 5 && $backupProt
            ? 'Pastas internas, planilhas e cópias de segurança bloqueadas para o navegador (.htaccess).' : 'Falta algum .htaccess de proteção (storage, config, src, database ou backups).');
        $livre = @disk_free_space($this->pastaBackup) ?: @disk_free_space($this->raiz);
        $total = @disk_total_space($this->raiz);
        if ($livre === false || $livre === null) {
            $i[] = $this->item('Espaço em disco', 'nao_verificado', 'A hospedagem não informa o espaço livre.');
        } else {
            $gb = $livre / 1073741824;
            $i[] = $this->item('Espaço em disco', $gb < 1 ? 'erro' : ($gb < 5 ? 'aviso' : 'ok'), number_format($gb, 1, ',', '.') . ' GB livres' . ($total ? ' de ' . number_format($total / 1073741824, 0, ',', '.') . ' GB' : '') . '.');
        }
        // Situação das cópias de segurança.
        if (!$this->pdo) {
            $arquivos = glob(rtrim($this->pastaBackup, '/\\') . '/biblioteca-dom-pedro-ii-*.sql') ?: [];
            $ultimo = $arquivos ? max(array_map('filemtime', $arquivos)) : null;
            $i[] = $this->item('Backup', $ultimo ? 'nao_verificado' : 'aviso', $ultimo ? 'Arquivo mais recente na pasta: ' . date('d/m/Y H:i', $ultimo) . ' (sem banco, não foi possível conferir o registro).' : 'Nenhum arquivo de cópia na pasta.');
            return $i;
        }
        try {
            $b = $this->pdo->query("SELECT * FROM backups WHERE origem <> 'download' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if (!$b) {
                $i[] = $this->item('Backup', 'aviso', 'Nenhuma cópia salva no servidor ainda. Gere uma em Manutenção e backups.');
            } else {
                $dias = (int)floor((time() - strtotime($b['criado_em'])) / 86400);
                $existe = is_file(rtrim($this->pastaBackup, '/\\') . DIRECTORY_SEPARATOR . basename((string)$b['arquivo']));
                $i[] = $this->item('Backup', !$existe ? 'erro' : ($dias > 7 ? 'aviso' : 'ok'), 'Última cópia realizada ' . ($dias === 0 ? 'hoje' : 'há ' . $dias . ' dia(s)') . ' (' . date('d/m/Y H:i', strtotime($b['criado_em'])) . ')'
                    . ($existe ? ', ' . number_format((int)$b['tamanho'] / 1048576, 2, ',', '.') . ' MB, ' . ((int)$b['verificado'] ? 'conferida' : 'não conferida') : ', mas o arquivo NÃO está mais na pasta') . '.');
                if (array_key_exists('restauracao_testada_em', $b)) {
                    $t = $this->pdo->query('SELECT restauracao_testada_em, restauracao_ok, restauracao_detalhe FROM backups WHERE restauracao_testada_em IS NOT NULL ORDER BY restauracao_testada_em DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
                    $i[] = $this->item('Teste de recuperação', !$t ? 'aviso' : ($t['restauracao_ok'] === null ? 'nao_verificado' : ((int)$t['restauracao_ok'] ? 'ok' : 'erro')),
                        !$t ? 'Nenhuma cópia foi testada ainda. Use “Testar recuperação” em Manutenção e backups.' : date('d/m/Y H:i', strtotime($t['restauracao_testada_em'])) . ': ' . $t['restauracao_detalhe']);
                }
            }
        } catch (Throwable) {
            $i[] = $this->item('Backup', 'nao_verificado', 'O registro das cópias ainda não existe (migração 150).');
        }
        return $i;
    }

    private function erros(): array
    {
        $recentes = RegistroErros::recentes(10);
        $semana = RegistroErros::contarRecentes(7);
        $conexao = count(array_filter($recentes, fn ($r) => !empty($r['conexao_perdida'])));
        return [$this->item('Erros técnicos nos últimos 7 dias', $semana === 0 ? 'ok' : ($semana > 20 ? 'erro' : 'aviso'), $semana === 0 ? 'Nenhum erro registrado.'
            : $semana . ' erro(s) registrado(s)' . ($conexao ? ', ' . $conexao . ' por conexão perdida com o banco' : '') . '. Os mais recentes aparecem abaixo, com o código que a equipe vê na tela.')];
    }
}
