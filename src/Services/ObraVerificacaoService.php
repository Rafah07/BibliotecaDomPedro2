<?php

namespace Biblioteca\Services;

use Biblioteca\Support\ConsultasPdo;
use PDO;

/**
 * Status de verificação dos dados bibliográficos de uma OBRA (tabela livros).
 *
 *   Dados não verificados  livros.dados_verificados_em vazio (todos os registros importados começam assim)
 *   Dados verificados      alguém da equipe confirmou explicitamente que conferiu as informações
 *
 * Regras:
 *   * O status só vira "Dados verificados" pela ação explícita "Salvar e marcar dados como
 *     verificados". Campos ausentes (ISBN, editora...) podem continuar vazios depois da verificação.
 *   * Uma obra verificada pode ser editada depois. Toda alteração fica no histórico
 *     (livro_verificacoes, com o valor antes e depois). Se mudar uma informação bibliográfica
 *     RELEVANTE (título, subtítulo, autor, ISBN, editora, ano ou edição) sem marcar de novo como
 *     verificada, a obra volta para "Dados não verificados" e pode ser verificada outra vez.
 *     Mudanças só de categoria, assunto ou idioma mantêm a verificação.
 *   * A verificação é da obra, nunca do exemplar: o código BDP e a situação no inventário
 *     pertencem a cada exemplar físico.
 */
final class ObraVerificacaoService
{
    use ConsultasPdo;

    /** Campos cuja alteração exige uma nova verificação. */
    public const CAMPOS_RELEVANTES = ['titulo', 'subtitulo', 'autores', 'isbn', 'isbn_original', 'editora', 'ano_publicacao', 'edicao'];

    public const ROTULOS = [
        'titulo' => 'Título', 'subtitulo' => 'Subtítulo', 'autores' => 'Autor(es)', 'isbn' => 'ISBN', 'isbn_original' => 'ISBN informado',
        'editora' => 'Editora', 'ano_publicacao' => 'Ano', 'edicao' => 'Edição', 'idioma' => 'Idioma', 'assunto' => 'Assunto',
        'categoria' => 'Categoria', 'numero_paginas' => 'Páginas', 'sinopse' => 'Sinopse', 'palavras_chave' => 'Palavras-chave',
        'observacoes_internas' => 'Observações internas', 'status_validacao' => 'Situação do cadastro',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /** Rótulo do status para a interface. */
    public static function rotulo(?string $verificadoEm): string
    {
        return $verificadoEm !== null && $verificadoEm !== '' ? 'Dados verificados' : 'Dados não verificados';
    }

    /**
     * Registra uma gravação dos dados da obra (deve ser chamado DENTRO da transação que gravou).
     * $alteracoes: [campo => [antes, depois]]. $marcarVerificado: a pessoa clicou em
     * "Salvar e marcar dados como verificados".
     * @return string status final: 'verificado' | 'nao_verificado'
     */
    public function registrar(int $livroId, array $alteracoes, ?int $responsavelId, bool $marcarVerificado, string $origem = 'inventario', ?int $inventarioId = null): string
    {
        $atual = $this->linha('SELECT dados_verificados_em FROM livros WHERE id = ? FOR UPDATE', [$livroId]);
        if (!$atual) {
            throw new AcervoException('Obra não encontrada.');
        }
        $antes = $atual['dados_verificados_em'] !== null ? 'verificado' : 'nao_verificado';
        $json = $alteracoes ? json_encode(array_map(fn ($p) => ['antes' => $p[0] ?? null, 'depois' => $p[1] ?? null], $alteracoes), JSON_UNESCAPED_UNICODE) : null;
        $historico = 'INSERT INTO livro_verificacoes (livro_id, acao, status_anterior, status_novo, alteracoes, origem, inventario_id, administrador_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';

        if ($marcarVerificado) {
            $this->executar('UPDATE livros SET dados_verificados_em = NOW(), dados_verificados_por = ? WHERE id = ?', [$responsavelId, $livroId]);
            $this->executar($historico, [$livroId, 'verificado', $antes, 'verificado', $json, $origem, $inventarioId, $responsavelId]);
            $this->registrarAcao($responsavelId, 'livro.verificar', 'livro', $livroId, ['origem' => $origem, 'campos' => array_keys($alteracoes)]);
            return 'verificado';
        }
        if (!$alteracoes) {
            return $antes;
        }
        $relevantes = array_values(array_intersect(array_keys($alteracoes), self::CAMPOS_RELEVANTES));
        if ($antes === 'verificado' && $relevantes) {
            $this->executar('UPDATE livros SET dados_verificados_em = NULL, dados_verificados_por = NULL WHERE id = ?', [$livroId]);
            $this->executar($historico, [$livroId, 'verificacao_retirada', 'verificado', 'nao_verificado', $json, $origem, $inventarioId, $responsavelId]);
            $this->registrarAcao($responsavelId, 'livro.verificacao_retirada', 'livro', $livroId, ['origem' => $origem, 'campos' => $relevantes]);
            return 'nao_verificado';
        }
        $this->executar($historico, [$livroId, 'alterado', $antes, $antes, $json, $origem, $inventarioId, $responsavelId]);
        return $antes;
    }

    /** Histórico de verificação da obra (mais recente primeiro). */
    public function historico(int $livroId, int $limite = 30): array
    {
        $linhas = $this->todas('SELECT v.*, a.nome AS administrador_nome FROM livro_verificacoes v LEFT JOIN administradores a ON a.id = v.administrador_id
                                WHERE v.livro_id = ? ORDER BY v.criado_em DESC, v.id DESC LIMIT ' . max(1, $limite), [$livroId]);
        foreach ($linhas as &$l) {
            $l['alteracoes'] = json_decode((string)$l['alteracoes'], true) ?: [];
        }
        unset($l);
        return $linhas;
    }

    /** Texto curto de uma linha do histórico. */
    public static function descrever(array $h): string
    {
        $campos = implode(', ', array_map(fn ($c) => self::ROTULOS[$c] ?? $c, array_keys($h['alteracoes'] ?? [])));
        return match ($h['acao']) {
            'verificado' => $h['status_anterior'] === 'verificado' ? 'Verificada de novo' . ($campos ? ' (alterado: ' . $campos . ')' : '') : 'Dados marcados como verificados' . ($campos ? ' (alterado: ' . $campos . ')' : ''),
            'verificacao_retirada' => 'Alterada depois da verificação (' . $campos . '): voltou para "Dados não verificados"',
            default => 'Alterada' . ($campos ? ': ' . $campos : '') . ($h['status_novo'] === 'verificado' ? ' (continua verificada)' : ''),
        };
    }
}
