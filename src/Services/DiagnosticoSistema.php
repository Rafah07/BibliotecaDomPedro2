<?php

namespace Biblioteca\Services;

use PDO;
use Throwable;

/**
 * Verificações da página Sistema → Manutenção: versão do PHP, extensões, banco, migrações,
 * pastas e proteções. Só leitura: nada é alterado.
 *
 * Cada item: ['titulo' => ..., 'situacao' => 'ok'|'aviso'|'erro', 'detalhe' => ...].
 * Os detalhes nunca trazem senhas nem caminhos completos do servidor.
 */
final class DiagnosticoSistema
{
    public const EXTENSOES = [
        'pdo_mysql' => 'conexão com o MySQL',
        'mbstring' => 'textos com acentos',
        'zip' => 'leitura das planilhas .xlsx e exportação XLSX',
        'fileinfo' => 'conferência do tipo das planilhas enviadas',
    ];

    /** Tabelas cujos totais aparecem como "dados preservados". */
    public const TABELAS_DADOS = [
        'livros' => 'Livros',
        'exemplares' => 'Exemplares físicos',
        'alunos' => 'Alunos',
        'emprestimos' => 'Empréstimos (histórico completo)',
        'administradores' => 'Contas do painel',
        'importacoes' => 'Importações de planilhas',
        'inventarios' => 'Inventários',
        'acoes_administrativas' => 'Ações administrativas registradas',
    ];

    public function __construct(private PDO $pdo, private string $raiz)
    {
    }

    /** @return list<array{titulo: string, situacao: string, detalhe: string}> */
    public function verificar(string $ambiente, bool $prazoProvisorio, bool $siteManutencao): array
    {
        $itens = [];
        $item = function (string $titulo, string $situacao, string $detalhe) use (&$itens) {
            $itens[] = ['titulo' => $titulo, 'situacao' => $situacao, 'detalhe' => $detalhe];
        };

        $item('Versão do PHP', version_compare(PHP_VERSION, '8.2.0', '>=') ? 'ok' : 'erro',
            'PHP ' . PHP_VERSION . (version_compare(PHP_VERSION, '8.2.0', '>=') ? '' : ' — o sistema precisa do PHP 8.2 ou mais novo.'));
        foreach (self::EXTENSOES as $ext => $uso) {
            $item('Extensão ' . $ext, extension_loaded($ext) ? 'ok' : 'erro', extension_loaded($ext) ? 'Ativa (' . $uso . ').' : 'Desativada: ative no php.ini do XAMPP (' . $uso . ').');
        }

        $item('Arquivo .env', is_file($this->raiz . '/.env') ? 'ok' : 'aviso',
            is_file($this->raiz . '/.env') ? 'Encontrado na raiz do projeto.' : 'Não encontrado: a configuração vem das variáveis do servidor.');
        if ($ambiente === 'production') {
            $exibe = filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOL);
            $item('Ambiente', $exibe ? 'aviso' : 'ok', 'Produção' . ($exibe ? ' — mas display_errors está ligado no PHP.' : '; erros vão só para o log do servidor.'));
        } else {
            $item('Ambiente', 'aviso', 'Desenvolvimento. No computador da biblioteca, use APP_ENV=production no .env.');
        }

        try {
            $versao = (string)$this->pdo->query('SELECT VERSION()')->fetchColumn();
            $charset = (string)$this->pdo->query('SELECT @@character_set_database')->fetchColumn();
            $item('Banco de dados', 'ok', (stripos($versao, 'mariadb') !== false ? 'MariaDB ' : 'MySQL ') . preg_replace('/-.*$/', '', $versao) . ' · conexão funcionando.');
            $item('Codificação do banco', str_starts_with($charset, 'utf8mb4') ? 'ok' : 'aviso',
                str_starts_with($charset, 'utf8mb4') ? 'utf8mb4 (acentos e símbolos preservados).' : $charset . ' — o recomendado é utf8mb4.');
        } catch (Throwable $e) {
            $item('Banco de dados', 'erro', 'Não foi possível consultar o banco.');
        }

        $pendentes = $this->migracoesPendentes();
        $item('Migrações do banco', $pendentes ? 'aviso' : 'ok', $pendentes
            ? count($pendentes) . ' pendente(s): ' . implode(', ', $pendentes) . '. Faça uma cópia de segurança e rode php database/migrar.php.'
            : 'Todas aplicadas.');

        foreach (['storage/importacoes' => 'planilhas enviadas', 'uploads/capas' => 'capas dos livros'] as $pasta => $uso) {
            $caminho = $this->raiz . '/' . $pasta;
            $ok = is_dir($caminho) && is_writable($caminho);
            $item('Pasta ' . $pasta, $ok ? 'ok' : 'erro', $ok ? 'Gravável (' . $uso . ').' : 'Sem permissão de gravação ou inexistente (' . $uso . ').');
        }
        $item('Proteção das planilhas', is_file($this->raiz . '/storage/importacoes/.htaccess') ? 'ok' : 'erro',
            is_file($this->raiz . '/storage/importacoes/.htaccess') ? 'storage/importacoes bloqueada para o navegador (.htaccess).' : 'Falta storage/importacoes/.htaccess: as planilhas poderiam ser baixadas pelo navegador.');

        $item('Prazo de empréstimo', $prazoProvisorio ? 'aviso' : 'ok', $prazoProvisorio
            ? 'Provisório (14 dias): a escola ainda não definiu. Ajuste em Sistema → Configurações.'
            : 'Definido pela biblioteca.');
        $item('Site público', $siteManutencao ? 'aviso' : 'ok', $siteManutencao ? 'Em manutenção: visitantes veem só a mensagem de manutenção.' : 'No ar.');
        return $itens;
    }

    /** @return list<string> nomes dos arquivos de migração ainda não aplicados */
    public function migracoesPendentes(): array
    {
        $arquivos = array_map('basename', glob($this->raiz . '/database/migrations/[0-9]*_*.sql') ?: []);
        usort($arquivos, 'strnatcmp');
        try {
            $aplicadas = $this->pdo->query('SELECT arquivo FROM schema_migracoes')->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return $arquivos;
        }
        return array_values(array_diff($arquivos, $aplicadas));
    }

    /** @return array<string, ?int> rótulo => total (null quando a tabela não existe) */
    public function totais(): array
    {
        $totais = [];
        foreach (self::TABELAS_DADOS as $tabela => $rotulo) {
            try {
                $totais[$rotulo] = (int)$this->pdo->query('SELECT COUNT(*) FROM `' . $tabela . '`')->fetchColumn();
            } catch (Throwable $e) {
                $totais[$rotulo] = null;
            }
        }
        return $totais;
    }

    /** Tamanho aproximado do banco em bytes. */
    public function tamanhoBanco(): ?int
    {
        try {
            return (int)$this->pdo->query('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE table_schema = DATABASE()')->fetchColumn();
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Tentativas de login com mais de $dias dias (podem ser apagadas: só servem para o bloqueio de 15 minutos). */
    public function tentativasAntigas(int $dias = 30): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM login_tentativas WHERE criado_em < NOW() - INTERVAL ' . max(1, $dias) . ' DAY')->fetchColumn();
    }

    public function limparTentativasAntigas(int $dias = 30): int
    {
        return $this->pdo->exec('DELETE FROM login_tentativas WHERE criado_em < NOW() - INTERVAL ' . max(1, $dias) . ' DAY') ?: 0;
    }
}
