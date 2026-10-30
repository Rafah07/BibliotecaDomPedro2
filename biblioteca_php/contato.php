<?php
/** Endereço antigo: os contatos oficiais agora ficam na página Sobre a biblioteca (sem formulário). */
require_once __DIR__ . '/../config/app.php';
header('Location: ' . url('biblioteca_php/sobre.php') . '#contato', true, 301);
