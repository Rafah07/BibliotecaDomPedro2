# Central de Ajuda — como atualizar os tutoriais

A Central de Ajuda (`admin/ajuda.php`, menu **Configurações → Tutorial do Sistema**) lê o
conteúdo destes arquivos versionados. Não há editor no painel nem tabela no banco: para mudar
um tutorial, edite o arquivo, rode o teste e faça commit.

```
admin/includes/ajuda/
├── modulos.php            16 módulos (ordem, nome, ícone, descrição, arquivo, palavras-chave)
├── tutoriais/
│   ├── 01-primeiros-passos.php
│   ├── ...
│   └── 16-solucao-problemas.php   perguntas frequentes
└── LEIA-ME.md             este guia
admin/assets/ajuda/        capturas de tela (só dados fictícios)
admin/includes/ajuda.php   funções: carregar, pesquisar, formatar, "voltar", ajuda contextual
admin/tests/ajuda-teste.php
```

## Formato de um tutorial

Cada arquivo de `tutoriais/` devolve uma lista de tutoriais:

```php
[
    'id' => 'registrar-emprestimo',          // único; letras minúsculas, números e hífen
    'titulo' => 'Como registrar um empréstimo',   // A — Título
    'resumo' => 'Uma frase, usada nos cartões e na pesquisa.',
    'palavras' => ['emprestar', 'retirada'],  // termos extras para a pesquisa
    'paginas' => ['novo-emprestimo.php'],     // páginas onde o botão "Como usar esta página?" abre este tutorial
    'permissao' => 'emprestimos.registrar',   // opcional: mostra o quadro de permissão e o selo "Seu perfil não tem esta função"
    'objetivo' => '...',                      // B
    'quando' => ['...'],                      // C
    'requisitos' => ['...'],                  // D
    'passos' => [                             // E — texto simples ou:
        ['titulo' => '...', 'texto' => '...', 'lista' => ['...'], 'dica' => '...', 'atencao' => '...',
         'imagem' => ['arquivo' => 'emprestimo-passo1.png', 'alt' => 'descrição da imagem', 'legenda' => '...']],
    ],
    'exemplo' => 'Sempre com dados fictícios.',   // F
    'resultado' => ['...'],                   // G
    'problemas' => [['problema', 'causa', 'solução']],   // H
    'cuidados' => ['...'],                    // I
    'relacionados' => ['registrar-devolucao'],    // J — próximos passos (ids de outros tutoriais)
    'confere' => ['novo-emprestimo.php' => ['Confirmar empréstimo']],  // nomes citados que precisam existir no código
]
```

Marcação permitida nos textos (todo o resto é escapado):

- `**negrito**` para nomes de botões, campos e menus;
- `` `valor` `` para o que se digita;
- `[[tutorial:id|texto]]` para outro tutorial;
- `[[pagina:id-do-menu|texto]]` para uma página do painel (só vira link para quem tem permissão).

## Regras do conteúdo

1. Descreva somente o que existe: use os nomes exatos de menus, campos, botões e mensagens.
   Registre esses nomes em `confere` (caminho relativo a `admin/`); o teste falha se algum sumir.
2. Exemplos e capturas só com dados fictícios. Nada de credenciais ou detalhes técnicos sensíveis.
3. Nunca oriente apagar registros, alterar o banco diretamente ou desativar a segurança.
4. Uma página do painel só pode aparecer em `paginas` de um tutorial.
5. Capturas: PNG otimizado (menos de 400 KB), com `alt` e `legenda`, em `admin/assets/ajuda/`.
   Se a imagem não existir, o tutorial continua funcionando sem ela (o teste acusa a falta).

## Depois de editar

```
php -l admin/includes/ajuda/tutoriais/<arquivo>.php
php admin/tests/ajuda-teste.php
```

Se mudou o nome de um botão no sistema, atualize o tutorial e o `confere` no mesmo commit.
