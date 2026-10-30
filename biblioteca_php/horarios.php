<?php
/** Endereço antigo: o horário agora fica na página Sobre a biblioteca. */
require_once __DIR__ . '/../config/app.php';
header('Location: ' . url('biblioteca_php/sobre.php') . '#horario', true, 301);
