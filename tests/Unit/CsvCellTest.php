<?php

namespace Tests\Unit;

use App\Services\CatalogCsv;
use PHPUnit\Framework\TestCase;

class CsvCellTest extends TestCase
{
    public function test_export_cells_escape_spreadsheet_formula_markers_and_preserve_normal_text(): void
    {
        foreach (['=HYPERLINK("evil")', '+1', '-1', '@SUM(A1)', "\tformula", "\rformula"] as $value) {
            $this->assertSame("'".$value, CatalogCsv::safe($value));
        }
        foreach (['ROMPER-PINK', '10.2500', 'Baby clothing', ''] as $value) {
            $this->assertSame($value, CatalogCsv::safe($value));
        }
    }
}
