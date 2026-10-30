# Biblioteca Dom Pedro II

Sistema da biblioteca escolar Dom Pedro II, em PHP tradicional e MySQL.

- **Site público** (sem login): página inicial, catálogo, gêneros, autores, sobre, horários e contato. Os alunos usam apenas esta área.
- **Painel administrativo** (`/admin/`, com login): dashboard, acervo (livros, exemplares, categorias), classificação CDD e importação das planilhas Excel antigas.

## Tecnologias e requisitos

| Item | Versão |
| --- | --- |
| PHP | 8.2 ou superior, com as extensões `pdo_mysql`, `mbstring`, `zip` e `curl` |
| MySQL | 8.0 ou superior (charset `utf8mb4`) |
| Composer | 2.x (instala o PhpSpreadsheet, usado para ler `.xlsx`) |

Não há framework nem etapa de build: o CSS e o JavaScript são arquivos simples. O painel usa o Chart.js por CDN (sem internet, os gráficos mostram um aviso e o resto funciona).

## Instalação

### 1. PHP e MySQL

- **Windows**: instale o [XAMPP](https://www.apachefriends.org/) ou o [Laragon](https://laragon.org/), que já trazem PHP, MySQL ou MariaDB (ambos funcionam: MySQL 8.0+ ou MariaDB 10.4+) e Apache. Copie a pasta do projeto para `htdocs` (XAMPP) ou `www` (Laragon).
- **Linux (Ubuntu/Debian)**: `sudo apt install php php-mysql php-mbstring php-zip php-curl mysql-server`
- **macOS**: `brew install php mysql`

Confira com `php -v` (8.2+) e `mysql --version` (8.0+).

### 2. Dependências do Composer

Instale o [Composer](https://getcomposer.org/download/) e, na pasta do projeto:

```bash
composer install
```

Sem o Composer o sistema funciona, mas a importação aceita apenas arquivos `.csv`.

### 3. Banco de dados

Entre no MySQL como administrador (`mysql -u root -p`) e crie o banco e um usuário próprio:

```sql
CREATE DATABASE biblioteca_dom_pedro CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'usuario_biblioteca'@'localhost' IDENTIFIED BY 'troque-esta-senha';
GRANT ALL PRIVILEGES ON biblioteca_dom_pedro.* TO 'usuario_biblioteca'@'localhost';
-- As migrações criam triggers e um procedimento. Com o log binário ligado
-- (padrão do MySQL 8), um usuário comum só consegue criá-los com esta opção:
SET GLOBAL log_bin_trust_function_creators = 1;
```

> Para que a opção acima continue valendo depois de reiniciar o MySQL, acrescente `log_bin_trust_function_creators = 1` na seção `[mysqld]` do `my.cnf`/`my.ini`. Outra possibilidade é rodar as migrações com o usuário `root`.

### 4. Arquivo `.env`

Copie o modelo e preencha os dados do banco:

```bash
cp .env.example .env
```

| Variável | Para que serve |
| --- | --- |
| `APP_ENV` | `development` (padrão) ou `production`. Em produção, as contas demonstrativas ficam desligadas. |
| `APP_BASE_PATH` | Caminho do site no navegador. Deixe vazio para descobrir sozinho; use `/BibliotecaDomPedro2` se a detecção falhar em uma subpasta. |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | Conexão com o MySQL. |
| `CDD_CONTATO`, `CDD_FONTE_*` | Fontes consultadas pela classificação CDD. |
| `STORAGE_IMPORTACOES` | Pasta privada das planilhas enviadas (padrão `storage/importacoes`). |

O `.env` está no `.gitignore` e **nunca** deve ser enviado ao GitHub. Variáveis definidas no próprio servidor têm prioridade sobre o arquivo.

### 5. Migrações

```bash
php database/migrar.php           # aplica as migrações pendentes
php database/migrar.php --status  # mostra o que já foi aplicado
```

Cada arquivo de `database/migrations/` roda uma única vez e fica registrado na tabela `schema_migracoes`. As migrações só criam ou acrescentam estruturas: nenhuma apaga dados.

### 6. Primeiro usuário do painel

```bash
php database/criar-admin.php "Nome Completo" email@escola.com.br bibliotecaria
```

A senha (mínimo de 10 caracteres) é pedida no terminal. Perfis: `administrador`, `bibliotecaria`, `auxiliar`, `consulta`.
Depois do primeiro administrador, as demais contas podem ser criadas pelo painel, em **Sistema → Administradores**.

Opcional, **somente em um banco de testes**: `php database/seeds/popular-demo.php` copia os livros fictícios para um banco vazio (recusa rodar com `APP_ENV=production` ou com livros já cadastrados).

### 6.1 Acervo real (planilha da biblioteca)

A aba **Catalogar** da planilha *DOM PEDRO BIBLIOTECA* (5.687 linhas) está em `database/seeds/dados/acervo-catalogar.csv`, só com os dados dos livros (sem as abas de alunos e empréstimos). Para carregá-la no banco:

```bash
php database/seeds/importar-acervo.php --apagar-demo
```

No XAMPP (Windows): `C:\xampp\php\php.exe database\seeds\importar-acervo.php --apagar-demo`, dentro da pasta do projeto.

- `--apagar-demo` esvazia antes o acervo, os exemplares, os empréstimos, a CDD, os inventários e as importações (pede para digitar APAGAR). Administradores e alunos ficam.
- A importação usa o mesmo fluxo da tela *Importações*: a CDD, o código de autor, a localização e a quantidade antigos ficam em `registros_legados`, e nenhum exemplar ou código BDP é criado.
- Linhas repetidas (idênticas, mesmo ISBN, ou mesmo título/autor da mesma editora, ano e edição) viram "outro exemplar" do mesmo livro. As linhas com erro ficam pendentes em *Importações → detalhes* para revisão.
- Se uma tentativa parar antes de gravar livros, basta rodar o comando de novo, **sem** `--apagar-demo`: a tentativa anterior é cancelada e a importação recomeça. Se parar no meio da gravação, ela continua de onde parou.
- O mesmo arquivo não é importado duas vezes. Para uma versão nova da planilha, passe o `.xlsx`: `php database/seeds/importar-acervo.php planilha.xlsx --aba=Catalogar`.

#### Aba ENTRADA OBRAS (livros que ainda não estavam no banco)

A aba **Catalogar** da planilha atualizada é igual à que já foi importada. As informações novas estão na aba **ENTRADA OBRAS** (2.384 linhas), copiada para `database/seeds/dados/entrada-obras.csv` só com os dados das obras. Na cópia, os autores separados por "/" passaram a ser separados por ";", o "-" usado como vazio virou campo vazio, e os códigos CDD/CDU anotados na coluna ISBN foram para as observações (a CDD do sistema não muda). A linha original da aba fica na coluna *Linha na aba ENTRADA OBRAS*.

Com o acervo já carregado (**sem** `--apagar-demo`):

```bash
php database/seeds/importar-acervo.php database/seeds/dados/entrada-obras.csv --complementar
```

No XAMPP: `C:\xampp\php\php.exe database\seeds\importar-acervo.php database\seeds\dados\entrada-obras.csv --complementar`, dentro da pasta do projeto.

- Livros que não estão no acervo são criados, como na importação normal (sem exemplares nem código BDP; a quantidade fica em `registros_legados`).
- Linha de um livro que **já está** no acervo (mesmo ISBN, ou mesmo título e autor sem ano diferente): nada é criado. O script só preenche o ISBN, a editora e o ano quando esses campos estão **vazios** no livro, anota isso nas observações internas e no registro de ações (`livro.complementar`). Nenhum valor que já existe é trocado. A linha fica como "ignorada" na importação.
- Mesmo título de um livro do acervo, mas com outro autor: fica como **revisar depois** em *Importações → detalhes* (pode ser o mesmo livro com o autor escrito de outro jeito, ou outro livro com o mesmo nome).
- Mesmo título e autor com ano diferente (talvez outra edição) e ISBNs inválidos ficam pendentes, para a revisão normal e para a tela de ISBNs inválidos.
- O mesmo arquivo não é importado duas vezes; rodar de novo não duplica nada.

Resultado no banco de teste (acervo da aba Catalogar já importado): 1.642 livros novos, 44 outros exemplares, 231 linhas de livros que já estavam no acervo (111 livros ganharam ISBN, editora ou ano que faltavam), 224 para revisar depois, 67 duplicidades e 176 ISBNs inválidos pendentes.

#### ISBNs inválidos

Em *Importações → detalhes*, o botão **Tratar ISBNs inválidos em lote** (`admin/isbn-invalidos.php?id=N`) lista as linhas pendentes por causa do ISBN, explica o problema (dígito verificador, quantidade de dígitos, caracteres, prefixo) e sugere correções só quando os dígitos da edição não mudam. A bibliotecária (ou um administrador) pode importar em lote, sem ISBN, as linhas em que o ISBN é o **único** problema, depois de confirmar a lista de livros afetados. O ISBN da planilha continua nos dados originais da importação, em `registros_legados` e nas observações internas do livro. Linhas com outros erros ou com possível duplicidade ficam para a revisão individual; nenhum exemplar ou CDD é criado e os livros existentes não mudam. Repetir a operação não duplica nada.

### 7. Servidor local

Na pasta do projeto:

```bash
php -S localhost:8000
```

- Site público: <http://localhost:8000/>
- Painel administrativo: <http://localhost:8000/admin/>

No XAMPP/Laragon, com o Apache ligado: <http://localhost/BibliotecaDomPedro2/> e <http://localhost/BibliotecaDomPedro2/admin/>. Os endereços são gerados a partir da raiz da aplicação, então o sistema funciona tanto na raiz de um domínio quanto em uma subpasta.

### Sem banco (modo demonstrativo)

Sem `.env` (ou sem `DB_NAME`/`DB_USER`), o sistema roda em modo demonstrativo:

- O site mostra livros de exemplo com o aviso "Demonstração".
- O painel aceita as contas `bibliotecaria@demo` e `auxiliar@demo` (senha `demo`), **apenas** quando `APP_ENV` não é `production`.
- A importação de planilhas e todas as gravações reais ficam bloqueadas.

## Estrutura

```
BibliotecaDomPedro2/
├── index.php                  página inicial (única)
├── biblioteca_php/            páginas públicas: catalogo, generos, autores, sobre, horarios, contato
├── includes/                  layout, menu lateral e acesso ao catálogo do site público
│   ├── layout.php             page_start(), page_end(), page_heading(), book_cover()
│   ├── sidebar.php            menu lateral expansível
│   ├── catalogo.php           escolhe a fonte dos livros (MySQL ou exemplos)
│   └── dados-exemplo.php      livros fictícios do modo demonstrativo
├── admin/                     painel administrativo (todas as páginas exigem login)
│   ├── index.php              dashboard
│   ├── login.php, logout.php
│   ├── livros.php, exemplares.php, categorias.php, cadastrar/editar/detalhes-*.php
│   ├── classificacao.php, classificar-livro.php, revisao-cdd.php, historico-cdd.php, relatorio-cdd.php
│   ├── importacao.php, detalhes-importacao.php, historico-importacoes.php
│   ├── em-desenvolvimento.php tela das páginas ainda não criadas (inventário, empréstimos...)
│   ├── api/                   endpoints JSON (login + token CSRF)
│   ├── includes/              layout, menu, autenticação, módulos
│   ├── assets/                CSS e JS do painel
│   └── tests/                 testes de linha de comando
├── config/
│   ├── app.php                .env, carregamento das classes, url() e asset()
│   └── database.php           conexão PDO com o MySQL
├── src/                       classes (namespace Biblioteca\)
│   ├── Repositories/          consultas ao banco (CatalogoRepository, ImportacaoRepository)
│   ├── Services/              regras de negócio (CatalogoService, importação)
│   └── Support/               ISBN e normalização de texto
├── database/
│   ├── migrations/            NNN_nome.sql
│   ├── seeds/                 dados fictícios (popular-demo) e acervo real (importar-acervo)
│   ├── migrar.php
│   └── criar-admin.php
├── assets/                    CSS, JS e imagens do site público
├── storage/importacoes/       planilhas enviadas (privado)
├── composer.json
└── .env.example
```

### Regras para quem for manter o código

- Includes PHP sempre com `__DIR__`: `require_once __DIR__ . '/../includes/layout.php';`
- Links e arquivos públicos com `url()` e `asset()`: `url('biblioteca_php/catalogo.php')`, `asset('css/style.css')`. No painel, `admin_url('id-do-menu')`.
- Textos exibidos sempre escapados (`e()` ou `htmlspecialchars`), consultas sempre preparadas (`db_query($sql, [$valor])`).
- SQL fica nos Repositories (ou nos arquivos de módulo do painel), não no meio do HTML.
- Mudanças no banco: crie um novo arquivo em `database/migrations/` (nunca edite um que já foi aplicado). Prefixos: `001–002` base, `010` circulação, `030` importação, `040` CDD, `050+` inventário.

## Banco de dados

| Entidade | Tabelas | Migração |
| --- | --- | --- |
| Livros (cadastro bibliográfico) | `livros`, `livro_autores`, `livro_categorias`, view `livros_resumo` | 001 |
| Autores | `autores` | 001 |
| Categorias | `categorias` | 001 |
| Exemplares (unidade física, código BDP) | `exemplares`, `exemplar_codigo_seq`, procedimento `gerar_exemplares` | 001 |
| Localizações | `localizacoes` | 001 |
| Histórico de cada exemplar | `movimentacoes` | 001 |
| Administradores | `administradores`, `login_tentativas` | 002 |
| Alunos (sem login) | `alunos` | 010 |
| Empréstimos | `emprestimos` | 010 |
| Importações | `importacoes`, `importacao_itens`, `importacao_mapeamentos` | 030 |
| Dados antigos das planilhas | `registros_legados` (imutável) | 030 |
| Histórico de atividades | `acoes_administrativas` | 030 |
| Classificações CDD | `classificacoes_cdd`, `classificacoes_cdd_historico`, `cdd_*` | 040 |
| Inventários | `inventarios`, `inventario_conferencias` | 001 + 050 |

Regras garantidas no banco: um livro tem vários exemplares e cada exemplar pertence a um livro; o código BDP é único; o ISBN identifica a edição, não o exemplar; a quantidade antiga das planilhas fica em `registros_legados` e **nunca** vira exemplar disponível; a CDD fica em `classificacoes_cdd` com histórico que não pode ser alterado; um exemplar só pode ter um empréstimo em aberto; empréstimos e registros legados não podem ser apagados.

## Segurança do painel

- Todas as páginas de `admin/` exigem login; as APIs respondem 401/403 sem sessão ou sem permissão.
- Senhas com `password_hash`/`password_verify`, sessão com cookie `HttpOnly` e `SameSite=Lax`, ID regenerado no login, bloqueio após 5 tentativas em 15 minutos.
- Formulários e APIs que alteram dados exigem token CSRF; o logout é feito por POST.
- Permissões por perfil em `admin/includes/auth.php` (`AUTH_PERMISSOES`), conferidas no servidor em cada página e ação (esconder botões é só conveniência). Só o perfil `administrador` gerencia contas; o último administrador ativo não pode ser desativado nem rebaixado.
- Sessão encerrada após 2 horas sem uso; redefinir a senha de alguém encerra as sessões abertas com a senha antiga.
- Cadastros, edições, arquivamentos e mudanças de contas ficam em `acoes_administrativas` (quem, quando, o quê). Senhas nunca são registradas.
- Livros não são excluídos: são **arquivados** (com motivo e confirmação) e podem ser restaurados. O arquivamento é recusado enquanto houver exemplares em uso ou empréstimos em aberto.
- Erros de banco aparecem para o usuário como mensagem genérica; o detalhe vai para o log do servidor.
- No Apache, arquivos `.htaccess` bloqueiam o acesso direto a `config/`, `database/`, `src/`, `admin/includes/`, `admin/tests/`, `storage/`, `vendor/` e ao `.env`. Em outros servidores (Nginx), configure o mesmo bloqueio.

## Testes

```bash
php admin/tests/importacao-teste.php   # leitura de planilhas e validações (sem banco)
php admin/tests/cdd-teste.php          # regras da CDD; com banco e livros cadastrados, testa também o MySQL
php admin/tests/isbn-lote-teste.php    # ISBNs inválidos em lote; cria e apaga um banco temporário (não usa o DB_NAME do .env)
php admin/tests/painel-teste.php       # livros, contas, permissões, CSRF, XSS e SQL; banco temporário com 3.000 livros fictícios
```

## Situação das funcionalidades

**Prontas**

- Site público com menu expansível, responsivo, lendo o catálogo do MySQL (ou exemplos identificados, sem banco).
- Disponibilidade no catálogo calculada pelos exemplares físicos.
- Login, permissões, CSRF e logout do painel.
- Dashboard com números reais do MySQL (títulos × exemplares, empréstimos ativos e atrasados, CDD pendente, pendências de importação, inventários em andamento).
- Gerenciamento de livros: listagem com pesquisa (com ou sem acentos), filtros, ordenação e paginação no servidor; cadastro, edição, arquivamento e restauração gravados no banco; consulta dos dados originais da importação.
- Gerenciamento de administradores (cadastro, edição, ativação, redefinição de senha) e página "Meu perfil" (troca da própria senha).
- Classificação CDD (pesquisa nas fontes, revisão, aprovação, histórico, relatórios).
- Importação de planilhas `.xlsx`/`.csv` com revisão, preservação dos dados antigos e prevenção de duplicidades.

**Pendentes**

- Cadastro de exemplares e de categorias ainda é **simulado** (valida, mas não grava no banco): os exemplares nascem no inventário físico.
- Inventário físico (estrutura do banco pronta: `inventarios`), etiquetas com código BDP.
- Empréstimos, devoluções e atrasos (estrutura pronta: `alunos`, `emprestimos`).
- Cadastro de alunos, relatórios gerais, página de atividades e configurações.
- Formulário de contato (não envia mensagens) e horários/contatos oficiais, que dependem da escola.
- Pesquisas das fontes CDD nunca foram testadas com internet neste ambiente de desenvolvimento.

O relatório da Etapa 3.5 (problemas encontrados e correções) está em [`docs/relatorio-etapa-3.5.md`](docs/relatorio-etapa-3.5.md).
O relatório da finalização do painel administrativo está em [`docs/relatorio-etapa-1-painel.md`](docs/relatorio-etapa-1-painel.md).
