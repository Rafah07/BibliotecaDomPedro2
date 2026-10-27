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

## Atenção

Este é um **protótipo**. O catálogo usa dados de exemplo; os horários e contatos oficiais ainda não foram informados; e o formulário de contato **não envia mensagens**. O próximo passo é validar as informações com a escola e integrar um banco de dados.
