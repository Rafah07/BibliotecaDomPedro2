<?php
/**
 * Consulta a fontes bibliográficas que publicam a Classificação Decimal de Dewey (CDD).
 *
 * Regras:
 *   * Só registram CDD os números que a fonte informa explicitamente:
 *       - Library of Congress: campo MARC 082 (Dewey Decimal Classification Number).
 *         O campo 050 é a Library of Congress Classification (LCC), outro sistema:
 *         ele aparece só como referência e NUNCA vira sugestão de CDD.
 *       - Open Library: "dewey_decimal_class" (edição) e "ddc" (busca, nível da obra).
 *         "lc_classifications"/"lcc" também são LCC.
 *   * Nenhum número é deduzido, completado ou "corrigido" pelo sistema.
 *   * Cada fonte tem intervalo mínimo entre consultas e as respostas ficam em cache,
 *     para respeitar os limites de uso dos serviços públicos.
 *   * WebDewey exige licença da OCLC e não tem consulta automática aqui:
 *     aparece apenas como fonte de consulta manual quando habilitado.
 */

const CDD_FONTES = [
    'loc' => [
        'nome' => 'Catálogo da Library of Congress',
        'descricao' => 'Registros MARC da Library of Congress (campo 082). Abrange principalmente obras publicadas ou adquiridas nos EUA; muitas edições brasileiras não aparecem.',
        'automatica' => true,
        'site' => 'https://catalog.loc.gov/',
    ],
    'openlibrary' => [
        'nome' => 'Open Library',
        'descricao' => 'Registros bibliográficos públicos. A CDD, quando existe, vem de catálogos importados ou de colaboradores: sempre confira antes de aprovar.',
        'automatica' => true,
        'site' => 'https://openlibrary.org/',
    ],
    'webdewey' => [
        'nome' => 'WebDewey (OCLC)',
        'descricao' => 'Edição eletrônica oficial da CDD. Exige assinatura institucional; consulta manual.',
        'automatica' => false,
        'site' => 'https://www.oclc.org/en/dewey/webdewey.html',
    ],
];

/** Fontes sugeridas no formulário de classificação manual. */
const CDD_FONTES_MANUAIS = [
    'Ficha catalográfica (CIP) impressa no livro',
    'Catálogo da Biblioteca Nacional (Brasil)',
    'Catálogo da Library of Congress',
    'WebDewey (acesso licenciado)',
    'Catálogo de outra biblioteca',
    'Análise da bibliotecária com base nas tabelas da CDD',
];

const CDD_HTTP_TIMEOUT = 12;
const CDD_CACHE_SEGUNDOS = 7 * 86400;

function cdd_fontes_config(): array
{
    return (array)(app_config()['fontes_cdd'] ?? []) + [
        'contato' => '',
        'intervalo_segundos' => 1.5,
        'loc' => true,
        'openlibrary' => true,
        'webdewey' => false,
    ];
}

/** Fontes habilitadas, com o indicador de consulta automática. */
function cdd_fontes_ativas(): array
{
    $config = cdd_fontes_config();
    $ativas = [];
    foreach (CDD_FONTES as $id => $fonte) {
        if (!empty($config[$id])) {
            $ativas[$id] = $fonte + ['id' => $id];
        }
    }
    return $ativas;
}

function cdd_fontes_automaticas(): array
{
    return array_filter(cdd_fontes_ativas(), fn ($f) => $f['automatica']);
}

// ---------------------------------------------------------------
// Pesquisa
// ---------------------------------------------------------------

/**
 * Pesquisa as fontes automáticas.
 * $termos: isbn, titulo, autor, editora, ano, edicao, assunto (todos opcionais).
 * Retorna ['registros' => [...], 'fontes' => [id => ['nome', 'status' => ok|sem_registro|erro, 'mensagem', 'total']]].
 */
function cdd_pesquisar_fontes(array $termos, ?array $fontes = null): array
{
    $termos = cdd_termos_limpos($termos);
    $ativas = cdd_fontes_automaticas();
    $fontes = $fontes === null ? array_keys($ativas) : array_values(array_intersect($fontes, array_keys($ativas)));
    $registros = [];
    $resumo = [];
    foreach ($fontes as $id) {
        try {
            $encontrados = match ($id) {
                'loc' => cdd_fonte_loc($termos),
                'openlibrary' => cdd_fonte_openlibrary($termos),
            };
            $resumo[$id] = ['nome' => CDD_FONTES[$id]['nome'], 'status' => $encontrados ? 'ok' : 'sem_registro', 'total' => count($encontrados), 'mensagem' => ''];
            array_push($registros, ...$encontrados);
        } catch (Throwable $e) {
            error_log("[biblioteca] Falha ao consultar a fonte $id: " . $e->getMessage());
            $resumo[$id] = ['nome' => CDD_FONTES[$id]['nome'], 'status' => 'erro', 'total' => 0, 'mensagem' => 'A fonte não respondeu. Tente novamente mais tarde.'];
        }
    }
    return ['registros' => $registros, 'fontes' => $resumo, 'termos' => $termos];
}

function cdd_termos_limpos(array $termos): array
{
    $limpos = [];
    foreach (['isbn', 'titulo', 'autor', 'editora', 'ano', 'edicao', 'assunto'] as $campo) {
        $valor = trim(preg_replace('/\s+/u', ' ', (string)($termos[$campo] ?? '')));
        $limpos[$campo] = mb_substr($valor, 0, 200);
    }
    $limpos['isbn'] = acervo_isbn_normalizar($limpos['isbn']);
    return $limpos;
}

// ---------------------------------------------------------------
// Library of Congress — SRU (https://www.loc.gov/standards/sru/)
// ---------------------------------------------------------------

const CDD_LOC_SRU = 'http://lx2.loc.gov:210/LCDB';

function cdd_fonte_loc(array $t): array
{
    $cql = cdd_loc_cql($t);
    if ($cql === '') {
        return [];
    }
    $url = CDD_LOC_SRU . '?' . http_build_query([
        'operation' => 'searchRetrieve',
        'version' => '1.1',
        'query' => $cql,
        'maximumRecords' => 5,
        'recordSchema' => 'marcxml',
    ]);
    $xml = cdd_http_get($url, 'loc');
    $registros = cdd_loc_parse_marcxml($xml);
    // Pesquisa só por assunto mostra como a LC classifica o tema, não este livro.
    if ($t['isbn'] === '' && $t['titulo'] === '' && $t['assunto'] !== '') {
        foreach ($registros as &$r) {
            $r['apenas_assunto'] = true;
        }
        unset($r);
    }
    return $registros;
}

/** Monta a consulta CQL. Usa ISBN quando houver; senão título/autor; por último, assunto. */
function cdd_loc_cql(array $t): string
{
    $q = fn (string $v) => '"' . str_replace(['\\', '"'], ['', ''], $v) . '"';
    if ($t['isbn'] !== '') {
        return 'bath.isbn=' . $t['isbn'];
    }
    $partes = [];
    if ($t['titulo'] !== '') {
        $partes[] = 'dc.title=' . $q($t['titulo']);
    }
    if ($t['autor'] !== '') {
        $partes[] = 'dc.creator=' . $q($t['autor']);
    }
    if (!$partes && $t['assunto'] !== '') {
        $partes[] = 'dc.subject=' . $q($t['assunto']);
    }
    return implode(' and ', $partes);
}

/** Lê os registros MARCXML. Público para os testes automatizados. */
function cdd_loc_parse_marcxml(string $xml): array
{
    if (trim($xml) === '') {
        return [];
    }
    $anterior = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    // LIBXML_NONET: nunca carrega entidades/DTDs externos.
    $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
    libxml_use_internal_errors($anterior);
    if (!$ok) {
        throw new RuntimeException('Resposta XML inválida da Library of Congress.');
    }
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('m', 'http://www.loc.gov/MARC21/slim');
    $registros = [];
    foreach ($xp->query('//m:record') as $rec) {
        $sub = function (string $tag, string $codes) use ($xp, $rec): array {
            $valores = [];
            foreach ($xp->query("m:datafield[@tag='$tag']", $rec) as $campo) {
                $partes = [];
                foreach ($xp->query('m:subfield', $campo) as $sf) {
                    if (str_contains($codes, $sf->getAttribute('code'))) {
                        $partes[] = trim($sf->textContent);
                    }
                }
                if ($partes) {
                    $valores[] = ['texto' => trim(implode(' ', $partes)), 'campo' => $campo];
                }
            }
            return $valores;
        };
        $primeiro = fn (array $v) => $v[0]['texto'] ?? '';
        $limpaPont = fn (string $s) => trim(preg_replace('/\s*[\/:;,.=]\s*$/u', '', $s));

        $lccn = trim(preg_replace('/\s+/', '', $primeiro($sub('010', 'a'))));
        $controle = '';
        foreach ($xp->query("m:controlfield[@tag='001']", $rec) as $cf) {
            $controle = trim($cf->textContent);
        }
        $isbns = array_map(fn ($v) => acervo_isbn_normalizar(strtok($v['texto'], ' (')), $sub('020', 'a'));

        $cdds = [];
        foreach ($xp->query("m:datafield[@tag='082']", $rec) as $campo) {
            $edicao = '';
            $numeros = [];
            foreach ($xp->query('m:subfield', $campo) as $sf) {
                if ($sf->getAttribute('code') === 'a') {
                    $numeros[] = trim($sf->textContent);
                } elseif ($sf->getAttribute('code') === '2') {
                    $edicao = trim($sf->textContent);
                }
            }
            foreach ($numeros as $numero) {
                $cdds[] = [
                    'bruta' => $numero,
                    'numero' => cdd_normalizar_fonte($numero),
                    'ddc_edicao' => $edicao,
                    'abreviada' => $campo->getAttribute('ind1') === '1',
                    'atribuida_por_lc' => $campo->getAttribute('ind2') === '0',
                ];
            }
        }

        $editora = $primeiro($sub('264', 'b')) ?: $primeiro($sub('260', 'b'));
        $ano = $primeiro($sub('264', 'c')) ?: $primeiro($sub('260', 'c'));
        $registros[] = [
            'fonte' => 'loc',
            'fonte_nome' => CDD_FONTES['loc']['nome'],
            'registro_id' => $lccn ?: $controle,
            'registro_url' => $lccn !== '' ? 'https://lccn.loc.gov/' . rawurlencode($lccn) : CDD_FONTES['loc']['site'],
            'titulo' => $limpaPont($primeiro($sub('245', 'ab'))),
            'autor' => $limpaPont($primeiro($sub('100', 'a')) ?: $primeiro($sub('110', 'a'))),
            'editora' => $limpaPont($editora),
            'ano' => preg_match('/\d{4}/', $ano, $m) ? $m[0] : '',
            'edicao' => $limpaPont($primeiro($sub('250', 'a'))),
            'isbns' => array_values(array_filter(array_unique($isbns))),
            'cdds' => $cdds,
            // LCC (campo 050): outro sistema de classificação. Só referência.
            'lcc' => $primeiro($sub('050', 'ab')),
            'nivel' => 'edicao',
        ];
    }
    return $registros;
}

// ---------------------------------------------------------------
// Open Library (https://openlibrary.org/developers/api)
// ---------------------------------------------------------------

const CDD_OPENLIBRARY = 'https://openlibrary.org';

function cdd_fonte_openlibrary(array $t): array
{
    if ($t['isbn'] !== '') {
        $json = cdd_http_get(CDD_OPENLIBRARY . '/isbn/' . rawurlencode($t['isbn']) . '.json', 'openlibrary', true);
        return $json === '' ? [] : cdd_openlibrary_parse_edicao($json);
    }
    if ($t['titulo'] === '') {
        return [];
    }
    $params = ['title' => $t['titulo'], 'limit' => 5, 'fields' => 'key,title,subtitle,author_name,publisher,first_publish_year,isbn,ddc,lcc,edition_count'];
    if ($t['autor'] !== '') {
        $params['author'] = $t['autor'];
    }
    return cdd_openlibrary_parse_busca(cdd_http_get(CDD_OPENLIBRARY . '/search.json?' . http_build_query($params), 'openlibrary'));
}

/** Registro de uma edição (consulta por ISBN). */
function cdd_openlibrary_parse_edicao(string $json): array
{
    $e = json_decode($json, true);
    if (!is_array($e) || empty($e['title'])) {
        return [];
    }
    $isbns = array_map('acervo_isbn_normalizar', array_merge((array)($e['isbn_13'] ?? []), (array)($e['isbn_10'] ?? [])));
    return [[
        'fonte' => 'openlibrary',
        'fonte_nome' => CDD_FONTES['openlibrary']['nome'],
        'registro_id' => (string)($e['key'] ?? ''),
        'registro_url' => !empty($e['key']) ? CDD_OPENLIBRARY . $e['key'] : CDD_FONTES['openlibrary']['site'],
        'titulo' => trim(($e['title'] ?? '') . (!empty($e['subtitle']) ? ': ' . $e['subtitle'] : '')),
        'autor' => trim((string)($e['by_statement'] ?? '')),
        'editora' => implode(', ', (array)($e['publishers'] ?? [])),
        'ano' => preg_match('/\d{4}/', (string)($e['publish_date'] ?? ''), $m) ? $m[0] : '',
        'edicao' => (string)($e['edition_name'] ?? ''),
        'isbns' => array_values(array_filter(array_unique($isbns))),
        'cdds' => array_map(fn ($n) => ['bruta' => (string)$n, 'numero' => cdd_normalizar_fonte((string)$n), 'ddc_edicao' => '', 'abreviada' => false, 'atribuida_por_lc' => false], (array)($e['dewey_decimal_class'] ?? [])),
        'lcc' => implode('; ', (array)($e['lc_classifications'] ?? [])),
        'nivel' => 'edicao',
    ]];
}

/** Resultados da busca por título/autor: a CDD é agregada da obra (várias edições). */
function cdd_openlibrary_parse_busca(string $json): array
{
    $dados = json_decode($json, true);
    $registros = [];
    foreach ((array)($dados['docs'] ?? []) as $doc) {
        if (empty($doc['title'])) {
            continue;
        }
        $registros[] = [
            'fonte' => 'openlibrary',
            'fonte_nome' => CDD_FONTES['openlibrary']['nome'],
            'registro_id' => (string)($doc['key'] ?? ''),
            'registro_url' => !empty($doc['key']) ? CDD_OPENLIBRARY . $doc['key'] : CDD_FONTES['openlibrary']['site'],
            'titulo' => trim($doc['title'] . (!empty($doc['subtitle']) ? ': ' . $doc['subtitle'] : '')),
            'autor' => implode(', ', array_slice((array)($doc['author_name'] ?? []), 0, 3)),
            'editora' => implode(', ', array_slice((array)($doc['publisher'] ?? []), 0, 3)),
            'ano' => (string)($doc['first_publish_year'] ?? ''),
            'edicao' => isset($doc['edition_count']) ? $doc['edition_count'] . ' edições na base' : '',
            'isbns' => array_values(array_slice(array_map('acervo_isbn_normalizar', (array)($doc['isbn'] ?? [])), 0, 40)),
            'cdds' => array_map(fn ($n) => ['bruta' => (string)$n, 'numero' => cdd_normalizar_fonte((string)$n), 'ddc_edicao' => '', 'abreviada' => false, 'atribuida_por_lc' => false], array_slice((array)($doc['ddc'] ?? []), 0, 5)),
            'lcc' => implode('; ', array_slice((array)($doc['lcc'] ?? []), 0, 3)),
            'nivel' => 'obra',
        ];
    }
    return $registros;
}

// ---------------------------------------------------------------
// Normalização e compatibilidade
// ---------------------------------------------------------------

/**
 * Normaliza um número de CDD vindo de uma fonte: remove as marcas de segmentação
 * da LC (/ e ') e espaços. Retorna '' se o resultado não tiver formato de CDD
 * (ex.: "[Fic]", "B", "E"), para que nada inválido vire sugestão.
 */
function cdd_normalizar_fonte(string $bruta): string
{
    $n = str_replace(['/', "'", '’', ' '], '', trim($bruta));
    return preg_match('/^\d{3}(\.\d+)?$/', $n) ? $n : '';
}

function cdd_texto_comparacao(string $texto): string
{
    $t = acervo_texto_busca($texto);
    $t = preg_replace('/[^a-z0-9 ]+/', ' ', $t);
    $t = preg_replace('/^(o|a|os|as|um|uma|the|an?|el|la|los|las|le|les)\s+/', '', trim($t));
    return trim(preg_replace('/\s+/', ' ', $t));
}

/** ISBN-10 → ISBN-13 (para comparar registros que usam formatos diferentes). */
function cdd_isbn13(string $isbn): string
{
    $isbn = acervo_isbn_normalizar($isbn);
    if (strlen($isbn) !== 10) {
        return $isbn;
    }
    $base = '978' . substr($isbn, 0, 9);
    $soma = 0;
    for ($i = 0; $i < 12; $i++) {
        $soma += (int)$base[$i] * ($i % 2 === 0 ? 1 : 3);
    }
    return $base . ((10 - $soma % 10) % 10);
}

/**
 * Avalia se o registro encontrado corresponde ao livro (mesma edição).
 * exata: ISBN igual e título compatível | provavel: título, autor e ano/editora compatíveis
 * incerta: título e autor parecidos, mas pode ser outra edição | incompativel: não é o mesmo livro.
 */
function cdd_compatibilidade(array $livro, array $registro): array
{
    $motivos = [];
    $isbnLivro = $livro['isbn'] ? cdd_isbn13($livro['isbn']) : '';
    $isbnIgual = $isbnLivro !== '' && in_array($isbnLivro, array_map('cdd_isbn13', $registro['isbns'] ?? []), true);

    $tl = cdd_texto_comparacao($livro['titulo'] ?? '');
    $tr = cdd_texto_comparacao(preg_replace('/\s*:.*$/u', '', $registro['titulo'] ?? ''));
    similar_text($tl, $tr, $pct);
    $tituloOk = $tl !== '' && ($pct >= 85 || ($tr !== '' && (str_starts_with($tr, $tl) || str_starts_with($tl, $tr))));

    $sobrenome = '';
    $partesAutor = preg_split('/[\s,]+/', cdd_texto_comparacao($livro['autor'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
    if ($partesAutor) {
        $sobrenome = end($partesAutor);
    }
    $autorRegistro = cdd_texto_comparacao($registro['autor'] ?? '');
    $autorOk = $sobrenome !== '' && $autorRegistro !== '' && str_contains($autorRegistro, $sobrenome);
    $anoOk = !empty($livro['ano']) && (string)$livro['ano'] === (string)($registro['ano'] ?? '');
    $editoraOk = !empty($livro['editora']) && !empty($registro['editora'])
        && str_contains(cdd_texto_comparacao($registro['editora']), cdd_texto_comparacao(preg_replace('/^editora\s+/iu', '', $livro['editora'])));

    $motivos[] = $isbnIgual ? 'ISBN igual ao do livro' : ($isbnLivro !== '' ? 'ISBN diferente ou ausente no registro' : 'Livro sem ISBN cadastrado');
    $motivos[] = $tituloOk ? 'Título compatível' : 'Título diferente';
    if ($sobrenome !== '') {
        $motivos[] = $autorOk ? 'Autor compatível' : 'Autor não confere';
    }
    if ($anoOk) {
        $motivos[] = 'Mesmo ano';
    }
    if ($editoraOk) {
        $motivos[] = 'Mesma editora';
    }
    if (($registro['nivel'] ?? '') === 'obra') {
        $motivos[] = 'CDD reunida de várias edições da obra';
    }
    if (!empty($registro['apenas_assunto'])) {
        $motivos[] = 'Encontrado só pelo assunto: mostra como o tema é classificado, não este livro';
        return ['nivel' => 'incerta', 'motivos' => $motivos];
    }

    if ($isbnIgual && $tituloOk) {
        $nivel = ($registro['nivel'] ?? '') === 'obra' ? 'provavel' : 'exata';
    } elseif ($isbnIgual) {
        $nivel = 'incompativel';
        $motivos[] = 'O ISBN pertence a outro título: confira o cadastro';
    } elseif ($tituloOk && $autorOk && ($anoOk || $editoraOk) && ($registro['nivel'] ?? '') !== 'obra') {
        $nivel = 'provavel';
    } elseif ($tituloOk && ($autorOk || $sobrenome === '')) {
        $nivel = 'incerta';
        $motivos[] = 'Pode ser outra edição';
    } else {
        $nivel = 'incompativel';
    }
    return ['nivel' => $nivel, 'motivos' => $motivos];
}

// ---------------------------------------------------------------
// HTTP com intervalo mínimo e cache
// ---------------------------------------------------------------

/** GET com cache e intervalo entre consultas. $ausenteVazio: 404 vira '' (registro inexistente). */
function cdd_http_get(string $url, string $fonte, bool $ausenteVazio = false): string
{
    $dir = sys_get_temp_dir() . '/bdp-cdd-cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $cache = $dir . '/' . sha1($url) . '.cache';
    if (is_file($cache) && filemtime($cache) > time() - CDD_CACHE_SEGUNDOS) {
        return (string)file_get_contents($cache);
    }

    cdd_aguardar_intervalo($fonte, $dir);
    $config = cdd_fontes_config();
    $agente = 'BibliotecaDomPedroII/1.0 (sistema escolar' . ($config['contato'] ? '; ' . $config['contato'] : '') . ')';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => CDD_HTTP_TIMEOUT,
            CURLOPT_USERAGENT => $agente,
            CURLOPT_HTTPHEADER => ['Accept: application/json, application/xml;q=0.9, */*;q=0.5'],
        ]);
        $corpo = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $erro = curl_error($ch);
        curl_close($ch);
        if ($corpo === false) {
            throw new RuntimeException("Falha de rede: $erro");
        }
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => CDD_HTTP_TIMEOUT, 'user_agent' => $agente, 'ignore_errors' => true, 'follow_location' => 1, 'max_redirects' => 3]]);
        $corpo = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $linha) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linha, $m)) {
                $status = (int)$m[1];
            }
        }
        if ($corpo === false) {
            throw new RuntimeException('Falha de rede.');
        }
    }

    if ($status === 404 && $ausenteVazio) {
        $corpo = '';
    } elseif ($status === 429) {
        throw new RuntimeException('Limite de consultas da fonte atingido (HTTP 429).');
    } elseif ($status < 200 || $status >= 300) {
        throw new RuntimeException("Resposta HTTP $status.");
    }
    @file_put_contents($cache, $corpo, LOCK_EX);
    return $corpo;
}

/** Garante o intervalo mínimo entre duas consultas à mesma fonte (vale para todos os usuários). */
function cdd_aguardar_intervalo(string $fonte, string $dir): void
{
    $intervalo = max(0.5, (float)cdd_fontes_config()['intervalo_segundos']);
    $arquivo = $dir . '/ritmo-' . preg_replace('/[^a-z0-9]/', '', $fonte) . '.lock';
    $h = @fopen($arquivo, 'c+');
    if (!$h) {
        usleep((int)($intervalo * 1e6));
        return;
    }
    flock($h, LOCK_EX);
    $ultimo = (float)stream_get_contents($h);
    $espera = $ultimo + $intervalo - microtime(true);
    if ($espera > 0) {
        usleep((int)(min($espera, 10) * 1e6));
    }
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, (string)microtime(true));
    flock($h, LOCK_UN);
    fclose($h);
}
