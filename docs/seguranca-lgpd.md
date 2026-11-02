# Segurança e proteção de dados pessoais (LGPD)

O sistema guarda dados pessoais de alunos (nome, matrícula, turma, histórico de empréstimos) e dados das contas da equipe. Este documento separa:

1. as **medidas técnicas** que o sistema já aplica (verificadas na auditoria da Etapa 7);
2. as **decisões organizacionais** que cabem à escola.

> Este documento **não** declara que o sistema ou a escola estão em conformidade total com a LGPD (Lei 13.709/2018). Conformidade depende também de decisões, documentos e rotinas da escola, que o software não substitui. Recomenda-se que a direção valide as decisões abaixo com o encarregado de dados (DPO) da rede de ensino ou com orientação jurídica.

## 1. Medidas técnicas existentes

### Acesso ao painel
- Senhas guardadas só como hash (`password_hash`, algoritmo padrão do PHP, atualizado automaticamente quando o PHP muda o padrão). Mínimo de 10 caracteres, com letras e números.
- Bloqueio por tentativas: depois de 5 senhas erradas (configurável de 3 a 20) para o mesmo e-mail **ou** endereço IP, o login fica bloqueado por 15 minutos. A mensagem é a mesma para e-mail inexistente e senha errada.
- Sessão: novo identificador depois do login (contra fixação de sessão), cookie `HttpOnly`, `SameSite=Lax`, `Secure` quando há HTTPS, sessão só por cookie (nunca na URL). Encerramento por inatividade (padrão 120 minutos, configurável). Ao desativar uma conta ou trocar a senha, as sessões abertas dela deixam de valer.
- Saída (**Sair**) por formulário com proteção CSRF, que apaga a sessão e o cookie.
- Em produção, as contas de demonstração ficam desligadas e os erros técnicos não aparecem na tela (página genérica e registro no log do servidor).
- Cabeçalhos de segurança: proibição de abrir o painel dentro de outro site (`X-Frame-Options`), `nosniff`, `Referrer-Policy`, páginas do painel sem cache, `Strict-Transport-Security` com HTTPS em produção.

### Perfis e permissões
Cada página e cada ação conferem a permissão **no servidor**, inclusive quando a pessoa digita o endereço direto ou força um formulário. Resumo:

| Perfil | Pode | Não pode |
| --- | --- | --- |
| Administrador | tudo, inclusive contas, manutenção e cópia de segurança | — |
| Bibliotecária | acervo, CDD (aprovar), exemplares, inventário, etiquetas, empréstimos, importar alunos, relatórios com dados pessoais, site público, configurações gerais | contas de acesso, segurança, manutenção e cópia de segurança |
| Auxiliar de biblioteca | consultar e registrar empréstimos, conferir inventário, gerar etiquetas, cadastrar exemplares, sugerir CDD, relatórios | aprovar CDD, dar baixa, importar alunos, exportar dados pessoais, configurações |
| Somente consulta | ver painel, CDD, importações e relatórios sem dados pessoais | qualquer alteração; empréstimos e dados de alunos |

A matriz completa é testada por `php admin/tests/auditoria-seguranca-teste.php`.

### Proteção contra ataques comuns
- **SQL injection**: todas as consultas usam parâmetros preparados (PDO). Ordenações e filtros aceitam só valores de listas fixas.
- **XSS**: todo texto exibido passa por escape (`e()`); o site público e o painel não carregam scripts nem fontes de outros sites.
- **CSRF**: todo formulário que altera dados exige um código de sessão; o código é renovado no login.
- **Redirecionamento aberto**: o retorno depois do login só aceita endereços internos do painel.
- **Uploads**: capas aceitas só como JPG/PNG/WebP até 2 MB, conferidas pelo conteúdo, regravadas (remove metadados) e salvas com nome aleatório; a pasta não executa PHP. Planilhas de alunos são lidas na memória e descartadas (não ficam no servidor).
- **Arquivos privados**: `.env`, `.git`, `backups/`, `storage/`, `database/`, `config/`, `src/`, `docs/`, `vendor/`, `admin/includes/`, `admin/tests/` e arquivos `.sql`, `.csv`, `.xlsx`, `.log` etc. são bloqueados pelo `.htaccess` (Apache). Para Nginx/IIS veja [`publicacao.md`](publicacao.md).

### Minimização e privacidade
- O **site público** nunca mostra dados de alunos nem quem está com um livro: só "disponível", "emprestado" ou a quantidade de exemplares disponíveis.
- Relatórios com nomes de alunos exigem permissão própria; exportar dados pessoais (CSV/XLSX/PDF) exige outra (`relatorios.exportar_pessoais`), e cada exportação fica registrada.
- O registro de ações (`acoes_administrativas`) guarda quem fez o quê e quando, sem copiar o conteúdo das planilhas de alunos.
- Os históricos de empréstimo, de CDD e dos exemplares não podem ser apagados nem alterados pelo painel (gatilhos no banco).
- Nenhum dado é enviado a serviços externos. As consultas de CDD em fontes externas enviam apenas dados do **livro** (ISBN, título), nunca de alunos.
- O sistema funciona sem internet no computador da biblioteca (fontes e gráficos são locais).

### Cópias de segurança
- Só o Administrador gera, depois de confirmar que vai guardar o arquivo em local protegido. Cada geração é registrada.
- Não há restauração pelo navegador. Veja [`backup-recuperacao.md`](backup-recuperacao.md).

### Limitações técnicas conhecidas (riscos residuais)
- Os dados no banco **não são criptografados** em repouso; a proteção depende do acesso ao computador/servidor e do disco. Recomenda-se criptografia de disco (BitLocker) no computador da biblioteca.
- O arquivo de cópia de segurança não é criptografado pelo sistema (veja a seção 5 do guia de backup).
- O bloqueio de tentativas por IP pode afetar todos os computadores da escola se eles saírem pelo mesmo IP na internet.
- Não há autenticação em dois fatores.
- Não há exclusão automática de dados antigos: a retenção depende de decisão da escola (seção 2).
- Não foram feitos testes de invasão por terceiros nem testes em Windows/XAMPP real, Nginx ou IIS (veja o relatório da auditoria).

## 2. Decisões que cabem à escola

Cada item abaixo precisa de uma decisão registrada por escrito pela direção. O sistema não decide nenhum deles.

| Assunto | Pergunta a responder | Sugestão (a validar) |
| --- | --- | --- |
| **Finalidade e base legal** | Para que os dados dos alunos são tratados e com qual base legal? | Execução de atividade pedagógica/administrativa da escola (empréstimo de livros). Validar com a secretaria/rede de ensino. |
| **Dados mínimos** | Quais dados do aluno são realmente necessários? | Nome, matrícula e turma. Evitar telefone, endereço, CPF e dados de saúde. |
| **Crianças e adolescentes** | Como informar responsáveis? | Incluir a biblioteca no aviso de privacidade da escola (art. 14 da LGPD exige atenção ao melhor interesse da criança e do adolescente). |
| **Quem acessa** | Quais pessoas recebem conta, e com qual perfil? | Uma conta pessoal por pessoa, nunca compartilhada; menor perfil necessário; revisar a lista de contas todo semestre e desativar quem sair. |
| **Quem exporta** | Quem pode baixar relatórios com nomes de alunos? | Só a bibliotecária responsável e a direção; arquivos apagados depois do uso. |
| **Retenção** | Por quanto tempo guardar alunos que saíram da escola e o histórico de empréstimos? | Ex.: manter o histórico do ano corrente + 1 ano; depois, anonimizar ou excluir. Definir também para cópias de segurança e registro de ações. |
| **Descarte** | Como descartar planilhas, impressões e cópias antigas? | Apagar arquivos digitais (inclusive da lixeira e downloads), triturar impressões com nomes. |
| **Custódia das cópias** | Quem guarda as cópias de segurança e onde? | Duas pessoas responsáveis, mídias guardadas em local trancado, nunca em conta pessoal. |
| **Importação de alunos** | Em qual computador e por quem? | Somente no computador autorizado da biblioteca, pela bibliotecária, com a planilha oficial da secretaria; apagar a planilha depois. |
| **Direitos dos titulares** | Como atender um pedido de acesso ou correção? | Pedido pela secretaria; a bibliotecária consulta o histórico do aluno no painel e corrige pela importação. |
| **Incidentes** | O que fazer se houver vazamento ou acesso indevido? | Ver abaixo. |
| **Publicação na internet** | O painel ficará acessível fora da escola? | Preferir acesso só pela rede da escola ou VPN; se for público, HTTPS obrigatório e senhas fortes. |

### Roteiro mínimo para incidentes
1. Registrar o que aconteceu, quando e quem percebeu.
2. Conter: desativar contas suspeitas (Sistema → Administradores), trocar senhas, trocar a senha do banco no `.env` e no MySQL, desligar o site se necessário.
3. Preservar evidências: registro de ações (`acoes_administrativas`), tentativas de login (`login_tentativas`) e logs do servidor.
4. Avaliar com a direção e o encarregado de dados se é preciso comunicar a ANPD e os responsáveis (art. 48 da LGPD).
5. Corrigir a causa e registrar a lição aprendida.

## 3. Boas práticas para a equipe
- Nunca anotar senhas em papel junto ao computador nem compartilhar a conta.
- Clicar em **Sair** ao terminar, principalmente em computador compartilhado.
- Bloquear o Windows (tecla Windows + L) ao se afastar.
- Não enviar planilhas ou relatórios de alunos por WhatsApp ou e-mail pessoal.
- Manter o Windows, o XAMPP e o navegador atualizados.
