<?php
/**
 * Gera o modo escuro dos arquivos CSS do site público, do painel e dos módulos.
 *
 * Uso (na raiz do projeto):   php ferramentas/gerar-tema-escuro.php
 *
 * Para cada arquivo CSS, o script lê as regras escritas à mão (a parte acima do marcador
 * "MODO ESCURO — GERADO") e acrescenta no fim do arquivo uma "cópia escura" de cada declaração de cor:
 * mesmo seletor, mesma ordem e mesmas @media, mas só vale quando <html data-tema="escuro">.
 * O prefixo :where(...) não aumenta a especificidade, então a ordem original das regras é mantida.
 *
 * Como as cores mudam:
 *   - fundos claros viram superfícies escuras (o branco vira a cor dos cartões);
 *   - textos escuros viram claros (o azul institucional vira um azul claro legível);
 *   - bordas claras viram bordas escuras;
 *   - cores fortes (menu azul, botões, faixas coloridas) ficam iguais: a identidade visual é mantida.
 * Tudo fica dentro de @media screen: impressão, etiquetas e PDFs continuam claros.
 *
 * Sempre que mudar um CSS, rode o script de novo (ele substitui o bloco gerado anterior).
 * Ajustes finos feitos à mão usam o seletor html[data-tema="escuro"] na parte normal do arquivo
 * e não são copiados pelo gerador.
 */

const MARCADOR = '/* ==== MODO ESCURO — GERADO por ferramentas/gerar-tema-escuro.php: não edite abaixo desta linha ==== */';
const RAIZ_TEMA = ':where(html[data-tema="escuro"])';

/* ------------------------------------------------------------------ leitura do CSS */

function sem_comentarios(string $css): string
{
    return preg_replace('#/\*.*?\*/#s', '', $css);
}

/** Árvore simples: ['tipo'=>'regra','seletor','decl'=>[[prop,valor]]] ou ['tipo'=>'at','prelude','filhos'] */
function analisar(string $css): array
{
    $i = 0;
    return analisar_bloco($css, $i, strlen($css));
}

function analisar_bloco(string $css, int &$i, int $fim): array
{
    $itens = [];
    $buffer = '';
    while ($i < $fim) {
        $c = $css[$i];
        if ($c === '"' || $c === "'") {
            $j = strpos($css, $c, $i + 1);
            $j = $j === false ? $fim : $j;
            $buffer .= substr($css, $i, $j - $i + 1);
            $i = $j + 1;
            continue;
        }
        if ($c === '}') {
            $i++;
            return $itens;
        }
        if ($c === ';' && str_starts_with(trim($buffer), '@')) {
            $buffer = ''; // @import, @charset...
            $i++;
            continue;
        }
        if ($c === '{') {
            $prelude = trim($buffer);
            $buffer = '';
            $i++;
            if (preg_match('/^@(media|supports|container|layer)\b/i', $prelude)) {
                $itens[] = ['tipo' => 'at', 'prelude' => $prelude, 'filhos' => analisar_bloco($css, $i, $fim)];
            } elseif (str_starts_with($prelude, '@')) {
                pular_bloco($css, $i, $fim); // @keyframes, @font-face, @page
            } else {
                $corpo = ler_corpo($css, $i, $fim);
                $itens[] = ['tipo' => 'regra', 'seletor' => $prelude, 'decl' => declaracoes($corpo)];
            }
            continue;
        }
        $buffer .= $c;
        $i++;
    }
    return $itens;
}

function pular_bloco(string $css, int &$i, int $fim): void
{
    $nivel = 1;
    while ($i < $fim && $nivel > 0) {
        if ($css[$i] === '{') $nivel++;
        if ($css[$i] === '}') $nivel--;
        $i++;
    }
}

function ler_corpo(string $css, int &$i, int $fim): string
{
    $inicio = $i;
    $nivel = 1;
    while ($i < $fim) {
        $c = $css[$i];
        if ($c === '"' || $c === "'") {
            $j = strpos($css, $c, $i + 1);
            $i = $j === false ? $fim : $j + 1;
            continue;
        }
        if ($c === '{') $nivel++;
        if ($c === '}' && --$nivel === 0) {
            $corpo = substr($css, $inicio, $i - $inicio);
            $i++;
            return $corpo;
        }
        $i++;
    }
    return substr($css, $inicio);
}

/** @return array<int,array{0:string,1:string}> */
function declaracoes(string $corpo): array
{
    $saida = [];
    foreach (dividir($corpo, ';') as $parte) {
        $pos = strpos($parte, ':');
        if ($pos === false) continue;
        $prop = strtolower(trim(substr($parte, 0, $pos)));
        $valor = trim(substr($parte, $pos + 1));
        if ($prop !== '' && $valor !== '') $saida[] = [$prop, $valor];
    }
    return $saida;
}

/** Divide por um separador ignorando parênteses e aspas. */
function dividir(string $texto, string $sep): array
{
    $partes = [];
    $nivel = 0;
    $atual = '';
    $aspas = null;
    $n = strlen($texto);
    for ($i = 0; $i < $n; $i++) {
        $c = $texto[$i];
        if ($aspas !== null) {
            if ($c === $aspas) $aspas = null;
            $atual .= $c;
            continue;
        }
        if ($c === '"' || $c === "'") $aspas = $c;
        elseif ($c === '(') $nivel++;
        elseif ($c === ')') $nivel--;
        elseif ($c === $sep && $nivel === 0) {
            $partes[] = $atual;
            $atual = '';
            continue;
        }
        $atual .= $c;
    }
    if (trim($atual) !== '') $partes[] = $atual;
    return $partes;
}

function coletar_tokens(array $itens, array &$tokens, array &$locais): void
{
    foreach ($itens as $item) {
        if ($item['tipo'] === 'at') {
            coletar_tokens($item['filhos'], $tokens, $locais);
            continue;
        }
        $raiz = trim($item['seletor']) === ':root';
        foreach ($item['decl'] as [$prop, $valor]) {
            if (!str_starts_with($prop, '--')) continue;
            if ($raiz && !isset($tokens[$prop])) $tokens[$prop] = $valor;
            elseif (!$raiz) $locais[$prop] = true;
        }
    }
}

/* ------------------------------------------------------------------ geração */

const PAPEL = [
    'color' => 'texto', 'fill' => 'texto', 'stroke' => 'texto', 'caret-color' => 'texto',
    'text-decoration-color' => 'texto', '-webkit-text-fill-color' => 'texto', 'column-rule-color' => 'borda',
    'background' => 'fundo', 'background-color' => 'fundo', 'background-image' => 'fundo',
    'border' => 'borda', 'border-top' => 'borda', 'border-right' => 'borda', 'border-bottom' => 'borda', 'border-left' => 'borda',
    'border-color' => 'borda', 'border-top-color' => 'borda', 'border-right-color' => 'borda',
    'border-bottom-color' => 'borda', 'border-left-color' => 'borda', 'border-block' => 'borda', 'border-inline' => 'borda',
    'border-block-start' => 'borda', 'border-block-end' => 'borda', 'border-inline-start' => 'borda', 'border-inline-end' => 'borda',
    'border-block-color' => 'borda', 'border-inline-color' => 'borda',
    'outline' => 'borda', 'outline-color' => 'borda', 'box-shadow' => 'sombra',
];

function gerar(array $itens, array $tokens, array $locais, int $nivel): string
{
    $saida = '';
    $recuo = str_repeat('    ', $nivel);
    foreach ($itens as $item) {
        if ($item['tipo'] === 'at') {
            if (so_impressao($item['prelude'])) continue;
            $dentro = gerar($item['filhos'], $tokens, $locais, $nivel + 1);
            if (trim($dentro) !== '') $saida .= $recuo . $item['prelude'] . " {\n" . $dentro . $recuo . "}\n";
            continue;
        }
        $seletor = $item['seletor'];
        if (str_contains($seletor, 'data-tema')) continue; // ajuste feito à mão
        $raiz = trim($seletor) === ':root';

        // Cor de fundo forte e opaca na própria regra: o texto dela fica como está (contraste já pensado).
        $fundoForte = false;
        foreach ($item['decl'] as [$prop, $valor]) {
            if (in_array($prop, ['background', 'background-color', 'background-image'], true)
                && fundo_forte(resolver($valor, $tokens, $locais))) {
                $fundoForte = true;
            }
        }

        $linhas = [];
        foreach ($item['decl'] as [$prop, $valor]) {
            if (str_starts_with($prop, '--')) {
                if (!contem_cor($valor)) continue;
                $novo = mapear_valor($valor, papel_token($prop), false);
                if ($novo !== $valor) $linhas[] = "$prop: $novo";
                continue;
            }
            if ($raiz || !isset(PAPEL[$prop])) continue;
            $papel = PAPEL[$prop];
            $importante = '';
            if (preg_match('/\s*!important\s*$/i', $valor)) {
                $importante = ' !important';
                $valor = preg_replace('/\s*!important\s*$/i', '', $valor);
            }
            $resolvido = resolver($valor, $tokens, $locais);
            foreach (gemeas($prop, $valor, $resolvido, $papel, $papel === 'texto' && $fundoForte) as [$p, $v]) {
                $linhas[] = "$p: $v$importante";
            }
        }
        if (!$linhas) continue;
        $saida .= $recuo . prefixar($seletor) . " { " . implode('; ', $linhas) . "; }\n";
    }
    return $saida;
}

function so_impressao(string $prelude): bool
{
    $q = strtolower(trim(substr($prelude, 6)));
    foreach (dividir($q, ',') as $parte) {
        if (!preg_match('/^\s*print\b/', $parte)) return false;
    }
    return true;
}

function prefixar(string $seletor): string
{
    $saida = [];
    foreach (dividir($seletor, ',') as $s) {
        $s = trim($s);
        if ($s === ':root') {
            $saida[] = ':root:where([data-tema="escuro"])';
        } elseif (preg_match('/^html\b/', $s)) {
            $saida[] = preg_replace('/^html/', 'html:where([data-tema="escuro"])', $s);
        } elseif (preg_match('/^:root\b/', $s)) {
            $saida[] = preg_replace('/^:root/', ':root:where([data-tema="escuro"])', $s);
        } else {
            $saida[] = RAIZ_TEMA . ' ' . $s;
        }
    }
    return implode(', ', $saida);
}

function papel_token(string $nome): string
{
    if (preg_match('/(sombra|shadow)/', $nome)) return 'sombra';
    if (preg_match('/(texto|text|muted|fg|foreground|-cor-letra)/', $nome)) return 'texto';
    if (preg_match('/(borda|border|ring|linha)/', $nome)) return 'borda';
    return 'fundo';
}

/** Substitui var(--token) definidos só em :root pelo valor claro, para mapear de acordo com o uso. */
function resolver(string $valor, array $tokens, array $locais, int $prof = 0): string
{
    if ($prof > 5 || !str_contains($valor, 'var(')) return $valor;
    $novo = preg_replace_callback('/var\(\s*(--[\w-]+)\s*(?:,\s*((?:[^()]|\([^()]*\))*))?\)/', function ($m) use ($tokens, $locais) {
        $nome = $m[1];
        if (isset($tokens[$nome]) && !isset($locais[$nome])) return $tokens[$nome];
        if (!isset($tokens[$nome]) && isset($m[2]) && $m[2] !== '' && !isset($locais[$nome])) return $m[2];
        return $m[0];
    }, $valor);
    return $novo === $valor ? $valor : resolver($novo, $tokens, $locais, $prof + 1);
}

/**
 * Declarações gêmeas de uma declaração original. Atalhos (border, background, outline) viram
 * as propriedades de cor correspondentes, para não desfazer larguras/estilos definidos depois.
 * @return array<int,array{0:string,1:string}>
 */
function gemeas(string $prop, string $original, string $resolvido, string $papel, bool $manterTexto): array
{
    // Tokens de :root viram o valor claro resolvido (o token escuro poderia ter outro papel, ex.: --adm-white
    // usado como cor de texto); variáveis locais continuam como var() e seguem o próprio mapeamento.
    $mapeado = function (string $v) use ($papel, $manterTexto): string {
        return $manterTexto ? $v : mapear_valor($v, $papel, true);
    };

    if ($prop === 'background') {
        [$cor, $imagem] = separar_fundo($resolvido);
        return [['background-color', $cor === null ? 'transparent' : $mapeado($cor)], ['background-image', $imagem === null ? 'none' : $mapeado($imagem)]];
    }
    if (preg_match('/^border(-(top|right|bottom|left|block|inline|block-start|block-end|inline-start|inline-end))?$/', $prop) || $prop === 'outline') {
        $cor = cor_do_atalho($resolvido);
        $alvo = $prop === 'outline' ? 'outline-color' : ($prop === 'border' ? 'border-color' : $prop . '-color');
        if (in_array($prop, ['border-block', 'border-inline'], true)) $alvo = $prop . '-color';
        if ($cor === null) {
            if (preg_match('/^(none|0|initial|unset|inherit)$/i', trim($resolvido))) return [[$alvo, 'currentcolor']];
            if (!str_contains($resolvido, 'var(')) return [[$alvo, 'currentcolor']];
            return []; // var() local sem cor conhecida: deixa a original valer
        }
        return [[$alvo, $mapeado($cor)]];
    }
    return [[$prop, $mapeado($resolvido)]];
}

/** Separa o atalho background em [cor, imagem]. */
function separar_fundo(string $valor): array
{
    $cor = null;
    $imagens = [];
    foreach (dividir($valor, ',') as $camada) {
        $camada = trim($camada);
        if (preg_match('/((?:repeating-)?(?:linear|radial|conic)-gradient\((?:[^()]|\([^()]*\))*\)|url\((?:[^()])*\))/i', $camada, $m)) {
            $imagens[] = $m[1];
            $resto = trim(str_replace($m[1], '', $camada));
        } else {
            $resto = $camada;
        }
        foreach (palavras($resto) as $p) {
            if (eh_cor($p)) $cor = $p;
        }
    }
    if ($cor === null && preg_match('/^var\(/', trim($valor)) && !$imagens) $cor = trim($valor);
    return [$cor, $imagens ? implode(', ', $imagens) : null];
}

function cor_do_atalho(string $valor): ?string
{
    foreach (palavras($valor) as $p) {
        if (eh_cor($p)) return $p;
    }
    if (preg_match('/var\(/', $valor) && !preg_match('/\d/', preg_replace('/var\([^)]*\)/', '', $valor))) {
        return null;
    }
    return null;
}

function palavras(string $valor): array
{
    return array_values(array_filter(array_map('trim', dividir($valor, ' ')), fn ($p) => $p !== ''));
}

function eh_cor(string $p): bool
{
    return parse_cor($p) !== null || in_array(strtolower($p), ['transparent', 'currentcolor'], true);
}

function contem_cor(string $valor): bool
{
    return (bool)preg_match('/#[0-9a-f]{3,8}\b|rgba?\(|hsla?\(|\b(white|black)\b/i', $valor);
}

/* ------------------------------------------------------------------ cores */

const NOMES = ['white' => [255, 255, 255, 1.0], 'black' => [0, 0, 0, 1.0], 'red' => [255, 0, 0, 1.0], 'green' => [0, 128, 0, 1.0]];

/** @return array{0:int,1:int,2:int,3:float}|null */
function parse_cor(string $c): ?array
{
    $c = strtolower(trim($c));
    if (isset(NOMES[$c])) return NOMES[$c];
    if (preg_match('/^#([0-9a-f]{3,8})$/', $c, $m)) {
        $h = $m[1];
        if (strlen($h) === 3 || strlen($h) === 4) {
            $h = implode('', array_map(fn ($x) => $x . $x, str_split($h)));
        }
        if (strlen($h) !== 6 && strlen($h) !== 8) return null;
        $a = strlen($h) === 8 ? hexdec(substr($h, 6, 2)) / 255 : 1.0;
        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2)), $a];
    }
    if (preg_match('/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:\s*[,\/]\s*([\d.]+%?))?\s*\)$/', $c, $m)) {
        $a = isset($m[4]) ? (str_ends_with($m[4], '%') ? (float)$m[4] / 100 : (float)$m[4]) : 1.0;
        return [(int)$m[1], (int)$m[2], (int)$m[3], $a];
    }
    return null;
}

function mapear_valor(string $valor, string $papel, bool $uso): string
{
    return preg_replace_callback('/#[0-9a-fA-F]{3,8}\b|rgba?\([^()]*\)|\b(?:white|black)\b/i', function ($m) use ($papel) {
        $cor = parse_cor($m[0]);
        if ($cor === null) return $m[0];
        $nova = mapear_cor($cor, $papel);
        return $nova === $cor ? $m[0] : formatar($nova);
    }, $valor);
}

function fundo_forte(string $valor): bool
{
    if (preg_match_all('/#[0-9a-fA-F]{3,8}\b|rgba?\([^()]*\)|\b(?:white|black)\b/i', $valor, $m)) {
        foreach ($m[0] as $c) {
            $cor = parse_cor($c);
            if ($cor === null || $cor[3] < 0.5) continue;
            [, , $l] = rgb_hsl($cor[0], $cor[1], $cor[2]);
            if ($l < 0.7) return true;
        }
    }
    return false;
}

function mapear_cor(array $cor, string $papel): array
{
    [$r, $g, $b, $a] = $cor;
    [$h, $s, $l] = rgb_hsl($r, $g, $b);
    $neutra = $s < 0.12;
    if ($neutra) { $h = 215; }

    if ($papel === 'fundo') {
        if ($l < 0.7) {
            // escuro e quase transparente (sombreados de hover): vira um clareado sutil
            if ($a < 0.3 && $l < 0.35) return [255, 255, 255, round(min(0.12, $a * 1.2), 3)];
            return $cor; // cores fortes: menu, botões, faixas
        }
        if ($a < 0.5) return $cor; // véus claros sobre fundos coloridos (ex.: hover do menu azul)
        if ($l >= 0.995) { $nl = 0.155; }          // branco → cartão
        else { $nl = 0.085 + (1 - $l) * 1.0; }     // demais claros → superfícies escuras
        $ns = $neutra ? 0.24 : min($s, 0.55) * 0.75;
        return hsl_rgb($h, $ns, min($nl, 0.32), $a);
    }
    if ($papel === 'texto') {
        if ($l > 0.55) return $cor;
        $nl = 0.93 - $l * 0.55;
        $ns = $neutra ? 0.14 : min($s, 0.75);
        if (!$neutra && $s > 0.3) $nl = max($nl, 0.72);
        return hsl_rgb($h, $ns, $nl, $a);
    }
    if ($papel === 'sombra' && $l < 0.6) return $cor; // sombras escuras continuam sombras
    // borda
    if ($l < 0.6 || ($l < 0.75 && $s >= 0.35)) { // bordas fortes (azul, dourado, vermelho) ficam iguais
        if ($a < 0.3 && $l < 0.35) return [255, 255, 255, round(min(0.14, $a * 1.4), 3)];
        return $cor;
    }
    if ($a < 0.5 && $l > 0.9) return $cor;
    $nl = 0.17 + (1 - $l) * 0.8;
    $ns = $neutra ? 0.2 : min($s, 0.5) * 0.7;
    return hsl_rgb($h, $ns, min($nl, 0.4), $a);
}

function rgb_hsl(int $r, int $g, int $b): array
{
    $r /= 255; $g /= 255; $b /= 255;
    $max = max($r, $g, $b); $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    if ($max === $min) return [0.0, 0.0, $l];
    $d = $max - $min;
    $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
    if ($max === $r) $h = ($g - $b) / $d + ($g < $b ? 6 : 0);
    elseif ($max === $g) $h = ($b - $r) / $d + 2;
    else $h = ($r - $g) / $d + 4;
    return [$h * 60, $s, $l];
}

function hsl_rgb(float $h, float $s, float $l, float $a): array
{
    $h = fmod($h, 360) / 360;
    if ($s == 0) { $v = (int)round($l * 255); return [$v, $v, $v, $a]; }
    $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
    $p = 2 * $l - $q;
    $f = function ($t) use ($p, $q) {
        if ($t < 0) $t += 1;
        if ($t > 1) $t -= 1;
        if ($t < 1 / 6) return $p + ($q - $p) * 6 * $t;
        if ($t < 1 / 2) return $q;
        if ($t < 2 / 3) return $p + ($q - $p) * (2 / 3 - $t) * 6;
        return $p;
    };
    return [(int)round($f($h + 1 / 3) * 255), (int)round($f($h) * 255), (int)round($f($h - 1 / 3) * 255), $a];
}

function formatar(array $c): string
{
    [$r, $g, $b, $a] = $c;
    if ($a >= 0.999) return sprintf('#%02x%02x%02x', $r, $g, $b);
    return sprintf('rgba(%d, %d, %d, %s)', $r, $g, $b, rtrim(rtrim(number_format($a, 3, '.', ''), '0'), '.'));
}


function principal(): void
{
    $raiz = dirname(__DIR__);
    $arquivos = array_merge(
        [$raiz . '/assets/css/style.css'],
        glob($raiz . '/admin/assets/css/*.css') ?: [],
        glob($raiz . '/modulos/*/assets/*.css') ?: []
    );

    $tokens = [];          // --nome => valor claro (só os definidos em :root)
    $tokensLocais = [];    // variáveis redefinidas fora de :root (não são resolvidas)
    $fontes = [];
    foreach ($arquivos as $arquivo) {
        $css = file_get_contents($arquivo);
        $pos = strpos($css, MARCADOR);
        if ($pos !== false) {
            $css = rtrim(substr($css, 0, $pos)) . "\n";
        }
        $fontes[$arquivo] = $css;
        coletar_tokens(analisar(sem_comentarios($css)), $tokens, $tokensLocais);
    }

    foreach ($fontes as $arquivo => $css) {
        $arvore = analisar(sem_comentarios($css));
        $saida = gerar($arvore, $tokens, $tokensLocais, 1);
        if (trim($saida) === '') {
            file_put_contents($arquivo, $css);
            continue;
        }
        $bloco = "\n" . MARCADOR . "\n@media screen {\n" . $saida . "}\n";
        file_put_contents($arquivo, $css . $bloco);
        echo str_replace($raiz . '/', '', $arquivo) . ": modo escuro gerado\n";
    }
}

principal();
