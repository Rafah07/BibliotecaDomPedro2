# Manual da bibliotecária — Biblioteca Dom Pedro II

Guia rápido do painel administrativo. Todos os exemplos usam **dados fictícios** (livros, alunos e códigos inventados).

> No computador da biblioteca, o painel abre em `http://localhost/BibliotecaDomPedro2/admin/` (ou no endereço que a escola definir quando o sistema for publicado).

> **Passo a passo completo dentro do painel:** em **Configurações → Tutorial do Sistema** (ou no menu do seu nome → **Tutorial do Sistema**) está a Central de Ajuda, com tutoriais de todos os módulos, pesquisa e o botão **Como usar esta página?** em cada tela. Este manual é o resumo impresso.

**Lembrete importante:** a biblioteca identifica cada livro físico **somente pelo código escrito na etiqueta**, como `BDP-000123`. Não há código de barras nem QR Code. Você lê o código e digita.

---

## 1. Como entrar no sistema

1. Abra o endereço do painel no navegador.
2. Digite o seu **e-mail** e a sua **senha** e clique em **Entrar**.
3. Cada pessoa tem a sua conta. Não use a conta de outra pessoa nem compartilhe a sua.
4. Errou a senha várias vezes? O acesso fica bloqueado por 15 minutos. Espere e tente de novo, ou peça ao administrador para redefinir a senha.
5. Para trocar a sua senha: menu do seu nome (canto superior) → **Meu perfil**. Use pelo menos 10 caracteres, com letras e números.

Se você ficar muito tempo sem usar o painel (padrão: 2 horas), ele pede o login de novo.

**Sem o MySQL interligado** (por exemplo, com o MySQL do XAMPP desligado), o painel abre em **modo demonstrativo**, com dados fictícios. Para acessar mesmo assim, use o e-mail **Bibliotecaria@demo** e a senha **demo123**. Nada do que for feito nesse modo é gravado. Esse acesso não funciona no servidor da escola em produção nem quando o banco está conectado.

## 2. Como pesquisar livros

1. Clique em **Acervo** no menu.
2. No campo de pesquisa, digite parte do título, autor, editora ou ISBN. Acentos não importam: `coracao` encontra "Coração".
   - Exemplo: digite `pequeno` para encontrar *O Pequeno Explorador* (livro fictício).
3. Use os filtros (categoria, situação da CDD, etc.) e a ordenação para refinar.
4. Clique no título para abrir os **detalhes do livro**: dados, exemplares físicos, CDD e histórico.

Para encontrar um exemplar pelo código, use **Exemplares** e digite o código (ex.: `BDP-000045`).

## 3. Como cadastrar um livro

1. **Acervo → Cadastrar livro.**
2. Preencha o **Título do livro** (obrigatório) e o que souber: autor, subtítulo, ISBN, editora, ano, edição, idioma, categoria, assunto, palavras-chave e sinopse.
   - Exemplo: Título *Histórias do Rio Azul*, autora *Marina Fictícia*, editora *Editora Exemplo*, ano 2020.
3. O campo **CDD** só é preenchido se você já tiver a classificação conferida. Uma CDD antiga das planilhas **nunca** é aprovada sozinha: ela passa pela **Classificação CDD**.
4. Clique em **Salvar livro** (ou **Salvar e cadastrar outro**, para seguir para o próximo livro). Depois, na ficha do livro, use **Cadastrar exemplar** para registrar os volumes físicos.

Livro errado ou duplicado não é apagado: use **Arquivar** na página do livro (ele sai do site público, mas o histórico fica).

## 4. Como cadastrar um exemplar físico

O **livro** é a obra (título, autor). O **exemplar** é cada volume físico que está na prateleira.

1. Abra o livro em **Acervo** e clique em **Cadastrar exemplar** (ou **Exemplares → Cadastrar exemplar** e pesquise o livro).
2. Informe o estado de conservação, a situação inicial (**Disponível** para poder emprestar), a localização e a **quantidade de volumes que estão com você**.
3. Marque a confirmação de que os volumes estão em mãos e clique em **Confirmar cadastro**.

Só cadastre exemplares que você está vendo: a quantidade das planilhas antigas não vira exemplar automaticamente.

## 5. Como gerar o código BDP

Você **não** escolhe nem digita o código novo: o sistema gera sozinho, na ordem, no momento do cadastro do exemplar (passo 4).

- Exemplo: se o último exemplar cadastrado foi `BDP-000120`, os dois próximos recebem `BDP-000121` e `BDP-000122`.
- A tela mostra os códigos gerados logo após o cadastro.
- Um código **nunca se repete** e **nunca é reaproveitado**, nem depois de uma baixa ou extravio.
- Escreva ou cole a etiqueta no livro antes de guardá-lo.

Ao digitar um código em qualquer tela: maiúsculas ou minúsculas tanto faz e o hífen pode faltar (`bdp000121` funciona), mas os **6 números são obrigatórios**. `BDP-121` mostra "Código incompleto", para evitar escolher o livro errado.

## 6. Como imprimir etiquetas escritas

As etiquetas têm só texto, por exemplo:

```
BIBLIOTECA DOM PEDRO II
      BDP-000121
```

1. Escolha os exemplares: logo depois do cadastro (**Imprimir etiqueta(s)**), na página do exemplar ou do livro, ou em **Exemplares** marcando as caixas e clicando em **Gerar etiquetas dos selecionados**.
2. Em **Etiquetas**, escolha o modelo:
   - "Patrimonial individual — 50 × 20 mm" para impressora de etiquetas;
   - um modelo A4 (várias por folha) para impressora comum. Se a folha já foi usada, informe quantas posições pular.
3. Clique em **Atualizar pré-visualização**, confira e clique em **Gerar PDF em tamanho real**.
4. Na janela de impressão, escolha **Tamanho real / 100%** e desligue "Ajustar à página". Teste antes em papel comum.

Etiqueta de **lombada** só sai definitiva com a CDD aprovada. Sem CDD aprovada sai a etiqueta provisória (só o código BDP).

## 7. Como cadastrar uma localização

1. **Exemplares → Localizações → Nova localização.**
2. Preencha o setor (obrigatório), a estante, a prateleira e, se quiser, um complemento e um código curto.
   - Exemplo: setor *Literatura infantil*, estante *E03*, prateleira *P02*, código `LIT-E03-P02`.
3. Salve. A mesma combinação não pode ser cadastrada duas vezes.
4. Para mudar um exemplar de lugar: abra o exemplar → **Transferir**.

Localizações não são apagadas, só **desativadas**.

## 8. Como iniciar um inventário

1. **Inventário → Novo inventário.**
2. Informe o nome (ex.: *Inventário 1º semestre — Literatura infantil*), a data de início, o responsável e o setor (ou "Acervo inteiro").
3. Salve. O inventário fica **em preparação**.
4. Confira os dados e clique em **Iniciar conferência**. A lista de exemplares previstos é fixada nesse momento.

## 9. Como conferir livros (e corrigir o cadastro)

Toda a conferência e a correção acontecem na página **Conferir e corrigir livros** do Inventário. Não existe página de revisão separada.

Fluxo: **Importar planilha → Organizar estantes → Pesquisar livro → Corrigir informações → Registrar código BDP → Marcar como encontrado.**

1. Na página do inventário, clique em **Conferir e corrigir livros**.
2. Pegue o livro na prateleira e use **Pesquisar livro: código BDP, título, autor ou ISBN**. Com etiqueta, digite o código (ex.: `BDP-000121`); sem etiqueta, digite o título ou o autor.
3. Nos resultados, as linhas vindas da planilha aparecem como **Linha N da planilha · Pendente**. Clique em **Conferir**.
4. Corrija o que estiver errado em **Informações do livro** (título, autor, ISBN, editora, ano, categoria...; todos os campos são opcionais) e escreva o **Código BDP do carimbo**, se o livro tiver. A ficha aparece aberta tanto nos registros da planilha quanto nos exemplares que já têm código.
   Para corrigir só os dados, sem marcar como encontrado, use o botão **Corrigir dados** ao lado do livro nos resultados (ou **Corrigir dados do livro** na lista de itens do inventário) e clique em **Salvar correções**.
5. Clique em **Salvar e marcar como encontrado**. O cadastro e o inventário são atualizados juntos.
6. Livro em mãos que não aparece na pesquisa: use **+ Cadastrar livro não listado**. O sistema mostra antes os livros parecidos; se for outro exemplar de uma obra já cadastrada, escolha-a.
7. Ao terminar uma estante ou setor, em **Finalizar estante ou setor**, selecione os livros que realmente não estão lá e clique em **Marcar selecionados como não localizados** (há confirmação). Nada vira "Não localizado" sozinho, e a marcação pode ser desfeita com **Voltar para pendente**.
8. Quando terminar, trate as decisões necessárias e clique em **Concluir inventário**. O relatório fica em **Relatório do inventário**.

Os status são **Pendente**, **Encontrado** e **Não localizado**. Os **alertas cadastrais** (informação incompleta, possível inconsistência, possível duplicidade) são independentes e não impedem marcar como encontrado. Um código BDP nunca se repete: se já pertence a outro exemplar, o sistema recusa. Cliques repetidos não criam registros duplicados.

## 10. Como registrar empréstimos

1. **Empréstimos → Novo empréstimo.**
2. **Aluno:** digite a matrícula (mais seguro) ou o nome e clique em **Pesquisar aluno**. Escolha o aluno certo; em caso de nomes iguais, confira a matrícula e a turma.
   - Exemplo: matrícula `2026000` — *Aluno Exemplo da Silva*, 6º A (fictício).
3. **Livro e exemplar:** digite o código BDP da etiqueta (ex.: `BDP-000121`) e pressione **Enter**.
4. **Resumo e confirmação:** confira o título, o código e a data de devolução, marque a confirmação e clique em **Confirmar empréstimo**.

O sistema não deixa emprestar um exemplar que já está emprestado, em manutenção, extraviado ou baixado, nem emprestar para aluno inativo.

## 11. Como registrar devoluções

1. No menu, grupo **Circulação**, clique em **Devoluções**.
2. Digite o código BDP do livro devolvido (ex.: `BDP-000121`) e pressione **Enter**.
3. O sistema encontra o empréstimo em aberto. Confira o aluno e o livro.
4. Se o livro voltou danificado, marque a opção de enviar para manutenção.
5. Clique em **Registrar devolução**. O exemplar volta a ficar disponível.

A devolução nunca apaga o empréstimo: ele vai para o histórico.

## 12. Como renovar empréstimos

1. Em **Empréstimos**, abra o empréstimo (ou procure pelo aluno ou pelo código BDP).
2. Clique em **Renovar**.
3. Confira a **nova data de devolução** e clique em **Renovar empréstimo**.

Os dias da renovação e o limite de renovações seguem as regras definidas em **Sistema → Configurações**.

## 13. Como consultar atrasos

1. **Empréstimos → Atrasos.**
2. A lista mostra os empréstimos vencidos, do mais antigo para o mais recente, com os dias de atraso, o aluno, a turma e o livro.
3. Clique no empréstimo para ver os detalhes, renovar ou registrar a devolução.

O sistema não cobra multa e não envia mensagens: o contato com o aluno é feito pela escola.

## 14. Como utilizar relatórios

1. Clique em **Relatórios**.
2. Escolha a seção: Acervo, Exemplares físicos, Empréstimos e devoluções, Atrasos, Alunos, Inventários, Classificação CDD ou Atividades.
3. Escolha o período (ex.: *01/03/2026 a 30/06/2026*) e os filtros.
4. Para baixar, use **CSV**, **Excel (XLSX)** ou **PDF** em cada tabela.

Relatórios com nomes de alunos são **documentos internos**: não publique, não envie por WhatsApp ou e-mail pessoal e apague o arquivo depois de usar. Cada exportação fica registrada.

## 15. Como importar alunos localmente

Faça isto **somente** no computador autorizado da biblioteca, com a planilha oficial da secretaria.

1. **Importar Alunos → Nova importação de alunos.**
2. Escolha o arquivo `.xlsx` ou `.csv`. Para testar o formato, use **Baixar modelo** (tem dois alunos fictícios).
3. **Mapear colunas:** indique qual coluna é Nome, Matrícula, Turma, Turno e E-mail. Deixe as outras como **Ignorar coluna** (CPF, endereço, telefone, responsáveis não são importados).
4. **Prévia:** confira quantos serão incluídos, atualizados e os erros. Nada é gravado ainda.
5. Escolha o que fazer com alunos já cadastrados (o recomendado é deixar como estão) e clique em **Confirmar importação**.
6. A planilha **não fica guardada** no sistema. Depois de importar, apague a planilha do computador (inclusive da pasta Downloads e da lixeira).

## 16. Como consultar, cadastrar e corrigir alunos

**Gerenciamento → Alunos** (bibliotecária, administrador e auxiliar; o perfil Somente consulta não vê alunos).

**Consultar**

1. Digite nome, matrícula ou e-mail no campo **Nome, matrícula ou e-mail do aluno...** e clique em **Pesquisar**. Acentos e maiúsculas não importam: `joao` encontra "João".
2. Use os filtros **Turma**, **Turno**, **Situação** (Ativos, Inativos, Todos) e **Empréstimos** (Com livro emprestado, Com atraso). Clique em **Nome**, **Matrícula** ou **Turma** para ordenar.
3. Clique no nome do aluno para abrir os **detalhes**: dados cadastrais, empréstimos ativos e atrasados, devoluções anteriores e o **Histórico de alterações do cadastro** (quem mudou o quê e quando).

**Corrigir um cadastro** (bibliotecária e administrador) — sem importar a planilha de novo

1. Na lista, clique em **Editar** (ou, nos detalhes, em **Editar cadastro**).
2. Corrija o que estiver errado (nome, matrícula, e-mail institucional, turma, turno) e clique em **Salvar correção**.
   - Exemplo fictício: "Joao Ficticio" → "João Fictício"; turma "6º A" → "7º A".
3. Ao mudar a **matrícula**, marque **Conferi com a secretaria: a matrícula correta é a nova.** e salve de novo. A matrícula nova não pode ser de outro aluno.
4. O aluno continua o mesmo: todos os empréstimos, devoluções e renovações continuam ligados a ele.

**Cadastrar um aluno** fora da importação (ex.: aluno transferido que ainda não está na planilha)

1. **Alunos → Cadastrar aluno.** Preencha **Nome** e **Matrícula** (obrigatórios) e, se souber, e-mail, turma e turno.
2. As regras são as mesmas da importação: matrícula sem espaços e sem repetir; e-mail válido (e do domínio da escola, se definido em Configurações).
3. Se já houver aluno com o mesmo nome ou e-mail, o sistema mostra a lista e pede confirmação de que é outra pessoa.

**Inativar** (aluno que saiu da escola): nos detalhes do aluno, quadro **Inativar cadastro**. O aluno não faz novos empréstimos; os que estão em aberto continuam e podem ser devolvidos. Nada é apagado. Para desfazer, **Reativar cadastro**. Alunos **não são excluídos**.

## 17. Como gerenciar as categorias dos livros

**Principal → Categorias.** Todos consultam; só bibliotecária e administrador alteram.

1. **Cadastrar categoria**: informe o **Nome da categoria** (até 80 caracteres) e, se quiser, a **Descrição**, e clique em **Salvar categoria**. Ela já aparece no **Cadastrar livro** e no **Editar livro**.
2. Não é possível repetir um nome: "POESIA", "poesia" e "Poésia" são a mesma categoria (o sistema avisa **Já existe a categoria**).
3. **Editar**: corrige o nome ou a descrição. A mudança aparece em todos os livros da categoria, nos filtros e no site, sem alterar os livros.
4. **Desativar**: só para categorias **sem livros ativos** (as que têm livros mostram **Em uso**). Para tirar de uso uma categoria com livros, troque a categoria desses livros em **Editar livro** e depois desative. Para desfazer, filtro **Situação → Desativadas** e **Reativar**. Categorias não são excluídas.
5. A aba **Categorias das planilhas antigas** mostra os textos de categoria vindos das planilhas importadas, só para consulta (ajuda a decidir quais categorias cadastrar).

## 18. Como realizar backup

Somente o **Administrador** faz a cópia de segurança.

1. **Sistema → Manutenção → Cópia de segurança.**
2. Marque a confirmação e clique em **Baixar cópia de segurança**.
3. Guarde o arquivo em local protegido (pendrive ou HD da escola guardado em local trancado). **Nunca** na pasta do site, no GitHub, em e-mail pessoal ou nuvem particular.
4. Copie também a pasta `uploads/capas/` (as capas não vão no arquivo).

Restaurar uma cópia é tarefa do responsável técnico, sempre num banco novo. Veja [`backup-recuperacao.md`](backup-recuperacao.md).

## 19. Como sair do sistema com segurança

1. Clique no seu nome (canto superior) e em **Sair**, ou use **Sair** no final do menu.
2. Feche o navegador se o computador for compartilhado.
3. Ao se afastar do computador por pouco tempo, bloqueie o Windows (tecla **Windows + L**).

---

### Dúvidas comuns

- **"Código incompleto"**: faltam números. Digite os 6 números depois de `BDP` (ex.: `BDP-000045`).
- **"Código não encontrado"**: confira a etiqueta. Se o livro não tem exemplar cadastrado, cadastre (passo 4).
- **"Exemplar já emprestado"**: registre a devolução anterior antes de emprestar de novo.
- **Voltei para a tela de login sozinha**: a sessão terminou por inatividade. Entre de novo; o que não foi salvo precisa ser refeito.
- **Não aparece um menu**: o seu perfil não tem essa permissão. Fale com o administrador.
- **"A matrícula ... já pertence a outro aluno"**: a matrícula já está cadastrada. Pesquise em **Alunos** (com **Situação → Todos**): o aluno provavelmente já existe.
- **"A categoria ... não pode ser desativada"**: ainda há livros ativos nela. Troque a categoria desses livros antes.
