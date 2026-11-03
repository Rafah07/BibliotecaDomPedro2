# Testes automáticos

Todos os testes rodam pela linha de comando. Os que usam banco **criam um banco temporário** `bdp_teste_*`, aplicam as migrações só nele, usam apenas dados fictícios e o **apagam no final**. O banco do `.env` (`DB_NAME`) nunca é usado para gravar nada.

A pasta `admin/tests/` é bloqueada no navegador (`.htaccess`) e os scripts recusam rodar fora da linha de comando.

## Requisitos

- PHP 8.2+ e as dependências do Composer instaladas.
- `.env` com `DB_HOST`, `DB_PORT`, `DB_USER` e `DB_PASSWORD` de um usuário que possa **criar e apagar bancos** (no XAMPP, `root` ou um usuário de testes). Se preferir não usar o usuário do `.env`, defina `TESTE_DB_USER` e `TESTE_DB_PASSWORD` só para os testes.
- O cliente `mysql` (para os testes de restauração). No XAMPP: `C:\xampp\mysql\bin\mysql.exe`; se não estiver no PATH, indique em `MYSQL_CLIENTE`.

## Comandos

No Linux ou no terminal do VS Code (Git Bash):

```bash
php admin/tests/painel-teste.php
php admin/tests/importacao-teste.php
php admin/tests/isbn-lote-teste.php
php admin/tests/alunos-importacao-teste.php
php admin/tests/emprestimos-teste.php
php admin/tests/inventario-teste.php
php admin/tests/cdd-teste.php
php admin/tests/relatorios-teste.php
php admin/tests/configuracoes-teste.php
php admin/tests/site-publico-teste.php
php admin/tests/codigos-etiquetas-teste.php
php admin/tests/fluxo-integracao-teste.php
php admin/tests/auditoria-seguranca-teste.php
php admin/tests/backup-restauracao-teste.php
php admin/tests/desempenho-teste.php            # --livros=3000 para uma rodada rápida
php admin/tests/ajuda-teste.php                 # Central de Ajuda (Etapa 10)
```

No **Prompt de Comando do Windows (XAMPP)**, dentro de `C:\xampp\htdocs\BibliotecaDomPedro2`:

```bat
C:\xampp\php\php.exe admin\tests\fluxo-integracao-teste.php
set MYSQL_CLIENTE=C:\xampp\mysql\bin\mysql.exe
C:\xampp\php\php.exe admin\tests\backup-restauracao-teste.php
set TESTE_URL_APACHE=http://localhost/BibliotecaDomPedro2/
C:\xampp\php\php.exe admin\tests\auditoria-seguranca-teste.php
```

Cada teste mostra uma linha `ok` ou `FALHOU` por verificação e termina com o total. O código de saída é `0` quando tudo passa.

## O que cada teste novo (Etapa 7) cobre

| Teste | Cobertura |
| --- | --- |
| `fluxo-integracao-teste.php` | Fluxo completo **pelas páginas**: livro fictício → exemplar → código BDP → localização → associação → etiqueta escrita (PDF) → aluno fictício → empréstimo → exemplar indisponível (painel e site) → devolução → disponível de novo → inventário → conferência → relatórios → históricos. Operações inválidas: código inexistente e incompleto, empréstimo duplicado, baixa de exemplar emprestado, devolução repetida, conferência repetida. Acesso sem login e com perfil sem permissão. |
| `auditoria-seguranca-teste.php` | Matriz de acesso direto por URL a todas as páginas do painel (anônimo, administrador, bibliotecária, auxiliar, consulta); reenvio forçado de **todos** os formulários como consulta, anônimo e com CSRF falso, conferindo que nenhuma tabela mudou; fixação de sessão; cookies `HttpOnly`/`SameSite`/`Secure`; HSTS; inatividade; logout; conta desativada; senha redefinida; bloqueio por tentativas; redirecionamento aberto; parâmetros maliciosos (SQL, XSS, tipos errados); privacidade do site público; nenhum script ou fonte externa. Com `TESTE_URL_APACHE`, confere no Apache que arquivos e pastas privados respondem 403/404 (cria e remove arquivos falsos para isso) e que `uploads/capas/` não executa PHP. |
| `backup-restauracao-teste.php` | Gera a cópia com dados fictícios em todos os módulos, restaura em **outro** banco temporário (recusa se o destino for o banco do `.env`) e compara estrutura, contagens, `CHECKSUM`, `AUTO_INCREMENT`, chaves estrangeiras, índices, gatilhos, views, procedimento e regras `CHECK`; confere órfãos, sequência BDP, gatilhos ativos, falha ao restaurar duas vezes e consistência da cópia com gravações simultâneas. |
| `desempenho-teste.php` | Gera 10.000 livros, 4.000 exemplares, 300 alunos, 600 empréstimos e um inventário fictícios; mede páginas do site e do painel e a exportação CSV; confere com `EXPLAIN` o uso de índices. `--livros=N` muda o tamanho; `--manter` não apaga o banco no final (para investigar). |

O `cdd-teste.php` foi corrigido nesta etapa: antes, a parte MySQL usava o banco do `.env`; agora usa banco temporário.

## Resultados da rodada final (2026-11-02)

Ambiente: Linux, PHP 8.3.6, MariaDB 10.11.14, Apache 2.4.58.

| Suíte | Resultado |
| --- | --- |
| painel | 89/89 |
| importacao | 43/43 |
| isbn-lote | 54/54 |
| alunos-importacao | 75/75 |
| emprestimos | 109/109 |
| inventario | 139/139 |
| cdd | 48/48 |
| relatorios | 121/121 |
| configuracoes | 96/96 |
| site-publico | 119/119 |
| codigos-etiquetas | 88/88 |
| fluxo-integracao | 66/66 |
| auditoria-seguranca (com Apache) | 135/135 |
| backup-restauracao | 34/34 |
| desempenho (10.000 livros) | 32/32 |
| **Total** | **1.248 verificações, 0 falhas** |

O banco do `.env` foi conferido com `CHECKSUM TABLE` antes e depois da rodada: inalterado.

Também executados:
- `php -l` em todos os arquivos PHP: sem erros.
- Fluxo de integração e site público com o PHP **sem** `intl` e `iconv` (`PHP_INI_SCAN_DIR` apontando para uma configuração sem essas extensões): 66/66 e 119/119.
- Verificação de telas com Playwright (Chromium): 31 páginas em 360, 390, 768, 1024, 1366 e 1920 px; sem rolagem horizontal depois das correções, foco visível no teclado e nenhum controle sem nome acessível. (Script de verificação usado durante a auditoria, não incluído no repositório.)

## Limitações

- **Não executados**: Windows/XAMPP real, MySQL 8, Nginx, IIS, navegadores além do Chromium, leitores de tela reais e teste de invasão independente.
- Os tempos de desempenho foram medidos no servidor embutido do PHP em Linux; no XAMPP podem ser maiores.
- Testes passando não garantem ausência de falhas: eles cobrem os casos descritos acima.
