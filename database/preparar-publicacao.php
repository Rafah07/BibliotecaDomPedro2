<?php
/**
 * Monta o pacote para enviar à hospedagem (Hostinger, cPanel ou outra), só com o que o site precisa.
 *
 * Uso (na pasta do projeto, no XAMPP):
 *   C:\xampp\php\php.exe database/preparar-publicacao.php [pasta-de-saída]
 *
 * Padrão da saída: C:\xampp\publicacao_biblioteca (fora do htdocs, que é servido pela web).
 * Cria a pasta "site" com os arquivos e, se o Windows tiver o tar (Windows 10/11 têm), o arquivo
 * "biblioteca-site-AAAA-MM-DD.zip" pronto para o Gerenciador de Arquivos da hospedagem.
 *
 * Entra: os arquivos versionados no Git (o .gitignore já tira .env, backups, logs e planilhas) e as
 * capas enviadas (uploads/capas). Ficam de fora: testes (admin/tests), documentação (docs), seeds de
 * importação, arquivos do Git e do editor. As pastas graváveis (storage/importacoes, storage/logs,
 * storage/backups) vão vazias, só com o .htaccess que as protege.
 * Só entram arquivos já commitados (git ls-files) e as capas: faça o commit antes de montar o pacote.
 * Nada é apagado nem alterado no projeto.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raiz = realpath(__DIR__ . '/..');
$saida = rtrim($argv[1] ?? 'C:\\xampp\\publicacao_biblioteca', '/\\');
$destino = $saida . DIRECTORY_SEPARATOR . 'site';

if (str_starts_with(realpath($saida) ?: $saida, $raiz)) {
    fwrite(STDERR, "Escolha uma pasta de saída fora do projeto (ex.: C:\\xampp\\publicacao_biblioteca).\n");
    exit(1);
}
if (is_dir($destino) && (new FilesystemIterator($destino))->valid()) {
    fwrite(STDERR, "A pasta $destino já tem arquivos. Apague-a (ou escolha outra pasta) e rode de novo.\n");
    exit(1);
}

exec('git -C ' . escapeshellarg($raiz) . ' ls-files', $arquivos, $codigo);
if ($codigo !== 0 || !$arquivos) {
    fwrite(STDERR, "Não foi possível listar os arquivos do Git. Rode o script dentro da pasta do projeto.\n");
    exit(1);
}

$fora = '#^(admin/tests/|docs/|database/seeds/|\.git|\.github/|\.vscode/|\.idea/|\.claude/)|(^|/)\.(gitignore|gitattributes|editorconfig)$#';
$copiados = 0;
$copiar = function (string $relativo) use ($raiz, $destino, &$copiados): void {
    $origem = $raiz . '/' . $relativo;
    if (!is_file($origem)) {
        return;
    }
    $alvo = $destino . '/' . $relativo;
    if (!is_dir(dirname($alvo))) {
        mkdir(dirname($alvo), 0775, true);
    }
    copy($origem, $alvo);
    $copiados++;
};
foreach ($arquivos as $relativo) {
    if (!preg_match($fora, $relativo)) {
        $copiar($relativo);
    }
}

// Capas enviadas pelo painel (não ficam no Git, mas fazem parte do site).
$capas = 0;
foreach (glob($raiz . '/uploads/capas/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $capa) {
    $copiar('uploads/capas/' . basename($capa));
    $capas++;
}
foreach (['storage/importacoes', 'storage/logs', 'storage/backups', 'uploads/capas'] as $pasta) {
    if (!is_dir($destino . '/' . $pasta)) {
        mkdir($destino . '/' . $pasta, 0775, true);
    }
}

echo "Pacote montado em: $destino\n";
echo "  $copiados arquivo(s), incluindo $capas capa(s).\n";
echo "  Fora do pacote: .env, testes, documentação, backups, logs e planilhas.\n";

$zip = $saida . DIRECTORY_SEPARATOR . 'biblioteca-site-' . date('Y-m-d') . '.zip';
// O tar do Windows (bsdtar) cria .zip; o do Git Bash, que às vezes vem antes no PATH, não.
$tar = (getenv('SystemRoot') ?: 'C:\Windows') . '\System32\tar.exe';
if (DIRECTORY_SEPARATOR === '\\' && !is_file($zip) && is_file($tar)) {
    exec('"' . $tar . '" -a -c -f ' . escapeshellarg($zip) . ' -C ' . escapeshellarg($destino) . ' . 2>&1', $saidaTar, $codTar);
    echo $codTar === 0 && is_file($zip)
        ? "Arquivo para enviar: $zip\n"
        : "Não foi possível criar o .zip automaticamente. Compacte a pasta \"site\" pelo Windows (botão direito → Compactar).\n";
}
echo "Próximo passo: docs/publicacao-hostinger.md (seção 4).\n";
