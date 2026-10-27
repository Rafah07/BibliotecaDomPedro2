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

Ainda não há login, banco de dados nem cadastros: o botão "Sair" e a pesquisa global são apenas demonstrativos.

## Atenção

Este é um **protótipo**. O catálogo usa dados de exemplo; os horários e contatos oficiais ainda não foram informados; e o formulário de contato **não envia mensagens**. O próximo passo é validar as informações com a escola e integrar um banco de dados.
