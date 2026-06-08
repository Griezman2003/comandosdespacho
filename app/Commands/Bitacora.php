<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use Illuminate\Support\Facades\Storage;

class Bitacora extends Command
{
    protected $signature = 'app:bitacora {cliente} {periodo}';
    protected $description = 'Genera una bitácora de Excel con pestañas de Facturas (desde XML) y Fichas';

    public function handle()
    {
        $cliente = strtoupper($this->argument('cliente'));
        $periodoInput = $this->argument('periodo');

        if (!str_contains($periodoInput, '-')) {
            $periodoInput .= '-' . date('Y');
        }
        
        $periodo = strtoupper($periodoInput);

        $relativeXmlPath = "private/CLIENTES/{$cliente}/XML/{$periodo}";
        $fullPath = storage_path("app/{$relativeXmlPath}");

        if (!file_exists($fullPath)) {
            $this->error("La ruta no existe: {$fullPath}");
            return 1;
        }

        $this->info("Leyendo archivos XML desde: {$relativeXmlPath}...");

        $spreadsheet = new Spreadsheet();
        
        $styleHeader = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri', 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '709255']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ];

        $styleTitle = ['font' => ['bold' => true, 'color' => ['rgb' => '548235'], 'size' => 16, 'name' => 'Calibri']];
        $styleSubtitle = ['font' => ['bold' => true, 'color' => ['rgb' => '595959'], 'size' => 11, 'name' => 'Calibri']];
        $styleBorder = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D9D9D9']]]];
        $styleTotalBorder = ['borders' => ['top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']], 'bottom' => ['borderStyle' => Border::BORDER_DOUBLE, 'color' => ['rgb' => '000000']]]];

        $sheetFacturas = $spreadsheet->getActiveSheet();
        $sheetFacturas->setTitle('Facturas');
        $sheetFacturas->setShowGridlines(true);


        $sheetFacturas->setCellValue('A1', "Facturas De " . ucwords(strtolower($cliente)));
        $sheetFacturas->getStyle('A1')->applyFromArray($styleTitle);
        $sheetFacturas->setCellValue('A2', "CORRESPONDIENTE DEL PERIODO {$periodo}");
        $sheetFacturas->getStyle('A2')->applyFromArray($styleSubtitle);

        $headersFacturas = ['ID', 'FOLIO FISCAL', 'FECHA DE CERTIFICACION', 'EFECTO DE COMPROBANTE', 'TOTAL'];
        $sheetFacturas->fromArray($headersFacturas, null, 'A4');
        $sheetFacturas->getStyle('A4:E4')->applyFromArray($styleHeader);
        $sheetFacturas->getStyle('B4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $files = glob("{$fullPath}/*.xml");
        $rowCount = 5;
        $idCounter = 1;

        foreach ($files as $file) {
            try {
                $xmlContent = file_get_contents($file);
                $xml = new \SimpleXMLElement($xmlContent);
                
                $namespaces = $xml->getNamespaces(true);

                $efecto = (string)($xml['TipoDeComprobante'] ?? 'INGRESO');
                $total = (float)($xml['Total'] ?? 0.00);
                
                if ($efecto === 'I') $efecto = 'INGRESO';
                if ($efecto === 'E') $efecto = 'EGRESO';
                if ($efecto === 'P') $efecto = 'PAGO';

                $uuid = '';
                $fechaCert = '';
                
                $timbreNodes = $xml->xpath('//*[local-name()="TimbreFiscalDigital"]');

                if ($timbreNodes && isset($timbreNodes[0])) {
                    $tfd = $timbreNodes[0];
                    $uuid = strtoupper((string)$tfd['UUID']);
                    $fechaCert = (string)$tfd['FechaTimbrado'];
                    
                    if ($fechaCert) {
                        $fechaCert = date('d/m/Y', strtotime($fechaCert));
                    }
                }

                $sheetFacturas->setCellValue("A{$rowCount}", $idCounter);
                $sheetFacturas->setCellValue("B{$rowCount}", $uuid);
                $sheetFacturas->setCellValue("C{$rowCount}", $fechaCert);
                $sheetFacturas->setCellValue("D{$rowCount}", $efecto);
                $sheetFacturas->setCellValue("E{$rowCount}", $total);

                $sheetFacturas->getStyle("A{$rowCount}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheetFacturas->getStyle("C{$rowCount}:D{$rowCount}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheetFacturas->getStyle("E{$rowCount}")->getNumberFormat()->setFormatCode('$#,##0.00');
                $sheetFacturas->getStyle("A{$rowCount}:E{$rowCount}")->applyFromArray($styleBorder);

                if ($rowCount % 2 == 0) {
                    $sheetFacturas->getStyle("A{$rowCount}:E{$rowCount}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F7F9F5');
                }

                $rowCount++;
                $idCounter++;
            } catch (\Exception $e) {
                $this->warn("No se pudo procesar el archivo XML: " . basename($file) . " - Error: " . $e->getMessage());
            }
        }

        $sheetFacturas->setCellValue("D{$rowCount}", 'Total');
        $sheetFacturas->getStyle("D{$rowCount}")->getFont()->setBold(true);
        $sheetFacturas->setCellValue("E{$rowCount}", "=SUM(E5:E" . ($rowCount - 1) . ")");
        $sheetFacturas->getStyle("E{$rowCount}")->getFont()->setBold(true);
        $sheetFacturas->getStyle("E{$rowCount}")->getNumberFormat()->setFormatCode('$#,##0.00');
        $sheetFacturas->getStyle("E{$rowCount}")->applyFromArray($styleTotalBorder);

        $sheetFichas = $spreadsheet->createSheet();
        $sheetFichas->setTitle('Fichas');
        $sheetFichas->setShowGridlines(true);

        $sheetFichas->setCellValue('A1', "Fichas de Transferencia - " . ucwords(strtolower($cliente)));
        $sheetFichas->getStyle('A1')->applyFromArray($styleTitle);
        $sheetFichas->setCellValue('A2', "CORRESPONDIENTE DEL PERIODO {$periodo}");
        $sheetFichas->getStyle('A2')->applyFromArray($styleSubtitle);

        $headersFichas = ['ID', 'PROVEEDORES', 'FECHA', 'MONTO', 'FOLIO FISCAL'];
        $sheetFichas->fromArray($headersFichas, null, 'A4');
        $sheetFichas->getStyle('A4:E4')->applyFromArray($styleHeader);
        $sheetFichas->getStyle('B4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheetFichas->getStyle('E4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);


        for ($i = 5; $i <= 10; $i++) {
            $sheetFichas->setCellValue("A{$i}", $i - 4);
            $sheetFichas->getStyle("A{$i}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheetFichas->getStyle("C{$i}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheetFichas->getStyle("D{$i}")->getNumberFormat()->setFormatCode('$#,##0.00');
            $sheetFichas->getStyle("A{$i}:E{$i}")->applyFromArray($styleBorder);
            if ($i % 2 == 0) {
                $sheetFichas->getStyle("A{$i}:E{$i}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F7F9F5');
            }
        }

        $sheetFichas->setCellValue("C11", 'Total');
        $sheetFichas->getStyle("C11")->getFont()->setBold(true);
        $sheetFichas->setCellValue("D11", "=SUM(D5:D10)");
        $sheetFichas->getStyle("D11")->getFont()->setBold(true);
        $sheetFichas->getStyle("D11")->getNumberFormat()->setFormatCode('$#,##0.00');
        $sheetFichas->getStyle("D11")->applyFromArray($styleTotalBorder);


        foreach ([$sheetFacturas, $sheetFichas] as $sheet) {
            foreach ($sheet->getColumnIterator() as $column) {
                $colLetter = $column->getColumnIndex();
                $sheet->getColumnDimension($colLetter)->setAutoSize(true);
            }
            $sheet->calculateColumnWidths();
        }

        $outputFileName = "Bitacora_{$cliente}_{$periodo}.xlsx";
        $outputDir = storage_path("app/private/CLIENTES/{$cliente}");
        $outputPath = "{$outputDir}/{$outputFileName}";

        $writer = new Xlsx($spreadsheet);
        $writer->save($outputPath);

        $this->info("¡Bitácora generada con éxito de manera ordenada!");
        $this->line("Guardada en: <comment>{$outputPath}</comment>");
        return 0;
    }
}