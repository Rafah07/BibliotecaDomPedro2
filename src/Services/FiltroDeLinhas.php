<?php

namespace Biblioteca\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/** Limita a leitura do .xlsx a um intervalo de linhas e colunas (economiza memória). */
final class FiltroDeLinhas implements IReadFilter
{
    public function __construct(private int $inicio, private int $fim, private int $maxColunas)
    {
    }

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return $row >= $this->inicio && $row <= $this->fim
            && Coordinate::columnIndexFromString($columnAddress) <= $this->maxColunas;
    }
}
