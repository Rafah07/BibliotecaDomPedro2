<?php

namespace Biblioteca\Services;

use Biblioteca\Support\ConsultasPdo;
use Biblioteca\Support\Isbn;
use Biblioteca\Support\Texto;
use PDO;

/**
 * "Vincular a uma obra já verificada" (conferência do Inventário, migração 130).
 *
 * A planilha tem muitas linhas repetidas: a mesma obra pode ter virado vários cadastros na
 * importação. Para a equipe não verificar os mesmos dados bibliográficos várias vezes, uma obra
 * ainda NÃO verificada pode mostrar possíveis correspondências entre as obras JÁ verificadas.
 *
 * Regras:
 *   * São só SUGESTÕES (mesmo ISBN, mesmo título e autor, título parecido, mesma editora/ano/
 *     edição). Nada é unido automaticamente; diferenças de editora, ano, edição ou ISBN são
 *     mostradas como alerta de possível edição diferente. Na dúvida, mantenha separadas.
 *   * Só com a confirmação explícita de quem confere, as linhas da planilha escolhidas passam
 *     para a obra verificada (registros_legados.livro_id). A obra original fica guardada em
 *     livro_original_id e os dados originais da planilha não mudam (são imutáveis).
 *   * Linhas já ligadas a um exemplar não mudam de obra (o exemplar pertence à obra dele).
 *   * Se a obra de origem ficar sem nenhuma linha e sem exemplares, ela é ARQUIVADA (não
 *     excluída), para não aparecer como uma segunda obra desnecessária. Desfazer a vinculação
 *     devolve a linha e restaura a obra arquivada por ela.
 *   * Todo vínculo e toda correção ficam em registro_vinculos.
 */
final class VinculoObraService
{
    use ConsultasPdo;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Possíveis obras JÁ VERIFICADAS que correspondem à obra $livroId (ou ao texto $q).
     * @return list<array> com 'motivos' (por que é parecida) e 'diferencas' (o que não bate)
     */
    public function sugestoes(int $livroId, ?string $q = null, int $limite = 6): array
    {
        $o = $this->linha('SELECT l.id, l.titulo, l.isbn, l.isbn_original, l.editora, l.ano_publicacao, l.edicao,
                                  (SELECT r.titulo_original FROM registros_legados r WHERE r.livro_id = l.id AND r.titulo_original IS NOT NULL ORDER BY r.id LIMIT 1) AS titulo_planilha,
                                  (SELECT a.nome FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = l.id ORDER BY la.ordem, a.id LIMIT 1) AS autor
                           FROM livros l WHERE l.id = ?', [$livroId]);
        if (!$o) {
            return [];
        }
        $titulo = Texto::limpar($q) ?? Texto::limpar($o['titulo']) ?? Texto::limpar($o['titulo_planilha']);
        $conds = [];
        $params = [];
        $isbns = array_values(array_filter([$o['isbn'], $o['isbn'] && strlen($o['isbn']) === 10 ? Isbn::para13($o['isbn']) : null]));
        if ($q === null && $isbns) {
            $conds[] = 'l.isbn IN (' . implode(',', array_fill(0, count($isbns), '?')) . ')';
            array_push($params, ...$isbns);
        }
        if ($q === null && $o['isbn_original']) {
            $conds[] = 'l.isbn_original = ?';
            $params[] = $o['isbn_original'];
        }
        if ($titulo !== null && mb_strlen($titulo) >= 3) {
            // Primeiras palavras significativas do título (até 3), todas presentes.
            $palavras = array_slice(array_values(array_filter(preg_split('/[\s,.:;!?()"\'\-]+/u', $titulo), fn ($p) => mb_strlen($p) >= 3)), 0, 3) ?: [$titulo];
            $partes = [];
            foreach ($palavras as $p) {
                $partes[] = 'l.titulo LIKE ?';
                $params[] = '%' . addcslashes($p, '%_\\') . '%';
            }
            $conds[] = '(' . implode(' AND ', $partes) . ')';
        }
        if (!$conds) {
            return [];
        }
        $lista = $this->todas("SELECT l.id, COALESCE(l.titulo, 'Sem título (a conferir)') AS titulo, l.titulo AS titulo_cadastrado, l.subtitulo, l.isbn, l.isbn_original,
                                      l.editora, l.ano_publicacao, l.edicao, l.dados_verificados_em, vp.nome AS verificado_por_nome,
                                      (SELECT GROUP_CONCAT(a.nome ORDER BY la.ordem SEPARATOR '; ') FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = l.id) AS autores,
                                      (SELECT COUNT(*) FROM exemplares e WHERE e.livro_id = l.id AND e.situacao <> 'baixado') AS exemplares
                               FROM livros l LEFT JOIN administradores vp ON vp.id = l.dados_verificados_por
                               WHERE l.arquivado_em IS NULL AND l.dados_verificados_em IS NOT NULL AND l.id <> ? AND (" . implode(' OR ', $conds) . ')
                               ORDER BY l.id LIMIT 60', array_merge([$livroId], $params));
        $chave = Texto::chaveObra($titulo, $o['autor']);
        $norm = fn ($v) => $v === null ? null : mb_strtolower(trim((string)$v));
        foreach ($lista as &$l) {
            $motivos = [];
            $diferencas = [];
            $pontos = 0;
            if ($isbns && in_array($l['isbn'], $isbns, true)) {
                $motivos[] = 'Mesmo ISBN';
                $pontos += 6;
            } elseif ($o['isbn'] && $l['isbn'] && !in_array($l['isbn'], $isbns, true)) {
                $diferencas[] = 'ISBN diferente (' . $l['isbn'] . ')';
                $pontos -= 4;
            }
            if ($o['isbn_original'] && $l['isbn_original'] === $o['isbn_original']) {
                $motivos[] = 'Mesmo ISBN (como foi digitado)';
                $pontos += 3;
            }
            $primeiroAutor = explode('; ', (string)$l['autores'])[0] ?: null;
            if ($chave !== null && Texto::chaveObra($l['titulo_cadastrado'], $primeiroAutor) === $chave) {
                $motivos[] = 'Mesmo título e autor';
                $pontos += 4;
            } else {
                $motivos[] = 'Título parecido';
                $pontos += 1;
            }
            foreach (['editora' => 'Editora', 'ano_publicacao' => 'Ano', 'edicao' => 'Edição'] as $campo => $rotulo) {
                if ($o[$campo] !== null && $l[$campo] !== null) {
                    if ($norm($o[$campo]) === $norm($l[$campo])) {
                        $motivos[] = 'Mesm' . ($campo === 'ano_publicacao' ? 'o ' : 'a ') . mb_strtolower($rotulo);
                        $pontos += 1;
                    } else {
                        $diferencas[] = $rotulo . ' diferente (' . $l[$campo] . ')';
                        $pontos -= 2;
                    }
                }
            }
            $l['motivos'] = $motivos;
            $l['diferencas'] = $diferencas;
            $l['pontos'] = $pontos;
        }
        unset($l);
        usort($lista, fn ($a, $b) => [$b['pontos'], $a['id']] <=> [$a['pontos'], $b['id']]);
        return array_slice($lista, 0, $limite);
    }

    /**
     * Passa as linhas da planilha escolhidas da obra $origemId para a obra verificada $destinoId.
     * Exige a confirmação explícita ($confirmado). @return array{movidas: int, origem_arquivada: bool}
     */
    public function vincular(int $origemId, int $destinoId, array $registroIds, int $responsavelId, bool $confirmado, ?int $inventarioId = null, string $observacao = ''): array
    {
        if (!$confirmado) {
            throw new AcervoException('Confirme que o livro em mãos é a MESMA obra e a MESMA edição da obra verificada. Na dúvida, mantenha separadas.');
        }
        if ($origemId === $destinoId) {
            throw new AcervoException('Escolha uma obra verificada diferente desta.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $registroIds), fn ($v) => $v > 0)));
        if (!$ids) {
            throw new AcervoException('Escolha pelo menos uma linha da planilha para vincular.');
        }
        $obs = self::textoLimpo($observacao, 500);
        return $this->transacao(function () use ($origemId, $destinoId, $ids, $responsavelId, $inventarioId, $obs) {
            // Mesma ordem de travas para qualquer par de obras (evita impasse).
            $livros = [];
            foreach ([min($origemId, $destinoId), max($origemId, $destinoId)] as $lid) {
                $livros[$lid] = $this->linha('SELECT id, titulo, arquivado_em, dados_verificados_em, observacoes_internas FROM livros WHERE id = ? FOR UPDATE', [$lid]);
            }
            $origem = $livros[$origemId];
            $destino = $livros[$destinoId];
            if (!$origem || $origem['arquivado_em'] !== null) {
                throw new AcervoException('A obra de origem não foi encontrada ou está arquivada.');
            }
            if (!$destino || $destino['arquivado_em'] !== null) {
                throw new AcervoException('A obra escolhida não foi encontrada ou está arquivada.');
            }
            if ($destino['dados_verificados_em'] === null) {
                throw new AcervoException('Só é possível vincular a uma obra com "Dados verificados".');
            }
            $movidas = 0;
            foreach ($ids as $rid) {
                $reg = $this->linha('SELECT * FROM registros_legados WHERE id = ? FOR UPDATE', [$rid]);
                if (!$reg || (int)$reg['livro_id'] !== $origemId) {
                    throw new AcervoException('Uma das linhas escolhidas não pertence a esta obra. Atualize a página e tente de novo.');
                }
                if ($reg['exemplar_id'] !== null) {
                    throw new AcervoException('A linha ' . (int)$reg['linha'] . ' da planilha já está ligada a um exemplar físico desta obra e não muda de obra.');
                }
                $this->executar('UPDATE registros_legados SET livro_id = ?, livro_original_id = COALESCE(livro_original_id, ?) WHERE id = ?', [$destinoId, $origemId, $rid]);
                $this->moverItensAbertos((int)$reg['importacao_item_id'], $destinoId);
                $this->executar('INSERT INTO registro_vinculos (registro_id, acao, livro_anterior_id, livro_novo_id, inventario_id, observacao, administrador_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$rid, 'vincular_obra', $origemId, $destinoId, $inventarioId, $obs, $responsavelId]);
                $movidas++;
            }
            $arquivada = false;
            $restam = (int)$this->valor('SELECT COUNT(*) FROM registros_legados WHERE livro_id = ?', [$origemId])
                + (int)$this->valor('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$origemId]);
            if ($restam === 0) {
                $nota = date('d/m/Y H:i') . ': arquivada porque os registros da planilha foram vinculados à obra verificada nº ' . $destinoId . '.';
                $this->executar('UPDATE livros SET arquivado_em = NOW(), observacoes_internas = TRIM(CONCAT(COALESCE(observacoes_internas, \'\'), \'\n\', ?)) WHERE id = ?', [$nota, $origemId]);
                $this->registrarAcao($responsavelId, 'livro.arquivar_vinculo', 'livro', $origemId, ['destino' => $destinoId]);
                $arquivada = true;
            }
            $this->registrarAcao($responsavelId, 'livro.vincular_obra', 'livro', $destinoId, ['origem' => $origemId, 'registros' => $ids, 'origem_arquivada' => $arquivada, 'inventario_id' => $inventarioId]);
            return ['movidas' => $movidas, 'origem_arquivada' => $arquivada];
        });
    }

    /**
     * Desfaz a vinculação de uma linha: ela volta para a obra original (que é restaurada se tiver
     * sido arquivada pela vinculação). Só para linhas ainda sem exemplar. @return int obra original
     */
    public function desfazer(int $registroId, int $responsavelId, string $motivo, ?int $inventarioId = null): int
    {
        $motivo = self::textoLimpo($motivo, 500);
        if ($motivo === null) {
            throw new AcervoException('Informe o motivo para desfazer a vinculação.');
        }
        return $this->transacao(function () use ($registroId, $responsavelId, $motivo, $inventarioId) {
            $reg = $this->linha('SELECT * FROM registros_legados WHERE id = ? FOR UPDATE', [$registroId]);
            if (!$reg || $reg['livro_original_id'] === null) {
                throw new AcervoException('Esta linha da planilha não foi vinculada a outra obra.');
            }
            if ($reg['exemplar_id'] !== null) {
                throw new AcervoException('Esta linha já está ligada a um exemplar. Desfaça primeiro o vínculo com o exemplar.');
            }
            $original = (int)$reg['livro_original_id'];
            $atual = (int)$reg['livro_id'];
            $livro = $this->linha('SELECT id, arquivado_em FROM livros WHERE id = ? FOR UPDATE', [$original]);
            if ($livro && $livro['arquivado_em'] !== null
                && $this->valor("SELECT 1 FROM acoes_administrativas WHERE entidade = 'livro' AND entidade_id = ? AND acao = 'livro.arquivar_vinculo' LIMIT 1", [$original])) {
                $this->executar('UPDATE livros SET arquivado_em = NULL WHERE id = ?', [$original]);
                $this->registrarAcao($responsavelId, 'livro.restaurar_vinculo', 'livro', $original, ['registro_id' => $registroId]);
            }
            $this->executar('UPDATE registros_legados SET livro_id = ?, livro_original_id = NULL WHERE id = ?', [$original, $registroId]);
            $this->moverItensAbertos((int)$reg['importacao_item_id'], $original);
            $this->executar('INSERT INTO registro_vinculos (registro_id, acao, livro_anterior_id, livro_novo_id, inventario_id, observacao, administrador_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$registroId, 'desfazer_obra', $atual, $original, $inventarioId, $motivo, $responsavelId]);
            $this->registrarAcao($responsavelId, 'livro.desfazer_vinculo_obra', 'livro', $atual, ['registro_id' => $registroId, 'volta_para' => $original, 'motivo' => $motivo]);
            return $original;
        });
    }

    /** Histórico de vínculos das linhas que estão (ou estiveram) nesta obra. */
    public function historico(int $livroId, int $limite = 30): array
    {
        return $this->todas('SELECT v.*, r.linha, r.arquivo, a.nome AS administrador_nome, e.codigo_individual,
                                    COALESCE(la.titulo, \'Sem título (a conferir)\') AS livro_anterior_titulo, COALESCE(ln.titulo, \'Sem título (a conferir)\') AS livro_novo_titulo
                             FROM registro_vinculos v
                             JOIN registros_legados r ON r.id = v.registro_id
                             LEFT JOIN administradores a ON a.id = v.administrador_id
                             LEFT JOIN exemplares e ON e.id = v.exemplar_id
                             LEFT JOIN livros la ON la.id = v.livro_anterior_id
                             LEFT JOIN livros ln ON ln.id = v.livro_novo_id
                             WHERE v.livro_novo_id = ? OR v.livro_anterior_id = ?
                             ORDER BY v.criado_em DESC, v.id DESC LIMIT ' . max(1, $limite), [$livroId, $livroId]);
    }

    /** Texto curto de uma linha do histórico de vínculos. */
    public static function descrever(array $h): string
    {
        $linha = 'Linha ' . (int)$h['linha'] . ' da planilha';
        return match ($h['acao']) {
            'vincular_exemplar' => $linha . ' ligada ao exemplar ' . ($h['codigo_individual'] ?? ''),
            'desfazer_exemplar' => $linha . ': vínculo com o exemplar ' . ($h['codigo_individual'] ?? '') . ' desfeito',
            'vincular_obra' => $linha . ' vinculada à obra verificada “' . $h['livro_novo_titulo'] . '” (vinha de “' . $h['livro_anterior_titulo'] . '”)',
            'desfazer_obra' => $linha . ' devolvida para a obra “' . $h['livro_novo_titulo'] . '”',
            default => $linha,
        };
    }

    /** Itens AINDA SEM EXEMPLAR desta linha nos inventários em andamento/pausados passam para a obra $livroId. */
    private function moverItensAbertos(int $importacaoItemId, int $livroId): void
    {
        $this->executar("UPDATE inventario_itens i JOIN inventarios v ON v.id = i.inventario_id AND v.status IN ('em_andamento', 'pausado')
                         SET i.livro_id = ? WHERE i.importacao_item_id = ? AND i.exemplar_id IS NULL", [$livroId, $importacaoItemId]);
    }
}
