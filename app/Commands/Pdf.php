<?php

namespace App\Commands;

use Throwable;
use LaravelZero\Framework\Commands\Command;
use Illuminate\Support\Facades\File;
use Exception;

use CfdiUtils\Nodes\XmlNodeUtils;
use PhpCfdi\CfdiCleaner\Cleaner;
use PhpCfdi\CfdiToPdf\CfdiDataBuilder;
use PhpCfdi\CfdiToPdf\Converter;
use PhpCfdi\CfdiToPdf\Builders\Html2PdfBuilder;

class Pdf extends Command
{
    /**
     * El argumento {carpeta?} recibe la estructura mensual (ej: ABRIL-2026)
     *
     * @var string
     */
    protected $signature = 'app:pdf {cliente} {carpeta?}';

    /**
     * El comando lee XMLs limpios y genera PDFs nativos usando PhpCfdi.
     *
     * @var string
     */
    protected $description = 'Genera PDFs desde XML CFDI existentes utilizando PhpCfdi nativo en PHP';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $cliente = $this->argument('cliente');
            $carpetaInput = $this->argument('carpeta');

            $xmlPath = storage_path("app/private/clientes/{$cliente}/xml");
            $pdfPath = storage_path("app/private/clientes/{$cliente}/pdf");


            if (!empty($carpetaInput)) {
                $carpetaInput = strtoupper(trim($carpetaInput));


                if (!str_contains($carpetaInput, '-')) {
                    $añoActual = date('Y');
                    $carpetaInput = "{$carpetaInput}-{$añoActual}";
                }

                $xmlPath = rtrim($xmlPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $carpetaInput;
                $pdfPath = rtrim($pdfPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $carpetaInput;
            }

            if (!is_dir($xmlPath)) {
                $this->newLine();
                $this->error("❌ El directorio de XML especificado NO existe.");
                $this->warn("Ruta buscada: {$xmlPath}");
                return self::FAILURE;
            }


            if (!is_dir($pdfPath)) {
                if (!mkdir($pdfPath, 0777, true) && !is_dir($pdfPath)) {
                    $this->newLine();
                    $this->error("❌ No se pudo crear la carpeta destino para los PDFs.");
                    $this->warn("Ruta: {$pdfPath}");
                    return self::FAILURE;
                }
            }


            $xmlFiles = File::files($xmlPath);

            $xmlFiles = array_filter($xmlFiles, function ($file) {
                return strtolower($file->getExtension()) === 'xml';
            });

            if (empty($xmlFiles)) {
                $this->warn('⚠️ No se encontraron archivos XML en la ruta elegida.');
                return self::SUCCESS;
            }

            $this->newLine();
            $this->line("Cliente : <fg=cyan>{$cliente}</>");
            if (!empty($carpetaInput)) {
                $this->line("Carpeta : <fg=cyan>{$carpetaInput}</>");
            }
            $this->line("XML encontrados : <fg=cyan>" . count($xmlFiles) . "</>");
            $this->newLine();

            $bar = $this->output->createProgressBar(count($xmlFiles));
            $bar->setFormat(
                " %current%/%max% [<fg=cyan>%bar%</>] %percent:3s%%  <fg=yellow>%message%</>"
            );
            $bar->setMessage('Iniciando...');
            $bar->start();

            $exitosos = 0;
            $errores  = 0;
            $logErrores = [];

            $builder = new Html2PdfBuilder();
            $converter = new Converter($builder);

            foreach ($xmlFiles as $fileInfo) {
                $xmlFile = $fileInfo->getRealPath();
                $nombreArchivo = $fileInfo->getFilename();
                $bar->setMessage($nombreArchivo);

                try {
                    $xmlContenido = file_get_contents($xmlFile);
                    if (empty(trim($xmlContenido))) {
                        throw new Exception("El archivo XML está vacío.");
                    }

                    $xmlLimpio = Cleaner::staticClean($xmlContenido);
                    $comprobante = XmlNodeUtils::nodeFromXmlString($xmlLimpio);

                    $fechaEmisionReal = $comprobante['Fecha'] ?? '';

                    $timbre = $comprobante->searchNode('cfdi:Complemento', 'tfd:TimbreFiscalDigital');
                    if ($timbre && !empty($fechaEmisionReal)) {
                        // Forzamos temporalmente en memoria la FechaTimbrado para que coincida con tu Fecha de emisión
                        $timbre->addAttributes(['FechaTimbrado' => $fechaEmisionReal]);
                    }

                    $cfdiData = (new CfdiDataBuilder())->build($comprobante);

                    $nombrePdf = pathinfo($xmlFile, PATHINFO_FILENAME);
                    $pdfFile = rtrim($pdfPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $nombrePdf . '.pdf';

                    $converter->createPdfAs($cfdiData, $pdfFile);
                    $exitosos++;

                } catch (Throwable $e) {
                    $errores++;
                    $logErrores[] = "{$nombreArchivo}: " . $e->getMessage();
                }

                $bar->advance();
            }

            $bar->setMessage(
                $errores > 0
                    ? "<fg=yellow>{$errores} error(es), {$exitosos} OK</>"
                    : '<fg=green>completado</>'
            );
            $bar->finish();
            $this->newLine(2);

            $this->line("PDFs generados con éxito (PHP): <fg=green>{$exitosos}</>");

            if ($errores > 0) {
                $this->line("      Con errores      : <fg=yellow>{$errores}</>");
                $this->newLine();
                $this->warn('Detalle de los errores:');
                foreach ($logErrores as $log) {
                    $this->line("    • {$log}");
                }
            }

            $this->newLine();
            $this->info('Proceso terminado');
            return self::SUCCESS;

        } catch (Throwable $e) {
            $this->newLine();
            $this->error(' ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}