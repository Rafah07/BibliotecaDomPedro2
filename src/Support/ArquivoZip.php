<?php

namespace Biblioteca\Support;

use RuntimeException;
use ZipArchive;

/**
 * Leitura (somente) de arquivos .zip, como o .xlsx.
 *
 * Usa a extensão zip do PHP quando ela está ligada; sem ela (comum no XAMPP, onde
 * "extension=zip" vem comentada no php.ini), lê o arquivo por conta própria com a
 * zlib, que sempre vem no PHP. Só entende o necessário para planilhas: arquivos
 * guardados sem compressão ou com "deflate", sem senha e sem ZIP64.
 */
final class ArquivoZip
{
    private ?ZipArchive $zip = null;
    /** @var resource|null */
    private $arquivo = null;
    /** @var array<string, array{metodo: int, compactado: int, tamanho: int, local: int, flags: int}> */
    private array $entradas = [];

    /**
     * @param bool $usarExtensao false força a leitura própria (usado nos testes)
     * @throws RuntimeException com mensagem segura para mostrar ao usuário
     */
    public function __construct(private string $caminho, bool $usarExtensao = true)
    {
        if ($usarExtensao && class_exists(ZipArchive::class)) {
            $zip = new ZipArchive();
            if ($zip->open($caminho, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('O arquivo .xlsx está corrompido ou não pôde ser aberto.');
            }
            $this->zip = $zip;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $info = $zip->statIndex($i);
                if ($info !== false) {
                    $this->entradas[(string)$info['name']] = ['metodo' => (int)$info['comp_method'], 'compactado' => (int)$info['comp_size'], 'tamanho' => (int)$info['size'], 'local' => 0, 'flags' => 0];
                }
            }
            return;
        }
        if (!function_exists('gzinflate')) {
            throw new RuntimeException('Este PHP não tem as extensões zip nem zlib, necessárias para ler .xlsx. Salve a planilha como CSV.');
        }
        $arquivo = @fopen($caminho, 'rb');
        if ($arquivo === false) {
            throw new RuntimeException('O arquivo .xlsx não pôde ser aberto.');
        }
        $this->arquivo = $arquivo;
        $this->lerDiretorio();
    }

    public function __destruct()
    {
        $this->fechar();
    }

    public function fechar(): void
    {
        $this->zip?->close();
        $this->zip = null;
        if (is_resource($this->arquivo)) {
            fclose($this->arquivo);
        }
        $this->arquivo = null;
    }

    /** @return array<string, int> nome => tamanho descompactado */
    public function entradas(): array
    {
        return array_map(fn ($e) => $e['tamanho'], $this->entradas);
    }

    public function existe(string $nome): bool
    {
        return isset($this->entradas[$nome]);
    }

    /** Conteúdo descompactado da entrada, ou null se ela não existe. */
    public function ler(string $nome): ?string
    {
        if (!isset($this->entradas[$nome])) {
            return null;
        }
        if ($this->zip !== null) {
            $conteudo = $this->zip->getFromName($nome);
            if ($conteudo === false) {
                throw new RuntimeException('O arquivo .xlsx está corrompido ou não pôde ser aberto.');
            }
            return $conteudo;
        }
        $e = $this->entradas[$nome];
        if ($e['flags'] & 0x1) {
            throw new RuntimeException('A planilha está protegida por senha. Salve uma cópia sem senha e envie novamente.');
        }
        if (fseek($this->arquivo, $e['local']) !== 0) {
            throw new RuntimeException('O arquivo .xlsx está corrompido.');
        }
        $cabecalho = fread($this->arquivo, 30);
        if ($cabecalho === false || strlen($cabecalho) < 30 || substr($cabecalho, 0, 4) !== "PK\x03\x04") {
            throw new RuntimeException('O arquivo .xlsx está corrompido.');
        }
        $tamanhos = unpack('vnome/vextra', substr($cabecalho, 26, 4));
        fseek($this->arquivo, $e['local'] + 30 + $tamanhos['nome'] + $tamanhos['extra']);
        $dados = $e['compactado'] > 0 ? fread($this->arquivo, $e['compactado']) : '';
        if ($dados === false || strlen($dados) !== $e['compactado']) {
            throw new RuntimeException('O arquivo .xlsx está corrompido.');
        }
        if ($e['metodo'] === 0) {
            return $dados;
        }
        if ($e['metodo'] !== 8) {
            throw new RuntimeException('A planilha usa uma compressão que o sistema não lê. Abra-a no Excel, salve novamente e tente outra vez.');
        }
        if ($e['tamanho'] === 0) {
            return '';
        }
        $conteudo = @gzinflate($dados, $e['tamanho']);
        if ($conteudo === false) {
            throw new RuntimeException('O arquivo .xlsx está corrompido.');
        }
        return $conteudo;
    }

    /** Lê o diretório central (fica no fim do arquivo). */
    private function lerDiretorio(): void
    {
        $tamanhoArquivo = (int)filesize($this->caminho);
        $janela = min($tamanhoArquivo, 65557); // 22 bytes do registro final + comentário de até 64 KB
        if ($janela < 22) {
            throw new RuntimeException('O arquivo .xlsx está corrompido ou não pôde ser aberto.');
        }
        fseek($this->arquivo, $tamanhoArquivo - $janela);
        $fim = (string)fread($this->arquivo, $janela);
        $pos = strrpos($fim, "PK\x05\x06");
        if ($pos === false || strlen($fim) - $pos < 22) {
            throw new RuntimeException('O arquivo .xlsx está corrompido ou não pôde ser aberto.');
        }
        $registro = unpack('vdisco/vdiscoDir/vnoDisco/vtotal/Vtamanho/Vinicio', substr($fim, $pos + 4, 16));
        if ($registro['total'] === 0xFFFF || $registro['inicio'] === 0xFFFFFFFF) {
            throw new RuntimeException('A planilha é grande demais para ser processada. Divida-a em partes menores.');
        }
        if ($registro['inicio'] + $registro['tamanho'] > $tamanhoArquivo) {
            throw new RuntimeException('O arquivo .xlsx está corrompido ou não pôde ser aberto.');
        }
        fseek($this->arquivo, $registro['inicio']);
        $diretorio = $registro['tamanho'] > 0 ? (string)fread($this->arquivo, $registro['tamanho']) : '';
        $p = 0;
        for ($i = 0; $i < $registro['total']; $i++) {
            if (substr($diretorio, $p, 4) !== "PK\x01\x02" || strlen($diretorio) < $p + 46) {
                throw new RuntimeException('O arquivo .xlsx está corrompido ou não pôde ser aberto.');
            }
            $c = unpack('vflags/vmetodo/vhora/vdata/Vcrc/Vcompactado/Vtamanho/vnome/vextra/vcomentario/vdiscoIni/vattrInt/VattrExt/Vlocal', substr($diretorio, $p + 8, 38));
            $nome = substr($diretorio, $p + 46, $c['nome']);
            if ($c['compactado'] === 0xFFFFFFFF || $c['tamanho'] === 0xFFFFFFFF || $c['local'] === 0xFFFFFFFF) {
                throw new RuntimeException('A planilha é grande demais para ser processada. Divida-a em partes menores.');
            }
            $this->entradas[$nome] = ['metodo' => $c['metodo'], 'compactado' => $c['compactado'], 'tamanho' => $c['tamanho'], 'local' => $c['local'], 'flags' => $c['flags']];
            $p += 46 + $c['nome'] + $c['extra'] + $c['comentario'];
        }
    }
}
