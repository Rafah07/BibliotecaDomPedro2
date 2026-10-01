# Biblioteca Escolar - Base PHP

## Como executar localmente

Se você tiver PHP instalado, abra o terminal nesta pasta e rode:

```bash
php -S localhost:8000
```

Depois abra:

```text
http://localhost:8000
```

## Estrutura

- `index.php` - página inicial
- `catalogo.php` - base do catálogo e pesquisa
- `generos.php`, `autores.php`, `sobre.php`, `horarios.php`, `contato.php` - páginas iniciais
- `includes/sidebar.php` - menu lateral reutilizável
- `assets/css/style.css` - estilos
- `assets/js/app.js` - interações simples

## Próximo passo sugerido

Conectar os dados dos livros ao Supabase e substituir os arrays PHP temporários por consultas reais ao banco.
