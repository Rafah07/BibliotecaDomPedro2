# Relatório da Etapa 3.5 — Correção, organização e integração

Data: 29/10/2026. Base: branch da Etapa 3 (PR #4), que já contém as Etapas 1, 2 e 4 (PRs #1 a #3). Nenhum desses PRs estava mesclado na `main`; o ZIP da `main` não tinha o painel administrativo, por isso a análise considerou o código das quatro etapas.

## Problemas encontrados

1. **Duas páginas iniciais idênticas.** `index.php` (raiz) era uma cópia exata de `Index/Index.php`. Na raiz, ela incluía `__DIR__ . '/../includes/sidebar.php'` (fora do projeto) e usava `../assets/...`: o menu lateral não aparecia e o CSS, as imagens e os links quebravam.
2. **Links para `Index/Index.php` espalhados** no menu público, no logotipo do cabeçalho e no item "Voltar ao site" do painel.
3. **Caminhos relativos fixos** (`../assets/...`, `$base = '../'`) no layout público, que só funcionavam para páginas exatamente um nível abaixo da raiz.
4. **Painel administrativo sem login na maior parte das páginas.** Apenas Classificação CDD, Importação e o relatório CDD exigiam login. Dashboard, Livros, Exemplares, Categorias, cadastros e detalhes abriam sem autenticação (com banco configurado, Livros/Exemplares pediam login só ao carregar dados).
5. **Contas demonstrativas sempre ativas**: sem banco configurado, `bibliotecaria@demo`/`demo` entrava no painel mesmo em produção.
6. **Botões sem ação**: "Sair" do menu lateral e do cabeçalho (sem login) e a pesquisa global do painel só mostravam um aviso; as abas "Destaques / Mais emprestados / Novidades" da página inicial não filtravam nada.
7. **Dados fictícios misturados com dados reais**: com o MySQL configurado, o dashboard e o sino de notificações continuavam mostrando os números inventados (1.248 títulos, 18 atrasos...).
8. **Duas configurações de banco**: `admin/config/database.php` (arquivo PHP) e variáveis `BDP_DB_*`, sem `.env`. O site público não tinha acesso ao banco.
9. **Resquícios de PostgreSQL/Supabase**: `admin/database/acervo.sql` e comentários citando Supabase.
10. **Catálogo público só com dados fictícios** e sem camada de acesso a dados.
11. **Banco sem as tabelas de alunos, empréstimos e rodadas de inventário.**
12. **Carregador de classes duplicado** em `admin/includes/importacao.php` e no teste de importação.
13. **Requisito do MySQL não documentado**: as migrações criam triggers e um procedimento; com o log binário ligado (padrão do MySQL 8), um usuário comum recebe o erro 1419. Isso foi descoberto ao instalar do zero.
14. `admin/tests/cdd-teste.php` falha com um banco recém-criado e vazio (precisa de pelo menos um livro). Não foi alterado: basta cadastrar livros ou rodar o seed de testes.

## Correções realizadas

### Estrutura e caminhos

- `index.php` (raiz) é a página inicial oficial, montada com o layout compartilhado; `Index/Index.php` foi removido (o conteúdo era idêntico, nada se perdeu).
- `config/app.php` (novo): lê o `.env`, registra o carregamento das classes de `src/` e cria `url()`, `asset()` e `e()`. A raiz da aplicação é descoberta automaticamente ou definida por `APP_BASE_PATH`.
- `includes/layout.php` e `includes/sidebar.php`: todos os links, CSS, JS e imagens usam `url()`/`asset()`; `asset()` acrescenta a data do arquivo para evitar cache antigo.
- `biblioteca_php/*.php`: links e imagens trocados por `url()`/`asset()`; includes continuam com `__DIR__`.
- `admin/includes/menu.php`: `admin_base()` agora deriva de `url('admin/')`; "Voltar ao site" aponta para a página inicial.

### Banco de dados

- `config/database.php` (novo): conexão PDO única, configurada por `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`; exceções, `utf8mb4`, prepares nativos, mensagem genérica para o visitante e detalhe só no log. `admin/includes/db.php` passou a apenas incluí-lo.
- `.env.example` (novo) e `.env` no `.gitignore`. Removidos `admin/config/database.example.php` e `admin/config/.gitignore`.
- Migrações movidas para `database/migrations/` (mesmos nomes, então bancos já migrados não reaplicam nada). `migrar.php` e `criar-admin.php` foram para `database/`, `popular-demo.php` para `database/seeds/` (agora bloqueado em produção).
- Novas migrações: `010_alunos_emprestimos.sql` e `050_inventarios.sql` (detalhes abaixo).
- Removido `admin/database/acervo.sql` (PostgreSQL) e todas as menções a Supabase/PostgreSQL.

### Autenticação

- `admin/includes/layout.php`: toda página do painel exige login antes de carregar qualquer dado.
- `admin/includes/auth.php`: contas demonstrativas só funcionam sem banco **e** fora de produção (`APP_ENV=production` as desliga).
- "Sair" no menu lateral e no cabeçalho virou um formulário POST com token CSRF; removido o "Sair" de mentira.
- `admin/login.php`: após entrar, volta para a página pedida ou para o dashboard; link "Voltar ao site".

### Catálogo e camada de dados

- `src/Repositories/CatalogoRepository.php` (novo): consultas preparadas que devolvem apenas campos públicos (título, autores, categoria, ISBN, capa) e a disponibilidade calculada pelos exemplares físicos (`disponivel`), nunca pela quantidade das planilhas.
- `src/Services/CatalogoService.php` (novo): decide a fonte (acervo real, acervo vazio, banco indisponível ou demonstração) e nunca mistura dados reais e fictícios.
- `includes/catalogo.php` (novo): ponto de acesso das páginas públicas e aviso de origem dos dados.
- Página inicial, Catálogo (com paginação de 24 livros e pesquisa por ISBN), Gêneros e Autores usam o serviço. Sem banco, os exemplos aparecem com o aviso "Demonstração"; com banco vazio ou fora do ar, aparece um estado vazio.
- CSS: capa com imagem, ISBN, selo de disponibilidade e paginação, no mesmo padrão visual.

### Painel administrativo

- `admin/includes/dashboard-dados.php` (novo): com MySQL, o dashboard mostra números reais (títulos, exemplares, disponíveis, emprestados, atrasos, manutenção, títulos sem conferência, empréstimos por mês, categorias, últimas ações e progresso de conferência). Sem banco, os dados demonstrativos com o aviso.
- Sino de notificações com pendências reais (ou "Nenhuma pendência").
- Pesquisa do cabeçalho agora abre a listagem de livros filtrada.
- `admin/assets/js/admin.js`: removidas as ações falsas de pesquisa e saída.

### Proteção de arquivos

- `.htaccess` na raiz (bloqueia `.env`, `composer.*`, `.sql`, `.md`, listagem de pastas e `vendor/`) e em `config/`, `database/`, `src/`, `admin/includes/` e `admin/tests/`.

## Estrutura final

```
index.php
biblioteca_php/   catalogo, generos, autores, sobre, horarios, contato
includes/         layout, sidebar, catalogo, dados-exemplo
admin/            páginas do painel, api/, includes/, assets/, tests/
config/           app.php, database.php
src/              Repositories/, Services/, Support/
database/         migrations/, seeds/, migrar.php, criar-admin.php
assets/           css/, js/, img/
storage/          importacoes/ (privado)
docs/             este relatório
composer.json, .env.example, README.md
```

## Banco de dados

| Migração | Tabelas |
| --- | --- |
| 001_acervo (existente) | categorias, autores, localizacoes, livros, livro_autores, livro_categorias, exemplar_codigo_seq, exemplares, movimentacoes, inventario_conferencias, view livros_resumo |
| 002_administradores (existente) | administradores, login_tentativas |
| **010_alunos_emprestimos (nova)** | alunos (sem login), emprestimos (um empréstimo aberto por exemplar, sem exclusão), coluna `movimentacoes.emprestimo_id` |
| 030_importacoes (existente) | acoes_administrativas, importacoes, importacao_itens, importacao_mapeamentos, registros_legados |
| 040_classificacao_cdd (existente) | classificacoes_cdd e histórico, cdd_lotes, cdd_pesquisas, cdd_consultas, cdd_lote_itens, cdd_tabela_referencia |
| **050_inventarios (nova)** | inventarios (rodadas de conferência) e coluna `inventario_conferencias.inventario_id` |

As novas migrações só criam tabelas e acrescentam colunas opcionais: nenhum dado existente é alterado.

## Funcionalidades testadas

Ambiente de teste: PHP 8.3.6, MySQL 8.0.46 e Chromium (Playwright), no servidor embutido do PHP.

| # | Verificação | Resultado |
| --- | --- | --- |
| 1–6 | Início, catálogo, autores, gêneros, sobre, horários e contato carregando | OK, na raiz (`/`) e em subpasta (`/BibliotecaDomPedro2/`) |
| 7 | Menu lateral expansível (desktop e celular) | OK |
| 8 | Navegação: varredura automática de todos os links, imagens, CSS e JS | OK: nenhum link quebrado (139 endereços no site, 299 com o painel logado) |
| 9–10 | CSS e imagens carregando | OK |
| 11 | Painel acessível só com login | OK: sem sessão, todas as páginas redirecionam para o login; após sair, voltam a redirecionar |
| 12 | Conexão MySQL configurável pelo `.env` | OK |
| 13 | Banco fora do ar (senha errada) | OK: site mostra "temporariamente indisponível", login mostra mensagem genérica, detalhe só no log |
| 14 | APIs protegidas | OK: 401 sem sessão |
| 15 | Celular (390 px) | OK: sem rolagem horizontal; menu abre pelo botão |
| 16 | Sintaxe PHP (`php -l` em 71 arquivos) | OK |
| — | Migrações em banco novo e reaplicação | OK (6 migrações; a segunda execução não faz nada) |
| — | Regras novas no MySQL | OK: segundo empréstimo aberto do mesmo exemplar é recusado; exclusão de empréstimo é recusada |
| — | `admin/tests/importacao-teste.php` | 38/38 |
| — | `admin/tests/cdd-teste.php` (com MySQL) | 48/48 |
| — | Modo demonstrativo em desenvolvimento / produção | OK: entra com `bibliotecaria@demo` em desenvolvimento; recusado em produção |
| — | Instalação limpa seguindo o README | OK (clone novo, `composer install`, `.env`, migrações, usuário, servidor) |

Não testado: Apache/XAMPP real (as regras `.htaccess` não foram executadas), gráficos do Chart.js e fontes do Google (sem acesso ao CDN no ambiente de teste) e consultas às fontes CDD pela internet.

## Pendências

- Gravação real de cadastro e edição de livros, exemplares e categorias (hoje validam e simulam).
- Módulos de inventário físico, etiquetas BDP, empréstimos/devoluções/atrasos e cadastro de alunos (estrutura do banco pronta).
- Relatórios gerais, página de atividades, configurações e perfil.
- Formulário de contato e informações oficiais de horários e contato (dependem da escola).
- Testar em um servidor Apache/XAMPP e com internet (CDN e fontes CDD).
