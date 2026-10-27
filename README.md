# Biblioteca Dom Pedro II — Protótipo

Protótipo visual em PHP, com página inicial, catálogo, gêneros, autores, sobre, horários e contato.

## Como executar

Na pasta `BibliotecaDomPedro2`, rode:

```bash
php -S localhost:8000
```

Abra **http://localhost:8000** no navegador (a raiz redireciona para a página inicial).

Também funciona em um servidor local com PHP, como XAMPP ou Laragon.

## Estrutura

- `Index/Index.php`: tela inicial
- `biblioteca_php/`: as seis páginas internas
- `includes/sidebar.php`: menu lateral expansível, com links funcionando
- `includes/layout.php`: cabeçalho e layout compartilhados
- `includes/dados-exemplo.php`: livros e autores fictícios para testar o visual (títulos e pessoas reais, mas **não** são dados reais do acervo da escola)
- `assets/css/style.css`: estilos do site e responsividade
- `assets/js/app.js`: interações visuais do menu e formulário de demonstração

## Painel administrativo (protótipo)

Acesse **http://localhost:8000/admin/** para ver o dashboard administrativo.

- `admin/index.php`: dashboard (indicadores, gráficos, atividades, alertas, acesso rápido e inventário)
- `admin/em-desenvolvimento.php`: tela provisória das páginas que ainda serão criadas
- `admin/includes/menu.php`: itens do menu lateral; marque `'pronta' => true` quando uma página existir
- `admin/includes/dados-demo.php`: **todos os dados demonstrativos** do painel, em funções que depois serão trocadas por consultas ao banco
- `admin/includes/layout.php`, `sidebar.php`, `header.php`: estrutura compartilhada
- `admin/includes/icones.php`: ícones Lucide embutidos (funcionam sem internet)
- `admin/assets/css/admin.css`: estilos do painel (classes com prefixo `adm-`, sem conflito com o site público)
- `admin/assets/js/admin.js` e `dashboard.js`: menu, menus suspensos, avisos e gráficos (Chart.js via CDN)

### Módulo de acervo (Etapa 2)

- `admin/livros.php`: listagem com pesquisa, filtros, ordenação, paginação, menu de ações e arquivamento
- `admin/cadastrar-livro.php` e `admin/editar-livro.php`: formulário de livro (ISBN-10/13 com dígito de controle, autores, capa, situação) com aviso de possível duplicidade
- `admin/detalhes-livro.php?id=1`: capa, dados, indicadores e abas (informações, exemplares, movimentações, inventário)
- `admin/exemplares.php` e `admin/detalhes-exemplar.php?codigo=BDP-000001`: unidades físicas, com código próprio, localização, conservação e situação
- `admin/categorias.php`: categorias, com arquivamento que exige mover os livros associados
- `admin/includes/acervo-demo.php`: dados demonstrativos do acervo (16 livros, 33 exemplares, 13 categorias), um conjunto por tabela
- `admin/includes/acervo.php`: consultas, rótulos, validação no servidor e componentes (capas, selos, breadcrumbs)
- `admin/assets/css/acervo.css` e `admin/assets/js/acervo.js`: estilos e interações do módulo
- `admin/database/acervo.sql`: estrutura para PostgreSQL/Supabase (tabelas, geração segura de códigos, bloqueio de exemplar emprestado)

Cadastros, edições, arquivamentos e exemplares criados nessas telas são **simulados**: ficam só na memória da página e somem ao recarregar. A quantidade informada em planilhas nunca vira exemplar disponível; os exemplares só existem depois da conferência física.

Ainda não há login, banco de dados nem cadastros: o botão "Sair" e a pesquisa global são apenas demonstrativos.

### Banco MySQL, login e classificação CDD (Etapa 4)

O MySQL 8.0+ passa a ser o banco principal do painel (o `admin/database/acervo.sql` da Etapa 2, em PostgreSQL, fica só como referência).

1. Crie o banco e copie `admin/config/database.example.php` para `admin/config/database.php` (ou use as variáveis `BDP_DB_HOST`, `BDP_DB_NAME`, `BDP_DB_USER`, `BDP_DB_PASS`).
2. `php admin/database/migrar.php` aplica as migrações de `admin/database/mysql/` (nenhuma apaga dados).
3. `php admin/database/criar-admin.php "Nome" email@escola bibliotecaria` cria um usuário (perfis: administrador, bibliotecaria, auxiliar, consulta).
4. Opcional, para testes: `php admin/database/popular-demo.php` copia os livros fictícios para um banco vazio.

Sem banco configurado, o módulo funciona em **modo demonstrativo**: contas `bibliotecaria@demo` e `auxiliar@demo` (senha `demo`), livros fictícios e alterações guardadas só na sessão do navegador.

Páginas (menu Acervo → Classificação CDD):

- `admin/classificacao.php`: indicadores, progresso, ações rápidas, tabela com filtros e pesquisa em lote
- `admin/classificar-livro.php?id=1`: revisão individual, pesquisa nas fontes, classificação manual e aprovação
- `admin/revisao-cdd.php`: fila de aprovação (aprovar, corrigir, rejeitar, pedir nova pesquisa, observar)
- `admin/historico-cdd.php` e `admin/relatorio-cdd.php`: histórico imutável e relatórios com exportação CSV/Excel
- `admin/detalhes-livro.php`: nova seção "Classificação Bibliográfica"

Regras: nenhuma CDD é inventada nem aprovada automaticamente; a CDD vem do campo MARC 082 da Library of Congress ou da Open Library, sempre com a fonte, e a LCC (outro sistema) aparece só como referência. A CDD antiga da planilha nunca é alterada, o histórico não pode ser editado nem apagado (triggers no MySQL) e os códigos BDP não mudam quando a CDD é corrigida. Apenas bibliotecária e administrador aprovam. Testes: `php admin/tests/cdd-teste.php`.

### Importação das planilhas Excel (Etapa 3)

Requer o MySQL (no modo demonstrativo as telas só explicam o que falta configurar).

1. `composer install` na raiz instala o PhpSpreadsheet (leitura de `.xlsx`). Sem ele, só arquivos `.csv` são aceitos.
2. `php admin/database/migrar.php` cria as tabelas da migração `030_importacoes.sql`: `importacoes`, `importacao_itens`, `importacao_mapeamentos`, `registros_legados` e `acoes_administrativas`.
3. Os arquivos enviados ficam em `storage/importacoes/`, fora do alcance do navegador (`.htaccess` com `Require all denied`). Para usar outra pasta, defina `armazenamento_importacoes` em `admin/config/database.php`.

Páginas (menu Gerenciamento → Importar Excel):

- `admin/importacao.php`: indicadores, envio por arrastar e soltar, escolha da aba, mapeamento das colunas (com modelos salvos) e análise em lotes
- `admin/detalhes-importacao.php?id=1`: pré-visualização com filtros, correções, decisões por linha ou em lote, relatório de inconsistências e confirmação
- `admin/historico-importacoes.php`: todas as importações, inclusive canceladas
- APIs em `admin/api/importacoes/` (upload, validar, confirmar, relatorio); regras em `src/Services/` e `src/Repositories/`

Regras: nada entra no acervo antes da confirmação; a linha original da planilha é guardada sem alterações e as correções ficam em campo separado; nenhum código BDP nem exemplar é criado (a quantidade da planilha é só referência para o inventário); a CDD antiga é preservada em `registros_legados` e nunca validada nem gerada; duplicidades nunca são apagadas ou mescladas automaticamente; o mesmo arquivo não pode ser importado duas vezes. Perfis: consulta só visualiza, auxiliar revisa e bibliotecária/administrador confirmam a gravação. Testes: `php admin/tests/importacao-teste.php`.

Com o MySQL configurado, as páginas de Livros e Exemplares passam a ler o banco (exigindo login) e os livros importados mostram a CDD antiga, a quantidade da planilha e os indicadores separados "Aguardando reclassificação CDD" e "Aguardando inventário físico".

## Atenção

Este é um **protótipo**. O catálogo usa dados de exemplo; os horários e contatos oficiais ainda não foram informados; e o formulário de contato **não envia mensagens**. O próximo passo é validar as informações com a escola e integrar um banco de dados.
