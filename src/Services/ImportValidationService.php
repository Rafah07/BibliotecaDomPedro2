<?php

namespace Biblioteca\Services;

use Biblioteca\Support\Isbn;
use Biblioteca\Support\Texto;

/**
 * Mapeamento, normalização e validação das linhas das planilhas.
 *
 * Tudo aqui é "puro" (não acessa o banco): recebe dados e devolve resultados.
 * Os problemas encontrados têm três níveis:
 *   erro   — a linha não pode ser importada sem correção;
 *   aviso  — pode ser importada, mas merece atenção;
 *   info   — só informação (ex.: espaços removidos, CDD antiga fora do padrão).
 * Duplicidades são tratadas à parte (analisarDuplicidades), porque dependem das outras linhas.
 */
final class ImportValidationService
{
    /** Campos do sistema que podem receber colunas da planilha. */
    public const CAMPOS = [
        'titulo' => ['label' => 'Título', 'sinonimos' => ['titulo', 'titulo da obra', 'nome do livro', 'obra', 'livro']],
        'subtitulo' => ['label' => 'Subtítulo', 'sinonimos' => ['subtitulo', 'sub titulo']],
        'autor' => ['label' => 'Autor', 'sinonimos' => ['sobrenome prenome do autor', 'sobrenome prenome autor', 'autor', 'autores', 'autor principal', 'nome do autor', 'autoria']],
        'isbn' => ['label' => 'ISBN', 'sinonimos' => ['isbn', 'isbn 10', 'isbn 13', 'codigo isbn']],
        'editora' => ['label' => 'Editora', 'sinonimos' => ['editora', 'editor', 'publicadora']],
        'ano_publicacao' => ['label' => 'Ano de publicação', 'sinonimos' => ['ano', 'ano de publicacao', 'ano publicacao', 'ano da edicao', 'data de publicacao']],
        'edicao' => ['label' => 'Edição', 'sinonimos' => ['edicao', 'ed']],
        'idioma' => ['label' => 'Idioma', 'sinonimos' => ['idioma', 'lingua']],
        'assunto_original' => ['label' => 'Assunto (original)', 'sinonimos' => ['assunto', 'assuntos', 'tema']],
        'categoria_original' => ['label' => 'Categoria (original)', 'sinonimos' => ['categoria', 'genero', 'genero literario', 'secao']],
        'cdd_original' => ['label' => 'CDD original', 'sinonimos' => ['cdd', 'classificacao', 'classificacao cdd', 'cdd antiga', 'dewey']],
        'codigo_autor_antigo' => ['label' => 'Código do autor (antigo)', 'sinonimos' => ['cod autor', 'cod do autor', 'codigo autor', 'codigo do autor', 'cutter', 'pha', 'tabela cutter']],
        'localizacao_antiga' => ['label' => 'Localização antiga', 'sinonimos' => ['localizacao na estante', 'localizacao', 'local', 'estante', 'localizacao antiga', 'posicao na estante']],
        'quantidade_informada' => ['label' => 'Quantidade informada', 'sinonimos' => ['quantidade', 'qtd', 'qtde', 'quant', 'exemplares', 'n de exemplares', 'numero de exemplares', 'total de exemplares']],
        'observacoes' => ['label' => 'Observações', 'sinonimos' => ['observacao', 'observacoes', 'obs']],
        'extra' => ['label' => 'Outra informação (guardar nas observações)', 'sinonimos' => []],
    ];

    /** Campos de texto livre que passam pela limpeza padrão. */
    private const CAMPOS_TEXTO = ['titulo', 'subtitulo', 'autor', 'editora', 'edicao', 'idioma', 'assunto_original', 'categoria_original', 'codigo_autor_antigo', 'localizacao_antiga', 'observacoes'];

    /** Campos que o usuário pode corrigir na revisão. */
    public const CAMPOS_CORRIGIVEIS = ['titulo', 'subtitulo', 'autor', 'isbn', 'editora', 'ano_publicacao', 'edicao', 'quantidade_informada'];

    public const LIMITES = [
        'titulo' => 255, 'subtitulo' => 255, 'autor' => 150, 'editora' => 150, 'edicao' => 60, 'idioma' => 30,
        'assunto_original' => 500, 'categoria_original' => 255, 'cdd_original' => 50, 'codigo_autor_antigo' => 50, 'localizacao_antiga' => 100,
    ];

    // ---------------------------------------------------------------
    // Mapeamento
    // ---------------------------------------------------------------

    /**
     * Sugere o campo de cada coluna pelo nome do cabeçalho.
     * Cada campo é sugerido uma vez; colunas não reconhecidas ficam como "ignorar".
     * @param string[] $cabecalhos
     * @param array<string, string> $salvo mapeamento salvo (cabeçalho normalizado => campo), tem prioridade
     * @return array<string, string> cabeçalho => campo
     */
    public function sugerirMapeamento(array $cabecalhos, array $salvo = []): array
    {
        $mapa = [];
        $usados = [];
        foreach ($cabecalhos as $cabecalho) {
            $chave = Texto::chave($cabecalho);
            if (isset($salvo[$chave]) && ($salvo[$chave] === 'ignorar' || isset(self::CAMPOS[$salvo[$chave]]))) {
                $mapa[$cabecalho] = $salvo[$chave];
                if ($salvo[$chave] !== 'ignorar' && $salvo[$chave] !== 'extra') {
                    $usados[$salvo[$chave]] = true;
                }
            }
        }
        // 1º: nomes exatos. 2º: nomes parecidos.
        foreach ([true, false] as $exato) {
            foreach ($cabecalhos as $cabecalho) {
                if (isset($mapa[$cabecalho])) {
                    continue;
                }
                $campo = $this->campoPorNome(Texto::chave($cabecalho), $exato);
                if ($campo !== null && !isset($usados[$campo])) {
                    $mapa[$cabecalho] = $campo;
                    $usados[$campo] = true;
                }
            }
        }
        $resultado = [];
        foreach ($cabecalhos as $cabecalho) {
            $resultado[$cabecalho] = $mapa[$cabecalho] ?? 'ignorar';
        }
        return $resultado;
    }

    private function campoPorNome(string $chave, bool $exato): ?string
    {
        if ($chave === '') {
            return null;
        }
        if ($exato) {
            foreach (self::CAMPOS as $campo => $def) {
                if (in_array($chave, $def['sinonimos'], true)) {
                    return $campo;
                }
            }
            return null;
        }
        return match (true) {
            str_contains($chave, 'autor') && (str_contains($chave, 'cod') || str_contains($chave, 'cutter')) => 'codigo_autor_antigo',
            str_contains($chave, 'subtitulo') => 'subtitulo',
            str_contains($chave, 'titulo') => 'titulo',
            str_contains($chave, 'autor') => 'autor',
            str_contains($chave, 'isbn') => 'isbn',
            str_contains($chave, 'cdd') || str_contains($chave, 'dewey') => 'cdd_original',
            str_contains($chave, 'localiza') || str_contains($chave, 'estante') => 'localizacao_antiga',
            str_contains($chave, 'quant') || str_contains($chave, 'qtd') || str_contains($chave, 'exemplar') => 'quantidade_informada',
            str_contains($chave, 'editora') => 'editora',
            str_contains($chave, 'assunto') => 'assunto_original',
            str_contains($chave, 'ano') && !str_contains($chave, 'plano') => 'ano_publicacao',
            str_contains($chave, 'edic') => 'edicao',
            str_contains($chave, 'obs') => 'observacoes',
            default => null,
        };
    }

    /**
     * Valida o mapeamento escolhido. Devolve mensagens de erro (vazio = ok).
     * @param array<string, string> $mapeamento
     */
    public function validarMapeamento(array $mapeamento): array
    {
        $erros = [];
        $contagem = [];
        foreach ($mapeamento as $campo) {
            if ($campo !== 'ignorar' && !isset(self::CAMPOS[$campo])) {
                $erros[] = 'Campo desconhecido no mapeamento.';
                continue;
            }
            $contagem[$campo] = ($contagem[$campo] ?? 0) + 1;
        }
        if (empty($contagem['titulo'])) {
            $erros[] = 'Escolha a coluna que contém o título do livro.';
        }
        foreach ($contagem as $campo => $n) {
            if ($n > 1 && !in_array($campo, ['ignorar', 'extra', 'observacoes'], true)) {
                $erros[] = 'O campo "' . self::CAMPOS[$campo]['label'] . '" foi escolhido para mais de uma coluna.';
            }
        }
        return $erros;
    }

    /**
     * Aplica o mapeamento a uma linha original.
     * @param array<string, string> $original cabeçalho => valor
     * @param array<string, string> $mapeamento cabeçalho => campo
     * @return array<string, string> campo => valor bruto
     */
    public function mapear(array $original, array $mapeamento): array
    {
        $brutos = [];
        $extras = [];
        foreach ($original as $cabecalho => $valor) {
            $campo = $mapeamento[$cabecalho] ?? 'ignorar';
            $valor = (string)$valor;
            if ($campo === 'ignorar' || trim($valor) === '') {
                continue;
            }
            if ($campo === 'extra' || ($campo === 'observacoes' && isset($brutos['observacoes']))) {
                $extras[] = $cabecalho . ': ' . trim($valor);
                continue;
            }
            $brutos[$campo] = $valor;
        }
        if ($extras) {
            $brutos['observacoes'] = trim(($brutos['observacoes'] ?? '') . ($brutos['observacoes'] ?? '' ? ' | ' : '') . implode(' | ', $extras));
        }
        return $brutos;
    }

    // ---------------------------------------------------------------
    // Normalização
    // ---------------------------------------------------------------

    /**
     * Normaliza os valores brutos. O resultado guarda também o que foi limpo,
     * para a revisão mostrar original e normalizado lado a lado.
     * @param array<string, string> $brutos
     */
    public function normalizar(array $brutos): array
    {
        $n = ['_limpezas' => []];
        foreach (self::CAMPOS_TEXTO as $campo) {
            $bruto = $brutos[$campo] ?? null;
            $limpo = Texto::limpar($bruto);
            $n[$campo] = $limpo;
            if ($bruto !== null && $limpo !== null && $limpo !== trim((string)$bruto)) {
                $n['_limpezas'][] = $campo;
            }
        }
        // Autores separados por ";" (ou " / "). O primeiro é o principal.
        $n['autores'] = $n['autor'] !== null
            ? array_values(array_filter(array_map([Texto::class, 'limpar'], preg_split('/\s*(?:;|\s\/\s)\s*/u', $n['autor']))))
            : [];

        // ISBN: só dígitos (e X). O texto original continua em dados_originais.
        $isbnBruto = Texto::limpar($brutos['isbn'] ?? null);
        $n['isbn_informado'] = $isbnBruto;
        $n['isbn'] = $isbnBruto !== null ? Isbn::normalizar($isbnBruto) : null;
        if ($n['isbn'] === '') {
            $n['isbn'] = null;
        }

        // CDD antiga: texto, preservando zeros à esquerda e pontos; não é validada.
        $cdd = $brutos['cdd_original'] ?? null;
        $n['cdd_original'] = $cdd !== null && trim($cdd) !== '' ? preg_replace('/\s+/u', ' ', trim($cdd)) : null;

        $anoBruto = Texto::limpar($brutos['ano_publicacao'] ?? null);
        $n['ano_informado'] = $anoBruto;
        $n['ano_publicacao'] = null;
        if ($anoBruto !== null && preg_match('/^(?:c\.?\s*|\[)?(\d{4})\]?\.?$/i', $anoBruto, $m)) {
            $n['ano_publicacao'] = (int)$m[1];
        }

        // Quantidade: só espaços são removidos, para não esconder sinais ("-2") ou textos ("dois").
        $qtdBruta = isset($brutos['quantidade_informada']) && trim($brutos['quantidade_informada']) !== '' ? trim(preg_replace('/\s+/u', ' ', $brutos['quantidade_informada'])) : null;
        $n['quantidade_texto'] = $qtdBruta;
        $n['quantidade_informada'] = Texto::inteiro($qtdBruta);

        foreach (self::LIMITES as $campo => $limite) {
            if (isset($n[$campo]) && is_string($n[$campo]) && mb_strlen($n[$campo]) > $limite) {
                $n['_excede'][] = $campo;
            }
        }
        return $n;
    }

    /** Aplica as correções da revisão sobre os dados normalizados (o original fica intacto). */
    public function aplicarCorrecoes(array $brutos, ?array $correcoes): array
    {
        foreach ($correcoes ?? [] as $campo => $valor) {
            if (in_array($campo, self::CAMPOS_CORRIGIVEIS, true)) {
                $brutos[$campo] = (string)$valor;
            }
        }
        return $this->normalizar($brutos);
    }

    // ---------------------------------------------------------------
    // Validação de uma linha
    // ---------------------------------------------------------------

    /**
     * @return array<int, array{nivel: string, codigo: string, campo: ?string, mensagem: string}>
     */
    public function validarLinha(array $n): array
    {
        $p = [];
        $add = function (string $nivel, string $codigo, ?string $campo, string $mensagem) use (&$p) {
            $p[] = ['nivel' => $nivel, 'codigo' => $codigo, 'campo' => $campo, 'mensagem' => $mensagem];
        };
        $rotulo = fn (string $campo) => self::CAMPOS[$campo]['label'] ?? $campo;

        // Campos obrigatórios / identificação da obra
        if ($n['titulo'] === null) {
            if ($n['autor'] === null && $n['isbn'] === null) {
                $add('erro', 'dados_insuficientes', 'titulo', 'Linha sem título, autor ou ISBN: não há dados suficientes para identificar a obra.');
            } else {
                $add('erro', 'sem_titulo', 'titulo', 'Título não informado.');
            }
        } elseif (mb_strlen($n['titulo']) < 2) {
            $add('erro', 'titulo_insuficiente', 'titulo', 'Título curto demais para identificar a obra.');
        } elseif (Isbn::pareceIsbn($n['titulo'])) {
            $add('aviso', 'valores_trocados', 'titulo', 'O título parece ser um ISBN. Confira se as colunas foram trocadas.');
        } elseif (preg_match('/^[\d\s.,\-\/]+$/u', $n['titulo'])) {
            $add('aviso', 'titulo_numerico', 'titulo', 'O título contém apenas números. Confira se a coluna está correta.');
        }

        if ($n['autor'] === null) {
            $add('aviso', 'sem_autor', 'autor', 'Autor não informado (registro incompleto).');
        } elseif (Isbn::pareceIsbn($n['autor']) || preg_match('/^[\d\s.,\-\/]+$/u', $n['autor'])) {
            $add('aviso', 'valores_trocados', 'autor', 'O autor contém apenas números. Confira se as colunas foram trocadas.');
        } elseif ($n['titulo'] !== null && Texto::chave($n['autor']) === Texto::chave($n['titulo'])) {
            $add('aviso', 'valores_trocados', 'autor', 'Título e autor estão iguais. Confira se as colunas foram trocadas.');
        }

        // ISBN (não obrigatório: livros antigos podem não ter)
        if ($n['isbn_informado'] !== null && !Isbn::valido($n['isbn'])) {
            $add('erro', 'isbn_invalido', 'isbn', 'ISBN inválido: "' . $n['isbn_informado'] . '". Confira os dígitos ou deixe o campo vazio.');
        }

        // Ano
        $anoMax = (int)date('Y') + 1;
        if ($n['ano_informado'] !== null && ($n['ano_publicacao'] === null || $n['ano_publicacao'] < 1450 || $n['ano_publicacao'] > $anoMax)) {
            $add('aviso', 'ano_invalido', 'ano_publicacao', 'Ano inválido: "' . $n['ano_informado'] . '". O ano não será gravado.');
        }

        // Quantidade (apenas referência; não gera exemplares)
        if ($n['quantidade_texto'] !== null) {
            if ($n['quantidade_informada'] === null) {
                $add('erro', 'quantidade_invalida', 'quantidade_informada', 'Quantidade inválida: "' . $n['quantidade_texto'] . '". Use um número inteiro.');
            } elseif ($n['quantidade_informada'] < 0) {
                $add('erro', 'quantidade_negativa', 'quantidade_informada', 'Quantidade negativa: ' . $n['quantidade_informada'] . '.');
            } elseif ($n['quantidade_informada'] === 0) {
                $add('aviso', 'quantidade_zero', 'quantidade_informada', 'Quantidade informada igual a zero.');
            } elseif ($n['quantidade_informada'] > 200) {
                $add('aviso', 'quantidade_alta', 'quantidade_informada', 'Quantidade muito alta (' . $n['quantidade_informada'] . '). Confira se não é outro número.');
            }
        }

        // CDD antiga: nunca impede a importação
        if ($n['cdd_original'] !== null) {
            if (Isbn::pareceIsbn($n['cdd_original'])) {
                $add('aviso', 'valores_trocados', 'cdd_original', 'A CDD antiga parece ser um ISBN. Confira se as colunas foram trocadas.');
            } elseif (!preg_match('/^\d{3}(\.\d+)?$/', $n['cdd_original'])) {
                $add('info', 'cdd_fora_padrao', 'cdd_original', 'CDD antiga fora do padrão ("' . $n['cdd_original'] . '"). Será revista na reclassificação.');
            }
        }

        if ($n['editora'] !== null && preg_match('/^[\d\s.,\-\/]+$/u', $n['editora'])) {
            $add('aviso', 'valores_trocados', 'editora', 'A editora contém apenas números. Confira se as colunas foram trocadas.');
        }

        foreach ($n['_excede'] ?? [] as $campo) {
            $add('erro', 'texto_longo', $campo, $rotulo($campo) . ' passa do limite de ' . self::LIMITES[$campo] . ' caracteres.');
        }
        if ($n['_limpezas']) {
            $add('info', 'texto_limpo', null, 'Espaços e caracteres desnecessários removidos em: ' . implode(', ', array_map($rotulo, $n['_limpezas'])) . '.');
        }
        return $p;
    }

    /** Chaves de comparação de uma linha normalizada. */
    public function chaves(array $n): array
    {
        $identidade = [];
        foreach (['titulo', 'subtitulo', 'autor', 'isbn', 'editora', 'ano_publicacao', 'edicao', 'cdd_original', 'codigo_autor_antigo', 'localizacao_antiga', 'assunto_original', 'quantidade_informada'] as $campo) {
            $identidade[$campo] = is_string($n[$campo] ?? null) ? Texto::chave($n[$campo]) : ($n[$campo] ?? null);
        }
        return [
            'hash' => sha1(json_encode($identidade)),
            'isbn' => $n['isbn'] !== null && Isbn::valido($n['isbn']) ? Isbn::para13($n['isbn']) : null,
            'obra' => Texto::chaveObra($n['titulo'], $n['autores'][0] ?? $n['autor']),
        ];
    }

    // ---------------------------------------------------------------
    // Duplicidades
    // ---------------------------------------------------------------

    /**
     * Procura duplicidades entre as linhas da planilha e o acervo já cadastrado.
     * Nada é unido ou excluído: o resultado só marca as linhas para revisão.
     *
     * @param array<int, array{id: int, linha: int, hash: string, isbn: ?string, obra: ?string}> $itens
     * @param array{isbn: array<string, int>, obra: array<string, int[]>, isbn_livro: array<int, ?string>} $acervo índices do acervo
     * @return array<int, array{problemas: array, ja_cadastrado: bool, livro_sugerido_id: ?int, linha_relacionada: ?int}> por id do item
     */
    public function analisarDuplicidades(array $itens, array $acervo): array
    {
        usort($itens, fn ($a, $b) => $a['linha'] <=> $b['linha']);
        $res = [];
        $primeiroHash = $primeiroIsbn = $primeiraObra = [];
        $copias = [];
        foreach ($itens as $item) {
            $id = $item['id'];
            $r = ['problemas' => [], 'ja_cadastrado' => false, 'livro_sugerido_id' => null, 'linha_relacionada' => null];
            $dup = function (string $codigo, string $mensagem, ?int $linha = null) use (&$r) {
                $r['problemas'][] = ['nivel' => 'duplicidade', 'codigo' => $codigo, 'campo' => null, 'mensagem' => $mensagem, 'linha_relacionada' => $linha];
                $r['linha_relacionada'] ??= $linha;
            };

            // Na própria planilha
            if (isset($primeiroHash[$item['hash']])) {
                $origem = $primeiroHash[$item['hash']];
                $dup('linha_identica', 'Registro idêntico à linha ' . $origem['linha'] . '. Pode ser outro exemplar ou uma repetição.', $origem['linha']);
                $copias[$origem['id']][] = $item['linha'];
            } else {
                $primeiroHash[$item['hash']] = $item;
                if ($item['isbn'] !== null && isset($primeiroIsbn[$item['isbn']])) {
                    $origem = $primeiroIsbn[$item['isbn']];
                    $dup('mesmo_isbn', 'Mesmo ISBN da linha ' . $origem['linha'] . '. Pode ser outro exemplar da mesma edição.', $origem['linha']);
                } elseif ($item['obra'] !== null && isset($primeiraObra[$item['obra']])) {
                    $origem = $primeiraObra[$item['obra']];
                    if ($item['isbn'] !== null && $origem['isbn'] !== null && $item['isbn'] !== $origem['isbn']) {
                        $r['problemas'][] = ['nivel' => 'info', 'codigo' => 'outra_edicao', 'campo' => null, 'mensagem' => 'Mesmo título e autor da linha ' . $origem['linha'] . ', mas com outro ISBN (provavelmente outra edição).', 'linha_relacionada' => $origem['linha']];
                    } else {
                        $dup('obra_semelhante', 'Título e autor semelhantes aos da linha ' . $origem['linha'] . '.', $origem['linha']);
                    }
                }
            }
            if ($item['isbn'] !== null) {
                $primeiroIsbn[$item['isbn']] ??= $item;
            }
            if ($item['obra'] !== null) {
                $primeiraObra[$item['obra']] ??= $item;
            }

            // No acervo já cadastrado
            if ($item['isbn'] !== null && isset($acervo['isbn'][$item['isbn']])) {
                $livroId = $acervo['isbn'][$item['isbn']];
                $r['ja_cadastrado'] = true;
                $r['livro_sugerido_id'] = $livroId;
                $dup('ja_cadastrado', 'Já existe no acervo um livro com este ISBN (cadastro nº ' . $livroId . ').');
            } elseif ($item['obra'] !== null && !empty($acervo['obra'][$item['obra']])) {
                foreach ($acervo['obra'][$item['obra']] as $livroId) {
                    $isbnLivro = $acervo['isbn_livro'][$livroId] ?? null;
                    if ($item['isbn'] !== null && $isbnLivro !== null && $isbnLivro !== $item['isbn']) {
                        continue; // outra edição
                    }
                    $r['ja_cadastrado'] = true;
                    $r['livro_sugerido_id'] = $livroId;
                    $dup('ja_cadastrado_semelhante', 'Já existe no acervo um livro com título e autor semelhantes (cadastro nº ' . $livroId . ').');
                    break;
                }
            }
            $res[$id] = $r;
        }
        foreach ($copias as $id => $linhas) {
            $res[$id]['problemas'][] = ['nivel' => 'info', 'codigo' => 'possui_copias', 'campo' => null, 'mensagem' => 'Esta linha se repete de forma idêntica nas linhas ' . implode(', ', $linhas) . '.', 'linha_relacionada' => null];
        }
        return $res;
    }

    /** Situação final da linha a partir dos problemas: erro > duplicidade > aviso > válido. */
    public static function situacao(array $problemas): string
    {
        $niveis = array_column($problemas, 'nivel');
        return match (true) {
            in_array('erro', $niveis, true) => 'erro',
            in_array('duplicidade', $niveis, true) => 'duplicidade',
            in_array('aviso', $niveis, true) => 'aviso',
            default => 'valido',
        };
    }
}
