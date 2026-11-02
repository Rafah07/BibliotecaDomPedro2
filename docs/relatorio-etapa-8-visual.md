# Etapa 8 — Modernização visual do site público

Etapa exclusivamente visual. Nenhuma migração, consulta SQL, serviço, repositório, regra de disponibilidade, autenticação ou página do painel foi alterada.

## Problemas visuais encontrados

- **CSS acumulado de várias etapas** (1.034 linhas): regras do protótipo antigo que não eram mais usadas (`.dashboard-grid`, `.feature-banner`, `.schedule-banner`, `.book-stack`...), a mesma classe redefinida em 2 ou 3 blocos e cores soltas (`#173676`, `#233f90`, `#2457c5`, `#2e73ff`...) sem uma paleta única.
- **Banner** era uma foto genérica com o texto "Descubra novos mundos" gravado na imagem: sem o nome nem o emblema da biblioteca, sem pesquisa, e no celular o texto ficava ilegível (imagem de 800×300 reduzida).
- **Pesquisa da página inicial** ficava num painel separado, abaixo do banner.
- **Menu lateral** com fonte do título grande para o espaço, item ativo pouco destacado e sem dica de nome quando recolhido. No celular, o menu fechado continuava na ordem do Tab e não fechava ao escolher uma página.
- **Capas padrão** com 12 gradientes coloridos diferentes (marrom, laranja, verde): parecia que cada livro tinha uma capa real.
- **Disponibilidade**: rótulos diferentes do pedido ("Exemplares ainda não identificados", "Nenhum disponível no momento").
- **Rodapé** como um cartão flutuante, sem títulos de coluna; "Acesso da equipe" misturado aos contatos.
- **Barra superior no celular**: o campo de pesquisa espremido entre o botão do menu e o emblema ("Pesquisar livro…").
- **Números do acervo** em três faixas azuis grandes no celular.
- **Página do livro**: sinopse no meio das informações; ISBN não aparecia primeiro; categoria só como etiqueta.
- **Fonte declarada "Inter"**, que não existe nos computadores da escola (caía para Arial).

## Melhorias por página

| Página | O que mudou |
| --- | --- |
| Todas | Paleta institucional em variáveis CSS (azul `#123D73`, azul escuro `#0A2749`, dourado `#D6AE62` só em detalhes, fundo `#F5F7FA`); fontes do sistema (Segoe UI no Windows) com títulos em Georgia; cabeçalho de página com ícone e filete dourado; rodapé azul escuro em colunas. |
| Menu lateral | Barra de altura total, recolhida só com ícones (com dica do nome), expansível pelo botão; item ativo com marcador dourado e `aria-current`; seções "Acervo" e "A biblioteca". No celular: botão "Menu" visível na barra superior, painel lateral com botão X, fecha com Esc, tocando fora ou escolhendo uma página; foco vai para a página atual, fica preso no painel aberto e volta ao botão ao fechar; fechado, o painel sai da ordem do Tab (`inert`). |
| Início | Banner azul com "Seu próximo livro começa aqui.", texto de boas-vindas do painel, pesquisa em destaque, botão do catálogo e emblema oficial numa placa branca; ilustração de livros em traço discreto. Depois: números reais do acervo e atalhos rápidos, livros recentes, categorias em cartões, apresentação da biblioteca (texto do painel) com horário, atalhos e rodapé. |
| Catálogo | Título "Catálogo"; campo de pesquisa maior com lupa; filtros abertos no computador e recolhidos no celular; contagem real de resultados; grade responsiva de cards; paginação com página atual, Anterior/Próxima desabilitados quando não se aplicam; estado vazio com botão "Limpar filtros". |
| Livro | Capa à esquerda (fixa ao rolar no computador, antes das informações no celular); título, subtítulo, autor, quadro de disponibilidade com etiqueta; informações bibliográficas na ordem ISBN, editora, ano, edição, idioma, páginas, categoria, assunto e CDD aprovada; sinopse e relacionados abaixo; botão "Voltar ao catálogo". |
| Sobre | Apresentação com emblema, cartões com ícone (espaço, objetivos, orientações), cartões de horário e contato. Textos continuam vindo do painel e campos vazios mostram "aguardando confirmação da escola". |
| Regras | Atalhos no topo (Consultar, Empréstimo, Devolução, Conservação, Regras gerais), passos numerados em cartões e cartões de conservação e regulamento. Nenhuma regra, prazo ou multa nova. |
| Categorias e Autores | Mesmos cartões brancos com ícone azul (sem as cores rosa/laranja/roxo). |

## Componentes padronizados (`assets/css/style.css`)

Botões `.btn-primary`, `.btn-secondary`, `.btn-quiet`, desabilitado (as classes antigas `.action-button` e `.ghost-button` continuam valendo), campos (`.field`, `.search-bar`, `.hero-search`, `.mini-search`), cards (livro, categoria, informação, atalho), etiquetas de disponibilidade, avisos (`.info-strip`, `.pending-note`), estado vazio, paginação, títulos de seção e links.

### Disponibilidade

O cálculo é o mesmo (`CatalogoService::disponibilidade()`, só exemplares físicos). Só o texto das etiquetas mudou em `availability_badge()`:

| Estado | Etiqueta |
| --- | --- |
| disponível | ✓ "1 disponível" / "N disponíveis" (verde) |
| todos emprestados ou em preparo | ⧗ "Nenhum exemplar disponível" (âmbar) |
| sem exemplar físico cadastrado | ⓘ "Disponibilidade ainda não conferida" (cinza) |

Ícone + texto: a cor nunca é a única informação.

### Capa padrão

Sem capa cadastrada, todos os livros usam a mesma capa neutra (azul-acinzentada com lombada azul, ícone de livro, título e autor). Nenhuma imagem aleatória.

## Arquivos modificados

- `assets/css/style.css` — reescrito e organizado (tokens, base, estrutura, componentes, páginas, responsividade).
- `assets/js/app.js` — menu lateral (celular: X, fechar ao navegar, foco, `inert`); filtros abertos no computador.
- `includes/layout.php` — barra superior (botão Menu no celular), rodapé em colunas, capa padrão neutra, etiquetas de disponibilidade, título completo no `title` do card.
- `includes/sidebar.php` — seções do menu, dicas no menu recolhido, botão de fechar.
- `index.php` — novo banner e ordem das seções.
- `biblioteca_php/catalogo.php`, `livro.php`, `sobre.php`, `regras.php` — marcação visual.
- `docs/relatorio-etapa-8-visual.md` — este relatório.

Imagens antigas (`banner.jpg`, `banner-celular.jpg`, `banner.png`) deixaram de ser usadas, mas continuam no repositório: apague só se quiser.

## Testes

Ambiente: Linux, PHP 8.3.6, MariaDB 10.11.14, Chromium (Playwright). Todos os testes usam banco temporário.

- 15 suítes de `admin/tests/`: **1.213 verificações, 0 falhas** (site-publico 119, fluxo-integracao 66, auditoria-seguranca 100, desempenho 32 com `--livros=3000`, backup-restauracao 34, cdd 48, painel 89, importacao 43, isbn-lote 54, alunos-importacao 75, emprestimos 109, inventario 139, relatorios 121, configuracoes 96, codigos-etiquetas 88).
- Capturas em 360, 390, 768, 1024, 1366 e 1920 px de Início, Catálogo (com filtros e vazio), Livro (com e sem exemplar), Sobre, Regras, Categorias e Autores: nenhuma rolagem horizontal.
- Verificação automática de acessibilidade em 9 páginas: um `h1` por página, todas as imagens com `alt`, todos os botões e links com nome, todos os campos com rótulo, ids sem repetição, contraste ≥ 4,5:1 (exceto o botão desabilitado "Anterior", 4,0:1, isento pela WCAG). Primeiro Tab vai para "Pular para o conteúdo"; `aria-expanded` do menu muda; no celular, foco entra no menu, fica preso nele, volta ao botão ao fechar; o menu fecha ao escolher uma página.
- Pesquisa da página inicial leva ao catálogo com o resultado.

## Limitações

- Não testado no Windows/XAMPP real, nem em Safari/iOS.
- As capturas usam o banco temporário dos testes (livros fictícios), não o acervo real.
- O texto do banner abaixo do título é o **texto de boas-vindas do painel** (Sistema → Site público); a sugestão "Explore o acervo da Biblioteca Dom Pedro II e descubra novas histórias, conhecimentos e possibilidades." pode ser colada lá.
- Nenhum defeito funcional preexistente foi encontrado.
