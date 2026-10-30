<?php /** Paginação das tabelas do acervo. Os botões das páginas são montados por acervo.js. */ ?>
<div class="adm-pagination" data-pagination>
    <label class="adm-pagination__size">
        Mostrar
        <select class="adm-select adm-select--sm" data-page-size>
            <option value="5">5</option>
            <option value="10" selected>10</option>
            <option value="25">25</option>
            <option value="50">50</option>
        </select>
        por página
    </label>
    <p class="adm-pagination__info" data-page-info></p>
    <nav class="adm-pagination__pages" data-pages aria-label="Páginas da tabela"></nav>
</div>
