<?php
/**
 * Testes do módulo de classificação CDD (sem rede).
 *
 * Uso: php admin/tests/cdd-teste.php
 * Com BDP_DB_* configurado, também testa o fluxo completo no MySQL, dentro de uma
 * transação desfeita no final (o banco não é alterado).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$_SERVER['SCRIPT_NAME'] = '/admin/tests/cdd-teste.php';
require __DIR__ . '/../includes/menu.php';
require __DIR__ . '/../includes/icones.php';
require __DIR__ . '/../includes/cdd.php';

$falhas = 0;
$total = 0;
function confere(string $nome, bool $ok, string $detalhe = ''): void
{
    global $falhas, $total;
    $total++;
    if (!$ok) {
        $falhas++;
    }
    echo ($ok ? "  ok  " : "  FALHOU  ") . $nome . ($ok || $detalhe === '' ? '' : " — $detalhe") . "\n";
}
function lanca(callable $fn): ?string
{
    try {
        $fn();
    } catch (CddErro $e) {
        return $e->getMessage();
    }
    return null;
}

echo "Validação de formato\n";
confere('869.3 é válida', cdd_validar('869.3')['valida']);
confere('028.5 preserva o zero à esquerda', cdd_validar('028.5')['numero'] === '028.5');
confere('Sem decimais é válida', cdd_validar('500')['valida']);
confere('Vírgula é recusada', !cdd_validar('869,3')['valida']);
confere('Dois dígitos são recusados', !cdd_validar('86.3')['valida']);
confere('Texto é recusado', !cdd_validar('LIT. BRAS.')['valida']);
confere('Prefixo de letra é recusado', !cdd_validar('B869.3')['valida']);
confere('Barra de segmentação é removida', cdd_validar('869.3/42')['numero'] === '869.342');
confere('Zero final gera aviso', count(array_filter(cdd_validar('869.30')['avisos'], fn ($a) => str_contains($a, 'zero'))) === 1);
confere('Classe principal de 028.5 é 0', cdd_classe_principal('028.5') === '0');

echo "Leitura de registros MARC (Library of Congress)\n";
$marc = <<<XML
<?xml version="1.0"?>
<zs:searchRetrieveResponse xmlns:zs="http://www.loc.gov/zing/srw/"><zs:records><zs:record><zs:recordData>
<record xmlns="http://www.loc.gov/MARC21/slim">
  <controlfield tag="001">123</controlfield>
  <datafield tag="010" ind1=" " ind2=" "><subfield code="a">  2001012345</subfield></datafield>
  <datafield tag="020" ind1=" " ind2=" "><subfield code="a">9780000000002 (pbk.)</subfield></datafield>
  <datafield tag="050" ind1="0" ind2="0"><subfield code="a">PQ9697.T</subfield><subfield code="b">A1 2001</subfield></datafield>
  <datafield tag="082" ind1="0" ind2="0"><subfield code="a">999.9/9</subfield><subfield code="2">23</subfield></datafield>
  <datafield tag="082" ind1="1" ind2="4"><subfield code="a">999</subfield><subfield code="2">15</subfield></datafield>
  <datafield tag="100" ind1="1" ind2=" "><subfield code="a">Autor, Teste,</subfield></datafield>
  <datafield tag="245" ind1="1" ind2="0"><subfield code="a">Livro de teste :</subfield><subfield code="b">um subtítulo /</subfield></datafield>
  <datafield tag="264" ind1=" " ind2="1"><subfield code="b">Editora Exemplo,</subfield><subfield code="c">2001.</subfield></datafield>
</record></zs:recordData></zs:record></zs:records></zs:searchRetrieveResponse>
XML;
$regs = cdd_loc_parse_marcxml($marc);
confere('Um registro lido', count($regs) === 1);
$r = $regs[0];
confere('CDD vem do campo 082 (sem a barra)', $r['cdds'][0]['numero'] === '999.99' && $r['cdds'][0]['ddc_edicao'] === '23');
confere('CDD abreviada é identificada', $r['cdds'][1]['abreviada'] === true);
confere('LCC (050) fica separada e não vira CDD', $r['lcc'] === 'PQ9697.T A1 2001' && !in_array('PQ9697.T', array_column($r['cdds'], 'numero'), true));
confere('ISBN normalizado', $r['isbns'] === ['9780000000002']);
confere('Link pelo LCCN', $r['registro_url'] === 'https://lccn.loc.gov/2001012345');
confere('Título sem pontuação final', $r['titulo'] === 'Livro de teste : um subtítulo');
confere('XML com entidade externa não é carregado', (function () {
    $xml = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><record xmlns="http://www.loc.gov/MARC21/slim"><datafield tag="245"><subfield code="a">&x;</subfield></datafield></record>';
    $res = @cdd_loc_parse_marcxml($xml);
    return !str_contains($res[0]['titulo'] ?? '', 'root');
})());

echo "Open Library\n";
$ed = cdd_openlibrary_parse_edicao(json_encode(['title' => 'Livro', 'key' => '/books/OL1M', 'isbn_13' => ['9780000000002'], 'dewey_decimal_class' => ['813/.54', '[Fic]'], 'lc_classifications' => ['PS3500 .A1']]));
confere('dewey_decimal_class normalizada', $ed[0]['cdds'][0]['numero'] === '813.54');
confere('Valor não-CDD ("[Fic]") não vira número', $ed[0]['cdds'][1]['numero'] === '');
confere('lc_classifications fica como LCC', $ed[0]['lcc'] === 'PS3500 .A1');
$busca = cdd_openlibrary_parse_busca(json_encode(['docs' => [['title' => 'Livro', 'key' => '/works/OL1W', 'ddc' => ['813.54'], 'author_name' => ['Fulano']]]]));
confere('Busca marca nível da obra', $busca[0]['nivel'] === 'obra');

echo "Compatibilidade de edição\n";
$livro = ['isbn' => '9780000000002', 'titulo' => 'Livro de teste', 'autor' => 'Teste Autor', 'ano' => 2001, 'editora' => 'Editora Exemplo'];
confere('ISBN e título iguais → mesma edição', cdd_compatibilidade($livro, $r)['nivel'] === 'exata');
confere('ISBN igual com outro título → não corresponde', cdd_compatibilidade(['titulo' => 'Outro livro qualquer'] + $livro, $r)['nivel'] === 'incompativel');
confere('Sem ISBN, título+autor+ano → provável', cdd_compatibilidade(['isbn' => ''] + $livro, $r)['nivel'] === 'provavel');
confere('Sem ISBN e outro ano → incerta (outra edição)', cdd_compatibilidade(['isbn' => '', 'ano' => 1990, 'editora' => 'Outra'] + $livro, $r)['nivel'] === 'incerta');
confere('ISBN-10 equivale ao ISBN-13', cdd_isbn13('8535908773') === cdd_isbn13('9788535908770'));

echo "Regras de ação\n";
$aux = ['id' => 2, 'perfil' => 'auxiliar', 'nome' => 'Aux'];
$bib = ['id' => 1, 'perfil' => 'bibliotecaria', 'nome' => 'Bib'];
$linha = cdd_linha_padrao(1, 'LIT. BRAS.');
$d = fn (array $x) => cdd_dados_limpos($x);
[$s] = cdd_aplicar('sugerir', $linha, $d(['cdd' => '869.3', 'fonte_nome' => 'Ficha catalográfica']), $aux);
confere('Sugestão fica como "sugerida"', $s['status'] === 'sugerida' && $s['cdd_proposta'] === '869.3' && $s['cdd_aprovada'] === null);
confere('Sugestão sem fonte é recusada', lanca(fn () => cdd_aplicar('sugerir', $linha, $d(['cdd' => '869.3']), $aux)) !== null);
confere('Rascunho não exige fonte', cdd_aplicar('rascunho', $linha, $d(['cdd' => '869.3']), $aux)[0]['rascunho'] === 1);
[$rv] = cdd_aplicar('enviar_revisao', $s, $d([]), $aux);
confere('Envio para revisão', $rv['status'] === 'em_revisao');
[$ap, $hist] = cdd_aplicar('aprovar', $rv, $d([]), $bib);
confere('Aprovação registra CDD final, usuário e data', $ap['cdd_aprovada'] === '869.3' && $ap['aprovado_por'] === 1 && $ap['aprovado_em'] !== null);
confere('CDD original preservada', $ap['cdd_original'] === 'LIT. BRAS.');
confere('Histórico guarda CDD anterior (planilha) e nova', $hist[0]['cdd_anterior'] === 'LIT. BRAS.' && $hist[0]['cdd_nova'] === '869.3' && $hist[0]['tipo'] === 'aprovacao');
confere('Corrigir exige justificativa', lanca(fn () => cdd_aplicar('aprovar', $rv, $d(['cdd' => '869.4']), $bib)) !== null);
[$co, $hc] = cdd_aplicar('aprovar', $rv, $d(['cdd' => '869.4', 'justificativa' => 'Assunto é poesia']), $bib);
confere('Aprovar com outro número vira correção', $hc[0]['tipo'] === 'correcao' && $co['cdd_aprovada'] === '869.4');
[$rj] = cdd_aplicar('rejeitar', $rv, $d(['observacao' => 'Outra edição']), $bib);
confere('Rejeição volta para pendente e marca revisão', $rj['status'] === 'pendente' && $rj['precisa_revisao'] === 1 && $rj['cdd_proposta'] === null);
[$rj2] = cdd_aplicar('rejeitar', array_merge($ap, ['cdd_proposta' => '869.5', 'status' => 'em_revisao']), $d(['observacao' => 'Não']), $bib);
confere('Rejeitar nova proposta mantém a CDD aprovada anterior', $rj2['status'] === 'aprovada' && $rj2['cdd_aprovada'] === '869.3');
confere('Auxiliar não pode aprovar', lanca(fn () => cdd_executar('aprovar', 1, ['cdd' => '869.3', 'fonte_nome' => 'x'], $aux)) !== null);
confere('Link de fonte precisa ser http(s)', lanca(fn () => cdd_dados_limpos(['fonte_url' => 'javascript:alert(1)'])) !== null);

if (db_modo() === 'mysql') {
    echo "Fluxo no MySQL (transação desfeita no final)\n";
    $pdo = db();
    $livroId = (int)$pdo->query('SELECT id FROM livros ORDER BY id LIMIT 1')->fetchColumn();
    $pdo->beginTransaction();
    try {
        $pdo->exec("INSERT IGNORE INTO administradores (id, nome, email, senha_hash, perfil) VALUES (900001, 'Teste Bib', 'teste-bib@local', 'x', 'bibliotecaria'), (900002, 'Teste Aux', 'teste-aux@local', 'x', 'auxiliar')");
        $tb = ['id' => 900001, 'perfil' => 'bibliotecaria', 'nome' => 'Teste Bib'];
        $ta = ['id' => 900002, 'perfil' => 'auxiliar', 'nome' => 'Teste Aux'];
        // db_transacao usa a mesma conexão: aqui simulamos com savepoints manuais.
        $item = cdd_item($livroId);
        $linha = cdd_linha_bloqueada($livroId, $item);
        [$n, $h] = cdd_aplicar('sugerir', $linha, cdd_dados_limpos(['cdd' => '028.5', 'fonte_nome' => 'Teste']), $ta);
        cdd_gravar_mysql($n, $h, $ta);
        $salvo = cdd_linha_mysql($livroId);
        confere('CDD gravada como texto, com zero à esquerda', $salvo['cdd_proposta'] === '028.5');
        [$n, $h] = cdd_aplicar('aprovar', $salvo, cdd_dados_limpos([]), $tb);
        cdd_gravar_mysql($n, $h, $tb);
        $salvo = cdd_linha_mysql($livroId);
        confere('Aprovação gravada', $salvo['status'] === 'aprovada' && (int)$salvo['aprovado_por'] === 900001);
        $hid = (int)$pdo->query("SELECT MAX(id) FROM classificacoes_cdd_historico WHERE livro_id = $livroId")->fetchColumn();
        $bloqueado = function (string $sql) use ($pdo) {
            try {
                $pdo->exec($sql);
                return false;
            } catch (PDOException $e) {
                return str_contains($e->getMessage(), 'não pode');
            }
        };
        confere('Histórico não pode ser alterado', $bloqueado("UPDATE classificacoes_cdd_historico SET observacao = 'x' WHERE id = $hid"));
        confere('Histórico não pode ser excluído', $bloqueado("DELETE FROM classificacoes_cdd_historico WHERE id = $hid"));
        confere('Classificação não pode ser excluída', $bloqueado("DELETE FROM classificacoes_cdd WHERE livro_id = $livroId"));
        $pdo->exec("UPDATE classificacoes_cdd SET cdd_original = 'ANTIGA' WHERE livro_id = $livroId AND cdd_original IS NULL");
        confere('CDD original não pode mudar depois de gravada', $bloqueado("UPDATE classificacoes_cdd SET cdd_original = 'OUTRA' WHERE livro_id = $livroId"));
        $check = false;
        try {
            $pdo->exec("UPDATE classificacoes_cdd SET cdd_aprovada = '86.3' WHERE livro_id = $livroId");
        } catch (PDOException $e) {
            $check = true;
        }
        confere('Banco recusa CDD aprovada fora do formato', $check);
        $codigoAntes = $pdo->query("SELECT GROUP_CONCAT(codigo_individual ORDER BY id) FROM exemplares WHERE livro_id = $livroId")->fetchColumn();
        [$n, $h] = cdd_aplicar('aprovar', cdd_linha_mysql($livroId), cdd_dados_limpos(['cdd' => '028.6', 'justificativa' => 'teste']), $tb);
        cdd_gravar_mysql($n, $h, $tb);
        $codigoDepois = $pdo->query("SELECT GROUP_CONCAT(codigo_individual ORDER BY id) FROM exemplares WHERE livro_id = $livroId")->fetchColumn();
        confere('Códigos BDP não mudam com a correção da CDD', $codigoAntes === $codigoDepois);
    } finally {
        $pdo->rollBack();
    }
}

echo "\n" . ($total - $falhas) . " de $total verificações passaram.\n";
exit($falhas ? 1 : 0);
