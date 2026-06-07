<?php

namespace App\Commands;

use Exception;
use LaravelZero\Framework\Commands\Command;
use Illuminate\Support\Facades\File;
use Shuchkin\SimpleXLSX;

class Revision extends Command
{
    /**
     * El argumento {carpeta?} recibe el nombre de la carpeta mensual (ej: ABRIL-2026 o solo "abril")
     *
     * @var string
     */
    protected $signature = 'app:Revision {cliente} {carpeta?} {--excel=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Busca Folios Fiscales nuevos en XML filtrando por carpeta mensual comparándolos contra el Excel';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $cliente = $this->argument('cliente');
        $carpetaInput = $this->argument('carpeta'); 
        $excelPath = $this->option('excel');

        $xmlPath = storage_path("app/private/clientes/{$cliente}/xml");

        if (!empty($carpetaInput)) {
            $carpetaInput = strtoupper(trim($carpetaInput));

            if (!str_contains($carpetaInput, '-')) {
                $añoActual = date('Y'); 
                $carpetaInput = "{$carpetaInput}-{$añoActual}";
            }

            $xmlPath = rtrim($xmlPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $carpetaInput;
        }

        if (!$excelPath) {
            $excelPath = $this->ask("Por favor, ingresa o arrastra la ruta completa de la bitácora Excel");
        }

        $excelPath = trim($excelPath, " '\"");

        if (empty($excelPath) || !file_exists($excelPath)) {
            $this->error("No se encontró ningún archivo Excel en la ruta especificada:\n{$excelPath}");
            return self::FAILURE;
        }

        if (!is_dir($xmlPath)) {
            $this->newLine();
            $this->error("❌ El directorio de XML especificado NO existe.");
            $this->warn("Ruta buscada: {$xmlPath}");
            if (!empty($carpetaInput)) {
                $this->line("Asegúrate de que la carpeta se llame exactamente: {$carpetaInput}");
            }
            return self::FAILURE;
        }

        $this->info("Analizando el archivo Excel completo (buscando en todas las hojas)...");

        $uuidsExcel = [];

        try {
            if ($xlsx = SimpleXLSX::parse($excelPath)) {
                foreach ($xlsx->sheetNames() as $sheetIndex => $sheetName) {
                    foreach ($xlsx->rows($sheetIndex) as $row) {
                        if (!is_array($row)) {
                            continue;
                        }

                        foreach ($row as $cellValue) {
                            $uuidCandidate = strtoupper(trim((string)$cellValue));

                            if (strlen($uuidCandidate) === 36 && substr_count($uuidCandidate, '-') === 4) {
                                $uuidsExcel[$uuidCandidate] = true;
                            }
                        }
                    }
                }
            } else {
                $this->error("Error al procesar el formato XLSX: " . SimpleXLSX::parseError());
                return self::FAILURE;
            }

        } catch (Exception $e) {
            $this->error("Error al leer el archivo Excel: " . $e->getMessage());
            return self::FAILURE;
        }
        
        $this->info('Total de Folios Fiscales únicos encontrados en el Excel: ' . count($uuidsExcel));


        if (!empty($carpetaInput)) {
            $this->info("Buscando archivos XML exclusivamente en la carpeta: {$carpetaInput}...");
            $xmlFiles = File::files($xmlPath); 
        } else {
            $this->info('Buscando archivos XML en todas las subcarpetas de manera global...');
            $xmlFiles = File::allFiles($xmlPath);
        }
        

        $xmlFiles = array_filter($xmlFiles, function ($file) {
            return strtolower($file->getExtension()) === 'xml';
        });

        if (empty($xmlFiles)) {
            $this->error("\n❌ No se encontraron archivos XML en la ruta elegida: {$xmlPath}");
            return self::FAILURE;
        }

        $faltantes = [];
        $this->info('Cruzando información con XMLs locales (' . count($xmlFiles) . ' archivos encontrados)...');

        foreach ($xmlFiles as $fileInfo) {
            $xmlFile = $fileInfo->getRealPath();
            $xml = simplexml_load_file($xmlFile);
            
            if (!$xml) {
                continue;
            }

            $xml->registerXPathNamespace('cfdi', 'http://www.sat.gob.mx/cfd/4');
            $xml->registerXPathNamespace('tfd', 'http://www.sat.gob.mx/TimbreFiscalDigital');

            $timbre = $xml->xpath('//cfdi:Complemento/tfd:TimbreFiscalDigital');
            
            if (!empty($timbre)) {
                $uuid = strtoupper(trim((string)$timbre[0]['UUID']));

                if (!empty($uuid) && !isset($uuidsExcel[$uuid])) {
                    $faltantes[] = [
                        'uuid'          => $uuid,
                        'archivo'       => $fileInfo->getFilename(),
                        'ruta_completa' => $xmlFile,
                    ];
                }
            }
        }

        $this->newLine();

        if (empty($faltantes)) {
            $this->info('¡Todo al día! Todos los XML analizados ya están registrados en tu Excel.');
            return self::SUCCESS;
        }

        $this->warn('⚠️ Se encontraron folios en los XML que NO existen en ninguna hoja del Excel:');
        
        $tablaConsola = array_map(function ($item) {
            return [
                'Folio Fiscal Faltante' => $item['uuid'],
                'Archivo XML' => $item['archivo']
            ];
        }, $faltantes);

        $this->table(['Folio Fiscal Faltante', 'Archivo XML'], $tablaConsola);

        $this->newLine();
        $this->info('Total faltantes: ' . count($faltantes));

        // ─────────────────────────────────────────────
        // Lógica de Copiado con Validación Estricta
        // ─────────────────────────────────────────────
        if ($this->confirm('¿Deseas copiar estos archivos XML faltantes a otra carpeta?', true)) {
            
            $destinoPath = $this->ask("Por favor, ingresa o arrastra la ruta de la carpeta destino");
            $destinoPath = trim($destinoPath, " '\"");

            if (empty($destinoPath)) {
                $this->error("La ruta de destino no puede estar vacía.");
                return self::FAILURE;
            }

            if (!is_dir($destinoPath)) {
                $this->newLine();
                $this->error("❌ Error: La carpeta especificada NO existe.");
                $this->warn("Ruta ingresada: {$destinoPath}");
                $this->line("El proceso de copiado se ha cancelado para evitar errores de escritura.");
                return self::FAILURE;
            }

            $this->newLine();
            $this->info("Copiando archivos...");
            
            $bar = $this->output->createProgressBar(count($faltantes));
            $bar->start();

            $copiadosExitosamente = 0;

            foreach ($faltantes as $faltante) {
                $origen = $faltante['ruta_completa'];
                $destino = rtrim($destinoPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $faltante['archivo'];

                if (copy($origen, $destino)) {
                    $copiadosExitosamente++;
                }
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);

            $this->info("✅ Proceso de copiado finalizado. Se copiaron {$copiadosExitosamente} de " . count($faltantes) . " archivos.");
        }

        return self::SUCCESS;
    }
}