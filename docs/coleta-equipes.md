# Equipes por link — organização inicial do acervo (migração 210)

Recurso temporário para a reorganização inicial: alunos e voluntários ajudam a anotar os livros e a
carimbar **sem conta no painel**, por um link gerado pelo administrador. Depois da organização, os links
podem ser desligados em Configurações; os dados ficam guardados.

Aplicar no servidor (faz a cópia de segurança antes, sozinho):

```
php database/migrar.php
```

O site precisa estar publicado na internet para os Chromebooks abrirem os links.

## Fluxo

| Equipe | Acesso | O que faz |
| --- | --- | --- |
| Coleta (ex.: Equipe 1) | link sem login | digita o nome e faz o **pré-cadastro** de cada livro do lote |
| Revisão (ex.: Equipe 2) | administradores, no painel | revisa, corrige e **aprova**: obra e exemplares com **código BDP** no lote |
| Carimbagem (ex.: Equipe 3) | link sem login | vê a **Planilha BDP** do lote, marca os carimbos e **conclui a carimbagem** |

1. O administrador abre o lote na Conferência (capacidade = número de exemplares do lote).
2. Na página do lote, seção **Equipes por link (sem login)**: equipe, para que serve (coleta ou
   carimbagem) e **validade** (escolhida pelo administrador, até 365 dias) → **Criar link**. O endereço
   aparece **uma vez** (o banco guarda só o hash SHA-256 do token); se perder, crie outro e desative o antigo.
3. **Coleta** (`/coleta/`): obrigatórios título, autor (ou “Sem autor indicado”), editora (ou “Sem editora
   indicada”), ano (ou “Sem data no livro”), **Tem ISBN?** (o ISBN tem o dígito verificador conferido) e
   **Tem CDD?** (formato `869` ou `869.3`), e **quantidade de exemplares iguais**. Complementares: subtítulo,
   edição, assunto, código do autor (Cutter) e observações. Cada livro recebe um número sequencial no lote
   (o mesmo do marcador colocado no livro). Pendentes podem ser corrigidos ou retirados pela equipe.
4. **Vagas**: a quantidade ocupa vagas na hora, com a linha do lote bloqueada. Pré-cadastros pendentes +
   exemplares já registrados nunca passam da capacidade; retirar ou recusar devolve as vagas.
5. **Revisão** (`admin/coleta.php?lote=…`, botão **Revisar pré-cadastros**): para cada livro, corrigir os
   dados, ligar à obra que já existe (mesmo ISBN) ou criar a obra, e **Aprovar e gerar** os códigos BDP
   (`ExemplarService::cadastrarEmQuantidade`, a mesma regra de todos os cadastros, com chave de operação
   que impede duplicar). **Recusar** exige motivo. **Aprovar todos sem alerta** cria as obras dos livros sem
   ISBN repetido e sem erro. A obra guarda em “observações internas” de qual pré-cadastro veio; a CDD entra
   como sugestão (fonte: ficha catalográfica). Quando a última vaga é aprovada, o lote fecha sozinho.
6. **Carimbagem**: com o lote fechado, a equipe marca os exemplares (com a confirmação “Conferi o código
   escrito em cada livro marcado”) e, com todos carimbados, **Concluir carimbagem do lote**
   (`LoteService::confirmarCarimbagem`): o lote passa a “Aguardando organização nas estantes”.

## Pausar trabalho

Qualquer etapa (coleta, revisão, carimbagem) pode ser pausada — pela equipe, no link, ou pelo
administrador, na página do lote — com motivo. O sistema grava **onde parou** (ex.: “12 livro(s)
pré-cadastrado(s), último nº 12; 8 de 40 vaga(s) livre(s)” ou “23 de 40 exemplar(es) carimbado(s)”).
Enquanto pausada, a etapa não recebe alterações; **Retomar trabalho** grava quem e quando. Tudo fica no
histórico do lote (`lote_eventos`).

## Segurança

- Token aleatório de 48 caracteres por link, válido só para um lote e um tipo (coleta ou carimbagem);
  validade definida pelo administrador; revogação imediata (**Desativar**).
- O token sai do endereço no primeiro acesso (fica na sessão própria da página, separada do painel);
  `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex`, sem cache, CSP sem scripts de fora.
- Cada envio exige o token CSRF da sessão; limite de 60 envios por minuto por sessão.
- A página não mostra dados de alunos, empréstimos nem nada do painel. O que é feito pelo link fica em nome
  do administrador que criou o link (as tabelas exigem uma conta) **e** do nome digitado
  (`coleta_itens.coletado_por`, `coleta_carimbos.nome`, `coleta_pausas.pausado_por`).
- **Configurações → Preparo dos exemplares → Aceitar os links das equipes**: com “não”, nenhum link abre.

## Tabelas (só acrescentadas)

- `coleta_links` — links (lote, tipo, equipe, hash do token, validade, criação, revogação, último uso).
- `coleta_itens` — pré-cadastros (dados do livro, quantidade, nome de quem coletou, situação, obra, revisão).
- `coleta_pausas` — pausas e retomadas por etapa, com onde parou.
- `coleta_carimbos` — quem marcou cada carimbo pelo link.

Testes: `php admin/tests/coleta-teste.php` (77 verificações: links, validação, vagas, pausa, revisão,
carimbagem e as páginas pública, do lote e de revisão).
