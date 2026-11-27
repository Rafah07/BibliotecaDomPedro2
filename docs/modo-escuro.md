# Modo escuro

Vale para o site público, o painel, a tela de login e as páginas das equipes (reorganização e coleta).

- **Botão:** no site, item "Modo escuro" no menu lateral (e ícone na barra de cima no celular); no painel, ícone de lua/sol no cabeçalho; nas páginas das equipes, botão "Modo escuro" no topo.
- **Escolha:** na primeira visita segue o tema do aparelho. Depois que a pessoa usa o botão, a escolha fica guardada naquele navegador (`localStorage` `bdp-tema`) e vale no site e no painel.
- **Impressão:** sempre clara. As cores escuras ficam dentro de `@media screen`; etiquetas, Planilha BDP e PDFs não mudam.
- **Como funciona:** `assets/js/tema.js` (carregado no `<head>`) põe `data-tema="escuro"` no `<html>`. O fim de cada CSS tem um bloco gerado com a versão escura de cada cor.

## Ao mudar um CSS

Depois de alterar qualquer arquivo CSS do site, do painel ou dos módulos, rode na raiz do projeto:

```
php ferramentas/gerar-tema-escuro.php
```

O script refaz o bloco "MODO ESCURO — GERADO" no fim de cada arquivo (não edite esse bloco à mão). Cores fortes (menu azul, botões, faixas) ficam iguais; fundos claros viram escuros, textos escuros viram claros. Ajustes finos à mão usam o seletor `html[data-tema="escuro"]` na parte normal do arquivo.
