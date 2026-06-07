<?php

namespace App\Commands;

use Exception;
use ZipArchive;
use LaravelZero\Framework\Commands\Command;

class Contalink extends Command
{
    /**
     *
     * @var string
     */
    protected $signature = 'app:Contalink {cliente} {--zip=}';

    /**
     *
     * @var string
     */
    protected $description = 'Descomprime un ZIP de Contalink y distribuye los XML y PDF en carpetas mensuales usando el UUID';

    /**
     */
    protected array $mesesEspanol = [
        1 => 'ENERO', 2 => 'FEBRERO', 3 => 'MARZO', 4 => 'ABRIL',
        5 => 'MAYO', 6 => 'JUNIO', 7 => 'JULIO', 8 => 'AGOSTO',
        9 => 'SEPTIEMBRE', 10 => 'OCTUBRE', 11 => 'NOVIEMBRE', 12 => 'DICIEMBRE'
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $cliente = $this->argument('cliente');
        $zipPath = $this->option('zip');

        $baseXmlPath = storage_path("app/private/clientes/{$cliente}/xml");
        $basePdfPath = storage_path("app/private/clientes/{$cliente}/pdf");

        if (!$zipPath) {
            $zipPath = $this->ask("Por favor, ingresa o arrastra la ruta completa del archivo ZIP de Contalink");
        }

        $zipPath = trim($zipPath, " '\"");

        if (empty($zipPath) || !file_exists($zipPath)) {
            $this->error("❌ No se encontró el archivo ZIP en la ruta específica:\n{$zipPath}");
            return self::FAILURE;
        }

        if (strtolower(pathinfo($zipPath, PATHINFO_EXTENSION)) !== 'zip') {
            $this->error("❌ El archivo proporcionado no es un archivo .zip válido.");
            return self::FAILURE;
        }

        $this->info("Iniciando procesamiento y clasificación por UUID para: <fg=cyan>{$cliente}</>");

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            $this->error("❌ No se pudo abrir o leer el archivo ZIP.");
            return self::FAILURE;
        }

        $totalArchivos = $zip->numFiles;
        $xmlContador = 0;
        $pdfContador = 0;
        $omitidosContador = 0;


        $mapaUuidMes = [];
        $archivosPdfAsignar = [];

        $this->info("Analizando y extrayendo contenido del ZIP ({$totalArchivos} elementos)...");
        $bar = $this->output->createProgressBar($totalArchivos);
        $bar->start();

        for ($i = 0; $i < $totalArchivos; $i++) {
            $filename = $zip->getNameIndex($i);
            
            if (str_ends_with($filename, '/') || empty($filename)) {
                $bar->advance();
                continue;
            }

            $pureFilename = basename($filename);
            $extension = strtolower(pathinfo($pureFilename, PATHINFO_EXTENSION));
            $fileContent = $zip->getFromIndex($i);

            if ($extension === 'xml') {

                $infoXml = $this->analizarXmlCfdi($fileContent);
                $carpetaMes = $infoXml['mes'];
                $uuidXml = $infoXml['uuid'];

                $claveMapeo = !empty($uuidXml) ? $uuidXml : strtoupper(pathinfo($pureFilename, PATHINFO_FILENAME));

                if (!$carpetaMes) {
                    $stat = $zip->statIndex($i);
                    $carpetaMes = $this->obtenerMesDesdeTimestamp($stat['mtime'] ?? time());
                }

                $mapaUuidMes[$claveMapeo] = $carpetaMes;

                $destinoDirectorio = $baseXmlPath . DIRECTORY_SEPARATOR . $carpetaMes;
                if (!is_dir($destinoDirectorio)) {
                    mkdir($destinoDirectorio, 0777, true);
                }

                file_put_contents($destinoDirectorio . DIRECTORY_SEPARATOR . $pureFilename, $fileContent);
                $xmlContador++;
                $bar->advance();
            } elseif ($extension === 'pdf') {
                $stat = $zip->statIndex($i);
                $archivosPdfAsignar[] = [
                    'pure_filename' => $pureFilename,
                    'uuid_key' => strtoupper(pathinfo($pureFilename, PATHINFO_FILENAME)),
                    'content' => $fileContent,
                    'mtime' => $stat['mtime'] ?? time()
                ];
                $bar->advance();
            } else {
                $omitidosContador++;
                $bar->advance();
            }
        }

        foreach ($archivosPdfAsignar as $pdf) {
            $uuidBuscado = $pdf['uuid_key'];

            if (isset($mapaUuidMes[$uuidBuscado])) {
                $carpetaMes = $mapaUuidMes[$uuidBuscado];
            } else {

                $carpetaMes = $this->obtenerMesDesdeTimestamp($pdf['mtime']);
            }

            $destinoDirectorio = $basePdfPath . DIRECTORY_SEPARATOR . $carpetaMes;
            if (!is_dir($destinoDirectorio)) {
                mkdir($destinoDirectorio, 0777, true);
            }

            file_put_contents($destinoDirectorio . DIRECTORY_SEPARATOR . $pdf['pure_filename'], $pdf['content']);
            $pdfContador++;
        }

        $zip->close();
        $bar->finish();
        $this->newLine(2);

        $this->info("✅ ¡Distribución por UUID finalizada con éxito!");
        $this->line(" ➔ XMLs organizados: <fg=green>{$xmlContador}</> carpetas en: <fg=cyan>xml/...</>");
        $this->line(" ➔ PDFs organizados: <fg=green>{$pdfContador}</> carpetas en: <fg=cyan>pdf/...</>");
        
        if ($omitidosContador > 0) {
            $this->warn(" ➔ Archivos omitidos del ZIP: {$omitidosContador}");
        }

        return self::SUCCESS;
    }

    /**
     * Parsea el XML para recuperar tanto la Fecha como el UUID del Timbre Fiscal
     */
    private function analizarXmlCfdi(string $xmlContent): array
    {
        $resultado = ['mes' => null, 'uuid' => null];

        try {
            $xml = simplexml_load_string($xmlContent);
            if (!$xml) {
                return $resultado;
            }

            $fechaString = (string)($xml['Fecha'] ?? $xml['fecha'] ?? '');
            if (!empty($fechaString)) {
                $timestamp = strtotime($fechaString);
                if ($timestamp) {
                    $resultado['mes'] = $this->obtenerMesDesdeTimestamp($timestamp);
                }
            }

            $xml->registerXPathNamespace('cfdi', 'http://www.sat.gob.mx/cfd/4');
            $xml->registerXPathNamespace('tfd', 'http://www.sat.gob.mx/TimbreFiscalDigital');
            $timbre = $xml->xpath('//cfdi:Complemento/tfd:TimbreFiscalDigital');

            if (!empty($timbre)) {
                $resultado['uuid'] = strtoupper(trim((string)$timbre[0]['UUID']));
            }

        } catch (Exception $e) {
            
        }

        return $resultado;
    }

    /**
     * Convierte un timestamp unix a la nomenclatura requerida (ej: "MAYO-2026")
     */
    private function obtenerMesDesdeTimestamp(int $timestamp): string
    {
        $numeroMes = (int)date('n', $timestamp);
        $año = date('Y', $timestamp);
        $nombreMes = $this->mesesEspanol[$numeroMes] ?? 'DESCONOCIDO';

        return "{$nombreMes}-{$año}";
    }
}