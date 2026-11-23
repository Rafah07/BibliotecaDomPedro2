<?php

namespace Biblioteca\Services;

use Biblioteca\Support\ConsultasPdo;
use PDO;

/**
 * Versões oficiais da Planilha BDP de cada lote (migração 190, tabela planilha_bdp_versoes).
 *
 *  - "Emitir versão" grava, no servidor, a cópia exata do que foi apresentado: ordem, código BDP,
 *    obra, título e autor de cada exemplar do lote. Versão 01, 02... por lote.
 *  - Versões emitidas nunca mudam (gatilhos impedem UPDATE e DELETE). Reimprimir uma versão antiga
 *    mostra exatamente o que foi impresso naquela data, mesmo que a obra tenha sido corrigida depois.
 *  - Uma versão fica DESATUALIZADA quando muda algo relevante para a carimbagem: título ou autor
 *    exibido, a obra associada a um exemplar, ou a composição/ordem do lote. Confirmar carimbo,
 *    mudar a localização ou só consultar de novo NÃO gera versão nova (não entram na assinatura).
 *  - Emitir de novo sem mudança relevante devolve a versão atual (não cria outra).
 *  - Os códigos BDP dos exemplares nunca mudam por causa de uma versão nova.
 */
final class PlanilhaBdpVersaoService
{
    use ConsultasPdo;

    /** Campos que, se mudarem, tornam uma versão emitida desatualizada. */
    public const CAMPOS_RELEVANTES = ['ordem', 'codigo', 'exemplar_id', 'livro_id', 'titulo', 'autores'];

    public const ROTULOS = ['ordem' => 'Ordem', 'codigo' => 'Código BDP', 'livro_id' => 'Obra associada', 'titulo' => 'Título', 'autores' => 'Autor'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Linhas atuais do lote, na ordem de cadastramento (dados bibliográficos atuais da obra).
     * @return list<array{exemplar_id: int, ordem: int, codigo: string, livro_id: int, titulo: string, autores: string, edicao: string, situacao: string}>
     */
    public function conteudoAtual(int $loteId): array
    {
        return array_map(fn ($r) => [
            'exemplar_id' => (int)$r['exemplar_id'], 'ordem' => (int)$r['ordem'], 'codigo' => (string)$r['codigo'], 'livro_id' => (int)$r['livro_id'],
            'titulo' => (string)$r['titulo'], 'autores' => (string)($r['autores'] ?? ''), 'edicao' => (string)($r['edicao'] ?? ''), 'situacao' => (string)$r['situacao'],
        ], $this->todas("SELECT e.id AS exemplar_id, e.lote_ordem AS ordem, e.codigo_individual AS codigo, e.livro_id, e.situacao,
                                l.titulo, TRIM(CONCAT_WS(' · ', NULLIF(l.edicao, ''), NULLIF(l.editora, ''), l.ano_publicacao)) AS edicao,
                                (SELECT GROUP_CONCAT(a.nome ORDER BY la.ordem SEPARATOR '; ') FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = l.id) AS autores
                         FROM exemplares e JOIN livros l ON l.id = e.livro_id
                         WHERE e.lote_id = ? ORDER BY e.lote_ordem, e.id", [$loteId]));
    }

    /** SHA-256 só dos campos relevantes para a carimbagem. */
    public static function assinatura(array $linhas): string
    {
        $base = array_map(fn ($l) => array_intersect_key($l, array_flip(self::CAMPOS_RELEVANTES)), $linhas);
        foreach ($base as &$b) {
            ksort($b);
        }
        unset($b);
        return hash('sha256', json_encode($base, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Emite a versão oficial do lote. Se nada relevante mudou desde a última, devolve a última
     * (reimpressão) sem criar outra. O número da versão é definido pelo servidor, com o lote bloqueado.
     * @return array{versao: array, nova: bool}
     */
    public function emitir(int $loteId, int $administradorId, string $motivo = ''): array
    {
        return $this->transacao(function () use ($loteId, $administradorId, $motivo) {
            $lote = $this->linha('SELECT id, numero FROM cadastro_lotes WHERE id = ? FOR UPDATE', [$loteId]);
            if (!$lote) {
                throw new AcervoException('Lote não encontrado.');
            }
            $linhas = $this->conteudoAtual($loteId);
            if (!$linhas) {
                throw new AcervoException('Este lote ainda não tem exemplares: não há planilha para emitir.');
            }
            $assinatura = self::assinatura($linhas);
            $ultima = $this->ultima($loteId);
            if ($ultima && hash_equals($ultima['assinatura'], $assinatura)) {
                return ['versao' => $ultima, 'nova' => false];
            }
            $motivo = self::textoLimpo($motivo, 500);
            if ($ultima && $motivo === null) {
                $dif = self::diferencas($ultima['linhas'], $linhas);
                $motivo = mb_substr('Atualização: ' . implode('; ', array_map(fn ($d) => $d['texto'], array_slice($dif, 0, 5))) . (count($dif) > 5 ? '; e mais ' . (count($dif) - 5) : ''), 0, 500);
            }
            $numero = $ultima ? (int)$ultima['versao'] + 1 : 1;
            $this->executar('INSERT INTO planilha_bdp_versoes (lote_id, versao, emitida_por, motivo, total, assinatura, conteudo) VALUES (?, ?, ?, ?, ?, ?, ?)', [
                $loteId, $numero, $administradorId, $motivo, count($linhas), $assinatura, json_encode($linhas, JSON_UNESCAPED_UNICODE),
            ]);
            $id = (int)$this->pdo->lastInsertId();
            $this->registrarAcao($administradorId, 'planilha_bdp.versao', 'lote', $loteId, ['versao' => $numero, 'total' => count($linhas), 'motivo' => $motivo]);
            return ['versao' => $this->versao($id), 'nova' => true];
        });
    }

    public function ultima(int $loteId): ?array
    {
        $id = $this->valor('SELECT id FROM planilha_bdp_versoes WHERE lote_id = ? ORDER BY versao DESC LIMIT 1', [$loteId]);
        return $id ? $this->versao((int)$id) : null;
    }

    public function versao(int $id): ?array
    {
        $v = $this->linha('SELECT v.*, a.nome AS emitida_por_nome, lt.numero AS lote_numero FROM planilha_bdp_versoes v
                           LEFT JOIN administradores a ON a.id = v.emitida_por JOIN cadastro_lotes lt ON lt.id = v.lote_id WHERE v.id = ?', [$id]);
        if (!$v) {
            return null;
        }
        $v['linhas'] = json_decode((string)$v['conteudo'], true) ?: [];
        unset($v['conteudo']);
        return $v;
    }

    /** Versões do lote (mais nova primeiro), sem o conteúdo. */
    public function versoes(int $loteId): array
    {
        return $this->todas('SELECT v.id, v.lote_id, v.versao, v.emitida_em, v.motivo, v.total, v.assinatura, a.nome AS emitida_por_nome
                             FROM planilha_bdp_versoes v LEFT JOIN administradores a ON a.id = v.emitida_por WHERE v.lote_id = ? ORDER BY v.versao DESC', [$loteId]);
    }

    /**
     * A última versão emitida ainda corresponde ao lote? (só UMA consulta pequena: o lote tem no máximo
     * a sua capacidade de exemplares).
     * @return array{ultima: ?array, desatualizada: bool, diferencas: list<array>}
     */
    public function situacao(int $loteId): array
    {
        $ultima = $this->ultima($loteId);
        if (!$ultima) {
            return ['ultima' => null, 'desatualizada' => false, 'diferencas' => []];
        }
        $atual = $this->conteudoAtual($loteId);
        if (hash_equals($ultima['assinatura'], self::assinatura($atual))) {
            return ['ultima' => $ultima, 'desatualizada' => false, 'diferencas' => []];
        }
        return ['ultima' => $ultima, 'desatualizada' => true, 'diferencas' => self::diferencas($ultima['linhas'], $atual)];
    }

    /**
     * Diferenças entre duas listas (antes → depois), por exemplar.
     * @return list<array{tipo: string, codigo: string, texto: string, fisico: bool}> fisico = muda a identificação física (carimbo/ordem)
     */
    public static function diferencas(array $antes, array $depois): array
    {
        $a = [];
        foreach ($antes as $l) {
            $a[(int)$l['exemplar_id']] = $l;
        }
        $d = [];
        foreach ($depois as $l) {
            $d[(int)$l['exemplar_id']] = $l;
        }
        $saida = [];
        foreach ($d as $id => $l) {
            if (!isset($a[$id])) {
                $saida[] = ['tipo' => 'incluido', 'codigo' => $l['codigo'], 'texto' => $l['codigo'] . ' incluído no lote (ordem ' . $l['ordem'] . ')', 'fisico' => true];
                continue;
            }
            $o = $a[$id];
            foreach (['ordem', 'codigo', 'livro_id', 'titulo', 'autores'] as $c) {
                if ((string)($o[$c] ?? '') !== (string)($l[$c] ?? '')) {
                    $saida[] = ['tipo' => 'alterado', 'codigo' => $l['codigo'],
                        'texto' => $l['codigo'] . ': ' . self::ROTULOS[$c] . ' “' . ($c === 'livro_id' ? 'obra nº ' . $o[$c] : $o[$c]) . '” → “' . ($c === 'livro_id' ? 'obra nº ' . $l[$c] : $l[$c]) . '”',
                        'fisico' => in_array($c, ['ordem', 'codigo', 'livro_id'], true)];
                }
            }
        }
        foreach ($a as $id => $l) {
            if (!isset($d[$id])) {
                $saida[] = ['tipo' => 'removido', 'codigo' => $l['codigo'], 'texto' => $l['codigo'] . ' não está mais no lote', 'fisico' => true];
            }
        }
        return $saida;
    }

    /** "Versão 01". */
    public static function rotulo(int|string $versao): string
    {
        return 'Versão ' . str_pad((string)(int)$versao, 2, '0', STR_PAD_LEFT);
    }
}
