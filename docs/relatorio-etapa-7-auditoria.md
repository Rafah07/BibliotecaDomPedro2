# Relatório da Etapa 7 — Revisão final, segurança, testes e preparação para publicação

Data: 2026-11-02. Base: `main` atualizada, já com os PRs #18 (configurações) e #19 (códigos BDP escritos) integrados.

> **Este relatório não afirma que o sistema está totalmente seguro.** Os testes automáticos reduzem riscos, mas não substituem a validação no computador da biblioteca, a revisão das decisões de privacidade pela escola e, se o painel for exposto na internet, um teste de segurança independente. Testes que não foram executados estão listados como **não executados**.

Ambiente usado na auditoria: Linux, **PHP 8.3.6**, **MariaDB 10.11.14**, **Apache 2.4.58** (para os testes do `.htaccess`) e o servidor embutido do PHP. **Não** foi testado em Windows/XAMPP real, MySQL 8, Nginx ou IIS.

Nenhum dado real foi usado ou alterado: todos os testes rodam em bancos temporários `bdp_teste_*`, criados e apagados pelo próprio teste, com dados fictícios. O banco do `.env` foi conferido por `CHECKSUM TABLE` antes e depois da rodada final de testes.

---

## 1. Situação geral

### Funcionalidades verificadas
Login e sessão; perfis e permissões (acesso direto por URL e formulários forçados); acervo e cadastro de livros; importações de planilhas e ISBN; CDD (sem aprovação automática); exemplares e códigos BDP; localizações; inventário e conferência por código digitado; etiquetas só com texto; empréstimos, devoluções, renovações e atrasos; importação local de alunos; relatórios e exportações PDF/CSV/XLSX; configurações e manutenção; cópia de segurança e restauração; site público; responsividade e acessibilidade; desempenho com 10.000 livros; funcionamento sem as extensões `intl` e `iconv`.

### Funcionalidades aprovadas (nos testes executados)
Todas as acima passaram nas suítes automáticas (seção 7) e na verificação de telas com Playwright. "Aprovado" aqui significa: aprovado **neste ambiente de teste**.

### Problemas encontrados e classificação

| # | Gravidade | Problema | Situação |
| --- | --- | --- | --- |
| 1 | **Alta** | `admin/tests/cdd-teste.php` rodava a parte MySQL **no banco do `.env`** (no computador da biblioteca, seria o banco real). | **Corrigido**: agora cria um banco temporário próprio, confere que está conectado nele e o apaga no final. |
| 2 | **Alta** | A cópia de segurança não era um instantâneo consistente: um empréstimo gravado durante o download podia sair pela metade (empréstimo sem a mudança do exemplar), quebrando a integridade na restauração. Comprovado por teste que falhava antes da correção. | **Corrigido** em `BackupService::exportar()` (`START TRANSACTION WITH CONSISTENT SNAPSHOT`). |
| 3 | Média | Com HTTPS, o cookie da sessão não era marcado como `Secure` e não havia HSTS. | **Corrigido**: `app_https()` + `APP_HTTPS` no `.env` para proxies. |
| 4 | Média | O código CSRF da sessão anterior continuava válido depois do login. | **Corrigido**: renovado no login. |
| 5 | Média | Em produção, um erro inesperado podia mostrar a página técnica padrão do PHP (`display_startup_errors` ligado e sem tratador geral). | **Corrigido**: página genérica e registro no log com prefixo `[biblioteca]`. |
| 6 | Média | Painel carregava Chart.js de CDN e fontes do Google: o IP e o horário de uso da equipe iam para terceiros, e os gráficos não funcionavam sem internet. | **Corrigido**: Chart.js 4.4.1 local (`admin/assets/lib/chartjs/`, licença MIT incluída) e fontes do sistema. |
| 7 | Média | A página de login não enviava os cabeçalhos anti-clickjacking e anti-cache das demais páginas. | **Corrigido**. |
| 8 | Baixa | Rolagem horizontal na página inteira em **Perfis e permissões** (360 a 1024 px) e em **Inventário → detalhes** (360/390 px); gráficos podiam passar da largura. | **Corrigido** no CSS, sem mudar o visual. |
| 9 | Baixa | Faltava documentação de publicação, Nginx/IIS, recuperação, privacidade e o manual. | **Corrigido** (seção 8). |

Não foi encontrada vulnerabilidade **crítica** (injeção de SQL, XSS, acesso sem login, escalada de perfil ou exposição de arquivos privados) nos testes executados.

### Problemas ainda pendentes (não corrigidos de propósito)
- Decisões da escola sobre retenção, descarte, acesso e exportação de dados ([`seguranca-lgpd.md`](seguranca-lgpd.md)).
- Prazo de empréstimo definitivo (hoje 14 dias, provisório).
- Inventário físico e cadastro dos exemplares reais (o acervo real está só no nível bibliográfico).
- Validação no XAMPP do computador da biblioteca.
- Itens de risco residual da seção 2.

## 2. Segurança

### Vulnerabilidades identificadas
Itens 3 a 7 da tabela acima (endurecimento de sessão, CSRF, erros e privacidade). Nenhuma falha de autorização foi encontrada: a matriz de acesso (anônimo, administrador, bibliotecária, auxiliar e consulta × todas as páginas do painel) e o reenvio forçado de **todos** os formulários como "consulta", como anônimo e com CSRF falso não alteraram nenhuma tabela (conferido por `CHECKSUM TABLE` antes e depois).

### Correções aplicadas
Veja a tabela. Também verificado e mantido: hash de senha (`password_hash`), bloqueio por tentativas (e-mail ou IP), regeneração da sessão no login, encerramento por inatividade, logout por POST com CSRF, sessões encerradas ao desativar a conta ou redefinir a senha, retorno pós-login só para endereços internos, uploads de capa validados pelo conteúdo e pasta sem execução de PHP, `.env`/`.git`/`backups`/`storage`/`database`/`config`/`src`/`docs`/`vendor`/`admin/includes`/`admin/tests` respondendo 403/404 no Apache, site público sem dados de alunos.

### Riscos residuais
- Sem autenticação em dois fatores.
- Banco e arquivos de cópia não criptografados pelo sistema (depende do disco e da guarda).
- Bloqueio por IP pode afetar a escola inteira se todos saírem pelo mesmo IP.
- `.htaccess` só protege no Apache; em Nginx/IIS as regras de [`publicacao.md`](publicacao.md) precisam ser aplicadas e testadas.
- Nenhum teste de invasão independente foi feito.
- A segurança depende do Windows atualizado, do XAMPP sem acesso externo ao phpMyAdmin e de senhas fortes no MySQL (o `root` do XAMPP vem **sem senha**).

### Recomendações para a escola
1. Colocar senha no `root` do MySQL do XAMPP e criar um usuário só para o sistema.
2. Não liberar o XAMPP para a rede (phpMyAdmin acessível só em `localhost`).
3. Criptografia de disco (BitLocker) no computador da biblioteca.
4. Contas pessoais, menor perfil necessário, revisão semestral das contas.
5. Registrar por escrito as decisões de [`seguranca-lgpd.md`](seguranca-lgpd.md).
6. Se o painel for para a internet: HTTPS obrigatório, preferir acesso restrito à rede da escola, e teste de segurança antes de abrir.

## 3. Banco de dados

### Migrações analisadas
As 11 migrações (`001_acervo.sql` a `100_etiquetas_codigo_escrito.sql`) foram aplicadas do zero em bancos temporários em todas as suítes, e o `--status` confere que nada fica pendente. Todas são incrementais e não apagam dados. **Esta etapa não criou migrações novas.**

### Integridade validada
- **Um empréstimo ativo por exemplar**: coluna gerada única `exemplar_em_aberto` + bloqueio `FOR UPDATE`; teste de empréstimo duplicado e de concorrência.
- **Códigos BDP únicos e nunca reaproveitados**: sequência `exemplar_codigo_seq` e gatilhos; conferido também depois da restauração (o próximo código continua a sequência).
- **Históricos preservados**: gatilhos impedem alterar ou apagar empréstimos devolvidos, movimentações, renovações e histórico CDD.
- **CDD antiga nunca aprovada sozinha**: conferido nas importações e no fluxo de integração.
- Sem registros órfãos depois da restauração; 62 chaves estrangeiras, 166 índices, 23 gatilhos, 2 views, 1 procedimento e 30 regras `CHECK` idênticos entre original e restaurado.

### Procedimentos de backup verificados
Cópia gerada pelo painel, restaurada com o cliente `mysql` num segundo banco temporário (o teste recusa restaurar se o destino for o banco do `.env`), comparada tabela a tabela (`SHOW CREATE TABLE`, contagens, `CHECKSUM`, `AUTO_INCREMENT`) e testada uma segunda restauração no mesmo banco (falha sem duplicar). Não existe restauração pelo navegador. Procedimento para o XAMPP: [`backup-recuperacao.md`](backup-recuperacao.md).

## 4. Desempenho

Teste `desempenho-teste.php` com **10.000 livros**, 4.000 exemplares, 300 alunos, 600 empréstimos e um inventário (fictícios), servidor embutido do PHP, mediana de 3 acessos (rodada final):

| Página | Mediana |
| --- | --- |
| Dashboard, livros e exemplares do painel, busca por código BDP, empréstimos, atrasos, inventário, catálogo público, buscas com e sem acento, página 300 do catálogo | 11 a 75 ms |
| Catálogo público com filtro de categoria + "só disponíveis" + ordem por título | 488 ms |
| Relatórios → visão geral / acervo | 193 ms / 589 ms |
| Exportação CSV do acervo inteiro (10.000 linhas) | 352 ms |

- **Problemas encontrados**: nenhum tempo acima do limite de cada página (1 a 3 s, conforme a página). Os dois mais lentos ficam abaixo de 0,6 s.
- **Otimizações implementadas**: nenhuma alteração de índice foi necessária. O `EXPLAIN` confirmou o uso dos índices existentes nas consultas principais; nenhum índice redundante foi criado.
- Ressalva: medido em Linux; no Windows/XAMPP os tempos tendem a ser maiores.

## 5. Compatibilidade

- **Versões testadas**: PHP 8.3.6, MariaDB 10.11.14, Apache 2.4.58. Mínimo declarado: PHP 8.2, MariaDB 10.4+ ou MySQL 8.0+ (MySQL 8 **não testado**).
- **Dependências**: extensões `pdo_mysql`, `mbstring`, `zip`, `fileinfo`, `curl`; `gd` para capas; `dom`/`xml` para planilhas. Composer (`phpoffice/phpspreadsheet`); PDFs gerados pelo próprio sistema. **`intl` e `iconv` não são necessárias** (fluxo completo e site público testados sem elas).
- **Limitações do XAMPP**:
  - O MySQL do XAMPP pode exigir `log_bin_trust_function_creators=1` para criar gatilhos e o procedimento (README).
  - Pastas no Windows não diferenciam maiúsculas: por isso o cookie da sessão continua com caminho `/` (um caminho mais restrito causaria repetição do login se o endereço fosse digitado com outra capitalização).
  - Arquivos grandes no phpMyAdmin dependem de `upload_max_filesize`/`post_max_size`.
  - O `root` sem senha e o phpMyAdmin aberto são padrões do XAMPP que precisam ser mudados.

## 6. Publicação

- **Requisitos de hospedagem, checklist de produção, Nginx/IIS, migrações, logs e atualização**: [`publicacao.md`](publicacao.md).
- **Ajustes obrigatórios antes da publicação**: domínio, hospedagem e responsável técnico (decisão da escola); prazo de empréstimo; política de dados pessoais; inventário físico e exemplares reais; importação dos alunos reais no computador autorizado; teste no XAMPP real; HTTPS e `APP_ENV=production`.
- **Riscos que precisam ser resolvidos**: os da seção 2 e a falta de validação em Windows/XAMPP e no servidor final.
- **Nada foi publicado**, nenhum domínio ou hospedagem foi contratado.

## 7. Testes executados

Resultados da rodada final (todas em bancos temporários). Detalhes e comandos em [`testes.md`](testes.md).

| Suíte | Resultado |
| --- | --- |
| `painel-teste.php` | 89/89 |
| `importacao-teste.php` | 43/43 |
| `isbn-lote-teste.php` | 54/54 |
| `alunos-importacao-teste.php` | 75/75 |
| `emprestimos-teste.php` | 109/109 |
| `inventario-teste.php` | 139/139 |
| `cdd-teste.php` | 48/48 (agora em banco temporário) |
| `relatorios-teste.php` | 121/121 |
| `configuracoes-teste.php` | 96/96 |
| `site-publico-teste.php` | 119/119 |
| `codigos-etiquetas-teste.php` | 88/88 |
| `fluxo-integracao-teste.php` (novo) | 66/66 |
| `auditoria-seguranca-teste.php` (novo, com Apache) | 135/135 |
| `backup-restauracao-teste.php` (novo) | 34/34 |
| `desempenho-teste.php` (novo, 10.000 livros) | 32/32 |
| **Total** | **1.248 verificações, 0 falhas** |

Também executados: `php -l` em todos os arquivos PHP (sem erros); fluxo de integração e site público **sem** as extensões `intl` e `iconv` (66/66 e 119/119); verificação de telas com Playwright em 31 páginas nas larguras 360, 390, 768, 1024, 1366 e 1920 px (sem rolagem horizontal depois das correções, foco visível, nenhum controle sem nome acessível).

**Não executados**: testes em Windows/XAMPP, MySQL 8, Nginx, IIS, leitores de tela reais (NVDA/VoiceOver), navegadores além do Chromium, e teste de invasão independente.

## 8. Arquivos modificados

**Código**
- `admin/includes/auth.php` — cookie `Secure` com HTTPS, sessão só por cookie, CSRF renovado no login.
- `config/app.php` — `app_https()`, HSTS, página de erro genérica em produção, `display_startup_errors=0`.
- `admin/login.php` — cabeçalhos de segurança, sem Google Fonts.
- `admin/includes/layout.php` — sem Google Fonts.
- `admin/index.php` — Chart.js local.
- `src/Services/BackupService.php` — cópia num instantâneo consistente.
- `admin/assets/css/admin.css`, `admin/assets/css/inventario.css`, `admin/includes/acervo-fisico.php`, `admin/detalhes-livro.php` — correções de largura em telas pequenas (e versão do CSS).
- `.htaccess` — bloco comentado de redirecionamento para HTTPS.
- `.env.example` — opção `APP_HTTPS`.

**Novos**
- `admin/assets/lib/chartjs/chart-4.4.1.umd.min.js` e `LICENSE.md`.
- `admin/tests/suporte.php`, `fluxo-integracao-teste.php`, `auditoria-seguranca-teste.php`, `backup-restauracao-teste.php`, `desempenho-teste.php`.
- `docs/publicacao.md`, `docs/backup-recuperacao.md`, `docs/seguranca-lgpd.md`, `docs/manual-bibliotecaria.md`, `docs/testes.md`, este relatório.

**Testes e documentação alterados**
- `admin/tests/cdd-teste.php` — passa a usar banco temporário.
- `README.md`, `docs/importacao-alunos.md`.

**Migrações novas**: nenhuma.

## 9. Como atualizar o VS Code e o XAMPP

Depois de revisar e aprovar o Pull Request no GitHub:

1. No VS Code, abra a pasta `C:\xampp\htdocs\BibliotecaDomPedro2`.
2. Painel **Controle do código-fonte** → `...` → **Pull** (ou no terminal: `git checkout main` e `git pull`).
3. Não há dependências novas do Composer nem migrações novas. Se quiser conferir: `php database/migrar.php --status`.
4. Abra o painel do XAMPP e reinicie o **Apache** (para recarregar o `.htaccess`).
5. No navegador, pressione **Ctrl + F5** no painel para carregar o CSS novo.
6. Confira **Sistema → Manutenção** (diagnóstico) e o Dashboard (gráficos devem aparecer mesmo sem internet).
7. Opcional: rode os testes no terminal do VS Code (comandos em [`testes.md`](testes.md)). Eles usam bancos temporários e não tocam no banco da biblioteca; precisam de um usuário do MySQL com permissão de criar bancos.

Nada muda no `.env` local. A opção `APP_HTTPS` só é usada na publicação.
