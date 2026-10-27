<?php
/**
 * Configuração do banco MySQL do painel.
 *
 * Copie este arquivo para "database.php" (na mesma pasta) e preencha os dados.
 * O arquivo database.php não vai para o Git (.gitignore), para não expor a senha.
 * Também é possível usar variáveis de ambiente: BDP_DB_HOST, BDP_DB_PORT,
 * BDP_DB_NAME, BDP_DB_USER, BDP_DB_PASS.
 *
 * Sem configuração, o painel funciona em modo demonstrativo (dados fictícios,
 * alterações guardadas apenas na sessão do navegador).
 */
return [
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'biblioteca',
    'username' => 'biblioteca',
    'password' => '',

    // Fontes bibliográficas consultadas pelo módulo de classificação CDD.
    'fontes_cdd' => [
        // Contato enviado no cabeçalho User-Agent, como pedem as fontes públicas.
        'contato' => 'biblioteca@escola.exemplo',
        // Intervalo mínimo entre duas consultas à mesma fonte, em segundos.
        'intervalo_segundos' => 1.5,
        'loc' => true,          // Catálogo da Library of Congress (SRU, campo MARC 082)
        'openlibrary' => true,  // Open Library (registros públicos; CDD informada por colaboradores)
        // WebDewey exige licença da OCLC. Deixe false até a instituição ter acesso autorizado.
        'webdewey' => false,
    ],
];
