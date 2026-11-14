# Exemplares, localizações, inventário e etiquetas

Este guia explica, em linguagem simples, como usar o acervo físico no painel administrativo.
Tudo depende do banco de dados MySQL configurado (sem banco, as páginas de exemplares mostram só a demonstração antiga).

## Ideia principal: livro × exemplar

- **Livro** (registro bibliográfico): título, autor, editora, ISBN. O ISBN identifica a **edição**, não um volume.
- **Exemplar**: cada volume físico que está na biblioteca. Cada um tem um código próprio, como `BDP-000001`.
- A quantidade das planilhas antigas é **só referência**. Ela não cria exemplares. O sistema mostra três números separados:
  - quantidade na planilha;
  - exemplares físicos cadastrados;
  - exemplares disponíveis para empréstimo.
- Um código BDP nunca se repete e nunca é reaproveitado, mesmo depois de uma baixa.

## Como cadastrar exemplares

1. Abra o livro em **Acervo** e clique em **Cadastrar exemplar**. Outro caminho é **Exemplares → Cadastrar exemplar** e pesquisar o livro.
2. Informe:
   - o estado de conservação;
   - a situação inicial (só "Disponível" aparece para empréstimo);
   - a localização;
   - a quantidade de volumes **que estão com você**;
   - observações, se precisar.
3. Marque a confirmação de que os volumes estão em mãos e clique em **Confirmar cadastro**.
4. O sistema mostra os códigos gerados e o botão **Imprimir etiqueta(s)**.

Também é possível cadastrar durante a conferência do inventário (veja abaixo).

Exemplares não são excluídos. Na página do exemplar existem estas ações, e todas ficam no histórico:

- **Editar** o estado e as observações;
- **Transferir** de localização (só aquele exemplar muda);
- **Mudar situação**: disponível, aguardando liberação, em manutenção, danificado;
- **Registrar extravio**;
- **Dar baixa**.

Exemplar emprestado não pode ser baixado, extraviado nem mudar de situação. Primeiro registre a devolução.

## Como cadastrar localizações

1. Abra **Exemplares → Localizações** e clique em **Nova localização**.
2. Preencha:
   - setor (obrigatório);
   - estante;
   - prateleira;
   - complemento (opcional);
   - um código curto (opcional, ex.: `LIT-E03-P02`).
3. A mesma combinação não pode ser cadastrada duas vezes. Maiúsculas e minúsculas contam como iguais.
4. Localizações não são excluídas: são **desativadas**. Os exemplares que já estão nelas continuam lá.

A aba **Localizações antigas (planilhas)** mostra o texto que veio das planilhas. É só para consulta: esse texto nunca vira localização confirmada sozinho e nunca é alterado.
O sistema não distribui livros por CDD automaticamente. A organização física será definida com a bibliotecária.

## Como iniciar um inventário

1. Abra **Inventário → Novo inventário**.
2. Informe:
   - o nome;
   - a data de início;
   - o responsável;
   - o setor (ou "Acervo inteiro");
   - observações.
3. O inventário fica **em preparação**. Revise os dados e clique em **Iniciar conferência**.
4. Nesse momento a lista de **exemplares previstos** é congelada (todos os exemplares ativos, ou só os do setor escolhido).

Só pode existir um inventário em andamento ou pausado de cada vez. As mudanças de situação possíveis são:

- em preparação → em andamento ou cancelado;
- em andamento → pausado, concluído ou cancelado;
- pausado → em andamento, concluído ou cancelado.

Inventários concluídos ou cancelados ficam bloqueados.

## Como realizar a conferência

A conferência e a correção dos dados acontecem numa única página, **Conferir e corrigir livros**. Não há página de revisão separada: a planilha importada entra inteira (com alertas) e é conferida aqui, com o livro em mãos.

1. Na página do inventário, clique em **Conferir e corrigir livros**.
2. Em **Pesquisar livro: código BDP, título, autor ou ISBN**, digite o código escrito na etiqueta ou parte do título, do autor ou do ISBN.
3. Com um código BDP, o sistema mostra o exemplar (título, autor, localização, situação). Confira o estado, a localização e, se precisar, abra **Corrigir informações do livro**. Clique em **Salvar e marcar como encontrado**.
4. Com uma pesquisa por texto, os resultados mostram os exemplares e as linhas importadas (**Linha N da planilha · Pendente**). Clique em **Conferir** numa linha da planilha para:
   - corrigir os dados (todos opcionais);
   - escrever o **Código BDP do carimbo** (6 números; recusado se já pertencer a outro exemplar);
   - **Salvar e marcar como encontrado**, **Salvar só as correções** ou **Marcar como não localizado**.
5. Livro em mãos que não está na lista: **+ Cadastrar livro não listado**. Todos os campos podem ficar vazios. O sistema mostra antes os possíveis livros iguais; escolha um deles para cadastrar outro exemplar da mesma obra ou **Nenhum destes: é um livro diferente.** O livro já fica encontrado e os contadores mudam na hora.
6. Ao terminar uma estante ou setor, use **Finalizar estante ou setor** na página do inventário: escolha o local, veja os pendentes, selecione os ausentes, marque **Procurei estes livros no local e confirmo que não estão lá.** e clique em **Marcar selecionados como não localizados**.

Status do item: **Pendente**, **Encontrado** e **Não localizado**. Nada vira não localizado automaticamente. Um item não localizado pode voltar para pendente (**Voltar para pendente**); um encontrado pode ser desfeito por quem gerencia o inventário (**Desfazer “Encontrado”**, com motivo). Os alertas cadastrais são independentes do status.

O painel de progresso mostra **Total**, **Encontrados**, **Pendentes**, **Não localizados**, **Com alertas cadastrais**, **Sem código BDP** e o **% concluído**.

Algumas regras da conferência:

- **Código inexistente** não cria nada: aparece "Código BDP-… não encontrado".
- **Código incompleto** (ex.: `BDP-12`): o sistema pede o código completo e não escolhe nenhum exemplar.
- **Código já conferido**: o sistema avisa, mostra a conferência anterior (data, responsável, local, estado) e não grava de novo. Quem gerencia o inventário pode **corrigir** a conferência, informando o motivo.
- **Localização em conferência** (coluna da direita): escolha a estante que está sendo conferida e ela vem preenchida em cada conferência.
- **Modo rápido**: cada código digitado é conferido na hora, com o estado já cadastrado e a localização em conferência.
- Exemplar sem localização: a localização encontrada passa a ser a confirmada.

## Como digitar o código do exemplar

A biblioteca identifica os exemplares **somente pelo código escrito** na etiqueta, como `BDP-000123`. Não há código de barras, QR Code, RFID ou NFC, e não é preciso leitor, celular ou câmera.

- Cada exemplar físico tem um código único e permanente, gerado no cadastro do exemplar. O código nunca muda e nunca é reaproveitado, nem depois da baixa.
- Letras maiúsculas ou minúsculas tanto faz: `bdp-000123` = `BDP-000123`.
- Espaços antes e depois são ignorados (inclusive de texto colado).
- O hífen pode faltar ou virar espaço: `BDP000123` e `BDP 000123` também funcionam.
- Os **6 números são obrigatórios**. `BDP-123` não é completado com zeros: um dígito esquecido poderia apontar para outro exemplar que existe. O sistema mostra "Código incompleto" e não seleciona nada.
- Não existe correspondência aproximada: ou o código é exatamente o de um exemplar, ou aparece a mensagem de código não encontrado.
- Atalhos na conferência: **Enter** pesquisa, **F2** ou **/** voltam ao campo do código, e **Esc** limpa o campo.
- O mesmo código funciona na lista de **Exemplares**, em **Empréstimos → Novo empréstimo** e em **Devoluções**.

## Como tratar divergências

Na página do inventário, a aba **Decisões necessárias** mostra o que precisa de decisão:

- **Encontrado com diferença** (outra localização ou outro estado). As decisões possíveis são:
  - **Atualizar o cadastro com o que foi encontrado**;
  - **Manter o cadastro como está**.
- **Previsto e não localizado**. As decisões possíveis são:
  - **Manter sem alteração (procurar depois)**;
  - **Registrar como extraviado**.
- **Emprestado**: aparece à parte e não é ausência. O inventário nunca encerra empréstimos: a devolução é feita em Empréstimos.

Nenhum exemplar vira extraviado sem uma decisão registrada. O botão **Manter todos os não localizados sem alteração** decide de uma vez, sem marcar extravios.
Quem tem permissão de gerenciar também pode marcar, na própria conferência, "já atualizar o cadastro", e a decisão fica registrada.

## Como concluir um inventário

1. Trate todas as pendências.
2. Clique em **Concluir inventário** e confirme.
3. O sistema grava o resumo final e o resultado por título, e bloqueia o inventário.
4. Em **Relatório**:
   - veja os números (previstos, conferidos, não conferidos, local diferente, estado divergente, cadastrados no inventário, extravios, correções);
   - exporte em **CSV** ou **PDF**.

Os relatórios não têm dados de alunos.

## Como gerar e imprimir etiquetas

1. Escolha os exemplares de uma destas formas:
   - na página do exemplar ou do livro (**Imprimir etiqueta**);
   - logo depois do cadastro;
   - em **Exemplares**, marcando as caixas e clicando **Gerar etiquetas dos selecionados**;
   - em **Exemplares**, com **Etiquetas de todos os filtrados** (até 500 por vez; por exemplo, filtro "Etiqueta ainda não gerada").
2. Em **Etiquetas**:
   - escolha o modelo;
   - informe quantas posições pular na primeira folha (para reaproveitar folha usada);
   - marque, se quiser, o contorno (linhas finas de recorte, úteis também no teste em papel comum);
   - em modelos de lombada, escolha se exemplares sem CDD aprovada recebem a etiqueta provisória (padrão) ou ficam de fora.
3. Confira a **pré-visualização em tamanho real** e clique em **Gerar PDF em tamanho real**.
4. Na impressão, escolha **Tamanho real / 100%** e desligue "Ajustar à página". Faça um teste em papel comum antes de usar a folha de etiquetas.

Tipos de impressão disponíveis:

1. **Etiqueta individual**: modelo "Patrimonial individual — 50 × 20 mm" (uma página do tamanho da etiqueta, para impressora de etiquetas ou para recortar).
2. **Várias etiquetas por folha A4**: por exemplo "Patrimonial A4 — 3 × 12 (50 × 20 mm, com espaço para recorte)". As folhas são completadas na ordem e a quebra de página é automática.
3. **Etiquetas patrimoniais** (modelos do tipo patrimonial).
4. **Etiquetas de lombada** (modelos do tipo lombada).

### Etiqueta patrimonial (modelo simples)

```
┌──────────────────────────────┐
│   BIBLIOTECA DOM PEDRO II    │
│         BDP-000001           │   ← maior texto, em negrito, centralizado
└──────────────────────────────┘
```

- Somente texto: fundo branco, letras pretas (Helvetica), sem imagens.
- **Sem código de barras e sem QR Code.** O código é lido pela bibliotecária e digitado no sistema.
- O código é sempre o elemento de maior destaque e nunca é cortado: se faltar espaço, a letra diminui só o necessário e, se ainda assim não couber, as informações opcionais saem primeiro.
- Margem interna para o recorte não comer as letras.
- Opcionais (desligados nos modelos de 50 × 20 mm): título curto, CDD **só se aprovada** e localização **só se confirmada**, em letra pequena abaixo do código.

### Etiqueta de lombada

```
┌────────────┐     ┌────────────┐
│   869.3    │     │            │
│ LIT-E02-P03│     │ BDP-000004 │   ← provisória: sem CDD aprovada
│ BDP-000002 │     │            │
└────────────┘     └────────────┘
```

- **CDD aprovada** em destaque (nunca a CDD antiga da planilha, que não é validada).
- Localização confirmada (opcional) e o **código BDP** do exemplar.
- **Sem CDD aprovada**: sai a etiqueta de identificação **provisória**, só com o código BDP. Depois da aprovação no módulo CDD, imprima a lombada definitiva.
- **Código de autor (Cutter)**: não é impresso. O sistema ainda não tem um código de autor validado; o "Cód. Autor" das planilhas antigas é só referência histórica e não vai para a etiqueta. Nada é inventado.

Nenhum dado de aluno, senha ou token é impresso. Livros sem exemplar físico não geram etiqueta (sem exemplar não há código). Cada exemplar aparece uma vez por geração, mesmo se for escolhido duas vezes, e exemplares baixados ficam de fora.

## Como configurar tamanhos e margens das etiquetas

1. Abra **Etiquetas → Modelos e medidas** (perfil administrador ou bibliotecária).
2. Edite um modelo ou crie um novo. Todas as medidas são em milímetros:
   - página;
   - etiqueta;
   - margem superior e esquerda;
   - espaço entre colunas e entre linhas;
   - número de colunas e linhas.
3. Escolha o tipo:
   - **patrimonial**: nome da biblioteca e código BDP em destaque;
   - **lombada**: CDD aprovada e código BDP, para etiquetas estreitas.
4. Marque o que aparece na etiqueta (o código BDP sempre aparece).
5. O sistema recusa medidas que não cabem na página e avisa se o código ficar com letra pequena demais para ler e digitar sem erro.

Modelos que já vêm cadastrados (confira as medidas na embalagem da sua folha):

- **Patrimonial A4 — 3 × 12 (50 × 20 mm, com espaço para recorte)** — sugerido (migração 100; é o modelo escolhido por padrão);
- **Patrimonial individual — 50 × 20 mm** (migração 100);
- **Lombada individual — 30 × 20 mm** (migração 100);
- Patrimonial A4 — 3 × 11 (63,5 × 25,4 mm);
- Patrimonial A4 — 2 × 7 (99,1 × 38,1 mm);
- Lombada A4 — 5 × 13 (38,1 × 21,2 mm);
- Individual — 50 × 25 mm (impressora de etiquetas).

A coluna antiga `codigo_barras` da tabela `etiqueta_modelos` continua no banco (para não recriar a tabela), mas é ignorada: nenhuma etiqueta desenha barras.

O nome impresso vem de `ETIQUETA_BIBLIOTECA` no `.env`. Se ficar vazio, sai "Biblioteca Dom Pedro II".

## Quais funcionalidades dependem da conferência física

Estas ações só fazem sentido com o livro em mãos:

- cadastro de exemplares (gera o código BDP);
- confirmação da localização de cada exemplar;
- impressão e colagem das etiquetas;
- conferência do inventário e decisões sobre ausências;
- empréstimos (só de exemplares cadastrados e disponíveis).

Estas funcionam sem a conferência física:

- cadastro e edição de livros;
- classificação CDD;
- cadastro de localizações e modelos de etiqueta;
- relatórios.

## Permissões

| Perfil | Pode |
| --- | --- |
| Administrador e bibliotecária | Tudo: exemplares, baixas, localizações, inventário (criar, iniciar, decidir, concluir), conferência, etiquetas e modelos. |
| Auxiliar | Cadastrar exemplares, conferir no inventário e imprimir etiquetas. Não cria nem conclui inventário, não decide divergências, não dá baixa e não muda modelos. |
| Consulta | Só visualizar as listas. |

As permissões são conferidas no servidor em cada página e em cada formulário. Os formulários têm proteção CSRF, e as ações ficam registradas em `acoes_administrativas`.

## Banco de dados

A migração `database/migrations/070_exemplares_inventario_etiquetas.sql` é incremental. Ela não apaga nem muda livros, alunos, administradores, classificações, empréstimos ou exemplares existentes, e não cria exemplares.

O que ela acrescenta:

- localizações: complemento, código, descrição e ativação;
- exemplares: a situação "danificado", quem cadastrou, data da etiqueta e dados da baixa;
- a tabela `inventario_itens` (uma linha por exemplar e inventário, sem repetição);
- a tabela `etiqueta_modelos`;
- gatilhos que impedem trocar o código BDP, apagar exemplares e alterar inventários finalizados.

Para aplicar: faça um backup e rode `php database/migrar.php`. As migrações já aplicadas são puladas.

Teste automático (usa um banco temporário com dados fictícios e o apaga no final):

```bash
php admin/tests/inventario-teste.php
php admin/tests/codigos-etiquetas-teste.php   # códigos escritos e etiquetas só com texto
```

A migração `100_etiquetas_codigo_escrito.sql` (opcional, mas recomendada) não mexe em exemplares, códigos, empréstimos ou CDDs: só zera a antiga opção de código de barras dos modelos e acrescenta os modelos de 50 × 20 mm e 30 × 20 mm.
