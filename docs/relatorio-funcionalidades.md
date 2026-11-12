# Relatório das funcionalidades — Biblioteca Dom Pedro II

**Data:** 12/11/2026
**Base:** código da pasta `C:\xampp\htdocs\BibliotecaDomPedro2`, a branch `main` do GitHub e os relatórios das etapas já entregues.

> **Atenção:** a pasta do computador está um pouco atrás do GitHub. A **Central de Ajuda** (`admin/ajuda.php`, PR #24) e o novo acesso de demonstração (PR #23) já estão na `main`, mas ainda não aparecem na pasta local. Para tê-los no XAMPP, basta atualizar a pasta (`git pull`).

---

## 1. Visão geral

O sistema é o site da biblioteca escolar Dom Pedro II, feito em PHP tradicional com MySQL/MariaDB (roda no XAMPP). Ele tem duas partes:

| Parte | Quem usa | Login |
|---|---|---|
| **Site público** | alunos e visitantes | não precisa |
| **Painel administrativo** (`/admin/`) | bibliotecária e equipe | e-mail e senha |

Não usa framework nem build, não carrega nada de servidores externos (funciona sem internet, exceto a pesquisa de CDD nas fontes públicas) e não exibe nenhum livro fictício no site público.

---

## 2. Site público

| Página | O que faz |
|---|---|
| **Início** (`index.php`) | Banner com o nome e o emblema da biblioteca, pesquisa, números do acervo, livros adicionados recentemente, categorias, horário e atalhos. |
| **Catálogo** | Pesquisa por título, autor, ISBN, assunto ou categoria, **com ou sem acentos** ("coracao" encontra "Coração"); filtros por categoria, autor, ano e disponibilidade; ordenação (título, autor, ano, recentes); 24 livros por página. |
| **Página do livro** | Dados bibliográficos, sinopse, capa, CDD aprovada, disponibilidade física e livros relacionados. |
| **Categorias** | Lista com a quantidade de livros de cada uma. |
| **Autores** | Autores com mais títulos. |
| **Sobre a biblioteca** | Apresentação, espaço, objetivos, horário e contato (textos editáveis no painel). |
| **Regras e funcionamento** | Como consultar, emprestar, devolver e conservar os livros (textos editáveis no painel). |

Detalhes importantes:

- **Disponibilidade** vem só dos exemplares físicos cadastrados (código BDP). A quantidade das planilhas antigas nunca é usada. Sem exemplares, aparece "Exemplares ainda não identificados".
- Só a **CDD aprovada** aparece para o público.
- **Capas** enviadas pelo painel; sem capa, aparece uma capa padrão neutra.
- Menu lateral recolhível, versão para celular, rodapé institucional.
- **Site em manutenção**: o administrador pode tirar o site do ar com uma mensagem.
- Nada no site público grava no banco.

---

## 3. Painel administrativo

### 3.1 Acesso e segurança

- Login com e-mail e senha; bloqueio após 5 tentativas erradas (ajustável); sessão encerrada após 2 horas sem uso (ajustável).
- Logout seguro, proteção CSRF em todos os formulários, senhas criptografadas.
- **Quatro perfis**: Administrador, Bibliotecária, Auxiliar de biblioteca e Somente consulta, com permissões conferidas no servidor em cada página.
- **Modo demonstrativo**: quando o MySQL não está ligado, o painel aceita uma conta de demonstração (na `main`: `Bibliotecaria@demo` / `demo123`) só fora de produção, sem gravar nada.
- Registro de ações: quem fez o quê e quando (cadastros, edições, arquivamentos, contas).
- **Meu perfil**: cada pessoa troca a própria senha.

### 3.2 Dashboard

Números reais do banco: títulos × exemplares, empréstimos ativos e atrasados, CDD pendente, pendências de importação e inventários em andamento, com gráficos (Chart.js guardado no próprio projeto) e atalhos. O perfil Consulta não vê nomes de alunos.

### 3.3 Acervo (livros)

- Listagem com pesquisa sem acentos, filtros, ordenação e paginação.
- **Cadastrar, editar e ver detalhes** do livro (dados, exemplares, CDD, histórico e dados originais da planilha).
- **Arquivar e restaurar** em vez de excluir (com motivo; bloqueado se houver exemplar emprestado).
- **Capa do livro**: envio de imagem.
- **Categorias**: a página existe, mas o cadastro ainda é **demonstrativo** (não grava).

### 3.4 Classificação CDD

- Painel da CDD com pesquisa nas fontes públicas, sugestão por livro e em lote.
- **Fila de aprovação**: a CDD sugerida só vale depois de aprovada (a CDD antiga das planilhas nunca é aprovada sozinha).
- Histórico que não pode ser alterado e relatórios exportáveis.

### 3.5 Importação do acervo (planilhas Excel)

- Envio de `.xlsx` ou `.csv`, mapeamento de colunas, validação e revisão linha a linha antes de gravar.
- Prevenção de duplicidades (mesmo ISBN ou mesmo título/autor/editora/ano); repetidos viram "outro exemplar".
- Dados antigos (CDD, localização, quantidade) guardados em `registros_legados`, só como referência.
- **ISBNs inválidos em lote**: explica o erro de cada ISBN e sugere correções seguras.
- Histórico de importações.
- **Acervo real já carregado** por script: aba Catalogar (5.687 linhas) e aba ENTRADA OBRAS (modo `--complementar`, que só preenche campos vazios).

### 3.6 Exemplares, localizações e código BDP

- **Livro × exemplar**: o livro é a obra; o exemplar é cada volume físico, com código próprio `BDP-000001`.
- O código é gerado automaticamente no cadastro, nunca se repete e nunca é reaproveitado.
- Cadastro só dos volumes que estão em mãos; a quantidade da planilha não vira exemplar.
- Ações no exemplar: editar conservação, transferir de localização, mudar situação, registrar extravio e dar baixa (tudo no histórico; nunca exclusão).
- **Localizações**: setor, estante, prateleira e código curto; desativar em vez de excluir. As localizações antigas das planilhas ficam só para consulta.
- **Identificação só por código escrito**: não há código de barras, QR Code ou RFID. O código é digitado; `bdp000121` funciona, mas `BDP-121` é recusado como "Código incompleto".

### 3.7 Inventário digital

- Criar inventário (acervo inteiro ou um setor), iniciar, pausar, concluir ou cancelar.
- Conferência digitando o código BDP: confirma estado e localização encontrados.
- Livro em mãos sem código pode ser cadastrado durante a conferência.
- Decisões sobre divergências (não encontrado, local diferente etc.) e relatório final em CSV/PDF.

### 3.8 Etiquetas

- PDF no tamanho real, **só com texto** ("BIBLIOTECA DOM PEDRO II / BDP-000121"): etiqueta patrimonial 50 × 20 mm, folha A4 e lombada provisória.
- Geração a partir do cadastro, do exemplar, do livro ou de vários exemplares selecionados.
- **Modelos de etiqueta** editáveis (tamanhos e margens).

### 3.9 Alunos

- **Importar Alunos** (`.xlsx`/`.csv`): mapeamento de colunas, prévia, prevenção de duplicidade pela matrícula e atualização separada com confirmação. O arquivo não fica guardado no servidor.
- Alunos não têm login. Histórico de cada aluno pela circulação.

### 3.10 Circulação (empréstimos)

- **Novo empréstimo em 3 passos**: aluno → código BDP do exemplar (ou pesquisa por título/autor/ISBN) → conferência e confirmação.
- **Devoluções** pelo código BDP, **renovações** e lista de **atrasos** com os dias de atraso.
- Lista com abas Ativos, Atrasados, Devolvidos e Histórico completo.
- Histórico por aluno e por exemplar.
- Regras configuráveis: prazo (provisório de 14 dias), dias de renovação, máximo de renovações, renovar atrasado, prazo máximo, limite de livros por aluno e bloqueio por atraso (os dois últimos desligados).
- Proteção contra dois empréstimos simultâneos do mesmo exemplar.
- Não há multas, reservas nem avisos por e-mail.

### 3.11 Relatórios

Central com 9 seções: visão geral, acervo, exemplares, empréstimos e devoluções, atrasos, alunos, inventários, CDD e atividades administrativas. Tem filtros de período, gráficos com tabela de dados e exportação em **PDF (A4), CSV e XLSX**. Relatórios com dados pessoais de alunos só para Administrador e Bibliotecária.

### 3.12 Sistema

- **Administradores**: cadastrar, editar, ativar/desativar contas e redefinir senhas (só Administrador; o último administrador ativo é protegido).
- **Site público**: editar os textos de Sobre e Regras.
- **Configurações**: identificação, regras de empréstimo, domínio do e-mail dos alunos, segurança do painel e site em manutenção, com histórico imutável das alterações. O valor do painel vale mais que o `.env`.
- **Perfis e permissões**: matriz que mostra o que cada perfil pode fazer.
- **Manutenção** (só Administrador): diagnóstico do sistema, cópia de segurança `.sql` e limpeza de tentativas de login.

### 3.13 Central de Ajuda (na `main`, falta atualizar a pasta local)

**Tutorial do Sistema** com 16 módulos e 99 tutoriais passo a passo (incluindo 15 perguntas frequentes), pesquisa sem acentos, capturas de tela com dados fictícios e o botão **Como usar esta página?** em 48 páginas do painel.

### 3.14 Visual

Site público e painel modernizados (etapas 8 e 9): azul institucional com detalhes dourados, menu lateral recolhível, cartões, rodapé e versão para celular.

---

## 4. Qualidade e documentação

- **16 testes automáticos** em `admin/tests/` (importação, CDD, ISBN, painel, alunos, empréstimos, inventário, site público, relatórios, configurações, etiquetas, fluxo completo, segurança, backup, desempenho com 10.000 livros e, na `main`, a ajuda). Todos usam bancos temporários e dados fictícios.
- Manuais em `docs/`: manual da bibliotecária, publicação, backup e recuperação, segurança e LGPD, e guias de cada módulo.
- 11 migrações de banco que só criam ou acrescentam estruturas (nenhuma apaga dados).

---

## 5. O que ainda está pendente

- Cadastro de **categorias** é demonstrativo (não grava).
- **Reservas** de livros e **avisos de atraso por e-mail** (dependem de autorização da escola).
- **Tela de consulta e edição de alunos** (hoje só pela importação).
- Organização física por CDD e código de autor (Cutter) para a lombada, a definir com a bibliotecária.
- Dados oficiais da escola: horário, contatos, nome oficial, regulamento de empréstimo (o prazo de 14 dias é provisório) e créditos.
- A pesquisa de CDD nas fontes da internet ainda não foi testada com internet.
