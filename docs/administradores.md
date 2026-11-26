# Administradores: um único perfil de acesso (migração 200)

Desde a migração `200_somente_administradores.sql`, o painel tem **um único perfil: Administrador**.
Os perfis Bibliotecária, Auxiliar de biblioteca e Somente consulta deixaram de existir: toda conta ativa
tem acesso total ao painel.

Aplicar no XAMPP (faz a cópia de segurança antes, sozinho):

```
C:\xampp\php\php.exe database/migrar.php
```

## O que mudou

- **Permissões** (`admin/includes/auth.php`): `AUTH_PERMISSOES` tem só a entrada `administrador`, com todas as
  permissões. `auth_usuario()` trata qualquer conta ativa como administradora, mesmo uma conta antiga que ainda
  tenha outro perfil gravado. O servidor continua conferindo cada página e ação (`auth_exigir` / `auth_pode`):
  sem login, nada é mostrado nem gravado.
- **Migração 200**: muda o perfil de todas as contas existentes para `administrador` e passa o padrão da coluna
  para `administrador`. Os outros valores do ENUM continuam aceitos pelo banco (nada é apagado), mas o painel não
  grava mais nenhum deles. Contas, senhas e o histórico de ações são preservados.
- **Função** (`administradores.cargo`): texto livre e opcional (ex.: “Bibliotecária”, “Coordenação”) que só
  identifica a pessoa na lista e no canto superior direito. Sem função, aparece “Administrador”.
- **Página “Perfis e permissões”** (`admin/permissoes.php`): removida, junto com o item do menu e o atalho em
  Configurações (que agora leva a Administradores).
- **Regra de segurança mantida**: ninguém desativa a própria conta, e sempre sobra pelo menos uma conta ativa.

## Cadastro rápido de administradores

O quadro **Cadastrar administrador** fica no topo de **Sistema → Administradores** e também em **Meu perfil**
(menu do seu nome). Tem só quatro campos:

| Campo | Observação |
| --- | --- |
| Nome completo | obrigatório |
| E-mail | obrigatório, usado para entrar; não pode se repetir |
| Função | opcional |
| Senha inicial | obrigatória (≥ 10 caracteres, letras e números). Fica visível para ser anotada e entregue; o botão **Gerar senha** cria uma senha aleatória de 12 caracteres sem letras parecidas (0/O, 1/l/I) |

A senha é conferida de novo no servidor (`auth_senha_problema`). O cadastro grava `usuario.cadastrar` em
`acoes_administrativas` com `origem = cadastro_rapido` (nunca a senha). A edição completa (dados, situação e
redefinição de senha) continua em **Administradores → Editar**.

Arquivos: `admin/includes/administradores.php` (`administradores_cadastrar_rapido`, `administradores_form_rapido`),
`admin/administradores.php`, `admin/perfil.php`, `admin/assets/js/administradores.js`,
`admin/assets/css/administradores.css`.

## Linha de comando

```
php database/criar-admin.php "Nome Completo" email@escola.com.br ["Função"]
```

A função é opcional. Os nomes de perfil antigos (`bibliotecaria`, `auxiliar`, `consulta`) ainda são aceitos no
terceiro argumento e viram a função correspondente; a conta é sempre de administrador.

## Cuidado

Como toda conta tem acesso total (inclusive dados pessoais de alunos, cópias de segurança e cadastro de outros
administradores), cadastre só pessoas da equipe e desative a conta assim que a pessoa deixar a biblioteca.
