<?php

namespace App\Commands;

use Exception;
use ZipArchive;
use SimpleXMLElement;
use LaravelZero\Framework\Commands\Command;
use Illuminate\Support\Facades\Storage;

use PhpCfdi\SatWsDescargaMasiva\Service;
use PhpCfdi\SatWsDescargaMasiva\WebClient\GuzzleWebClient;
use PhpCfdi\SatWsDescargaMasiva\Services\Query\QueryParameters;
use PhpCfdi\SatWsDescargaMasiva\Shared\DateTimePeriod;
use PhpCfdi\SatWsDescargaMasiva\Shared\DownloadType;
use PhpCfdi\SatWsDescargaMasiva\Shared\RequestType;
use PhpCfdi\SatWsDescargaMasiva\Shared\DocumentType;
use PhpCfdi\SatWsDescargaMasiva\Shared\DocumentStatus;
use PhpCfdi\SatWsDescargaMasiva\RequestBuilder\FielRequestBuilder\Fiel;
use PhpCfdi\SatWsDescargaMasiva\RequestBuilder\FielRequestBuilder\FielRequestBuilder;

class Xml extends Command
{
    /**
     * El nombre y firma del comando de la consola.
     *
     * @var string
     */
    protected $signature = 'app:Xml {cliente} {inicio} {fin} {--force : Ignora el historial de sincronización y descarga el rango exacto}';

    /**
     * La descripción del comando de la consola.
     *
     * @var string
     */
    protected $description = 'Descarga masiva de XMLs del SAT con soporte incremental y descargas históricas manuales';

    private array $meses = [
        '01' => 'ENERO', '02' => 'FEBRERO', '03' => 'MARZO', '04' => 'ABRIL',
        '05' => 'MAYO', '06' => 'JUNIO', '07' => 'JULIO', '08' => 'AGOSTO',
        '09' => 'SEPTIEMBRE', '10' => 'OCTUBRE', '11' => 'NOVIEMBRE', '12' => 'DICIEMBRE'
    ];

    /**
     * Ejecuta el comando de la consola.
     *
     * @return mixed
     */
    public function handle(): int
    {
        try {
            [$cliente, $inicio, $fin] = [
                $this->argument('cliente'),
                $this->argument('inicio'),
                $this->argument('fin'),
            ];

            $this->validarFechas($inicio, $fin);

            $relPath = "clientes/{$cliente}";

            $this->crearEstructura($relPath);

            $inicioAjustado = $this->obtenerFechaInicioSincronizacion($relPath, $inicio);

            if (strtotime($inicioAjustado) > strtotime($fin)) {
                $this->newLine();
                $this->info('➔ El cliente ya se encuentra actualizado hasta la fecha solicitada.');
                return self::SUCCESS;
            }

            $service = $this->construirServicio($relPath);

            $packages = $this->solicitarYEsperarPaquetes($service, $inicioAjustado, $fin, $relPath);

            $this->descargarYExtraerPaquetes($service, $packages, $relPath);

            if (!$this->option('force')) {
                $this->registrarUltimaSincronizacion($relPath, $fin);
            } else {
                $this->comment("Descarga forzada completada. No se alteró el historial de sincronización automática.");
            }

            $this->newLine();
            $this->info('Proceso terminado exitosamente');
            $this->line("   XMLs organizados en: <fg=cyan>storage/app/{$relPath}/xml/</>");

            return self::SUCCESS;

        } catch (Exception $e) {
            $this->newLine();
            $this->error('❌ ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    // ─────────────────────────────────────────────
    //  Validación de Fechas
    // ─────────────────────────────────────────────

    /**
     * Valida la estructura y coherencia cronológica de las fechas.
     * * @throws Exception
     */
    private function validarFechas(string $inicio, string $fin): void
    {
        $regEx = '/^\d{4}-\d{2}-\d{2}$/';
        
        if (!preg_match($regEx, $inicio) || !preg_match($regEx, $fin)) {
            throw new Exception("El formato de fecha es incorrecto. Debe ser YYYY-MM-DD (Ejemplo: 2026-01-31).");
        }

        $timeInicio = strtotime($inicio);
        $timeFin    = strtotime($fin);

        if (!$timeInicio || date('Y-m-d', $timeInicio) !== $inicio) {
            throw new Exception("La fecha de inicio '{$inicio}' no es una fecha válida en el calendario.");
        }
        if (!$timeFin || date('Y-m-d', $timeFin) !== $fin) {
            throw new Exception("La fecha de fin '{$fin}' no es una fecha válida en el calendario.");
        }

        if ($timeInicio > $timeFin) {
            throw new Exception("La fecha de inicio '{$inicio}' no puede ser mayor que la fecha de fin '{$fin}'.");
        }
    }

    // ─────────────────────────────────────────────
    //  Control de Sincronización Incremental
    // ─────────────────────────────────────────────

    private function obtenerFechaInicioSincronizacion(string $relPath, string $inicioOriginal): string
    {
        if ($this->option('force')) {
            $this->warn("Modo MANUAL forzado: Se ignorará el historial de sincronización.");
            $this->line("   Consultando el rango exacto: <fg=yellow>{$inicioOriginal}</>");
            return $inicioOriginal;
        }

        $syncFile = "{$relPath}/solicitudes/last_sync.json";

        if (Storage::disk('local')->exists($syncFile)) {
            $data = json_decode(Storage::disk('local')->get($syncFile), true);
            
            if (!empty($data['ultima_fecha_fin'])) {
                $siguienteDia = date('Y-m-d', strtotime($data['ultima_fecha_fin'] . ' +1 day'));
                
                $this->comment("Sincronización incremental detectada.");
                $this->line("   Omitiendo XMLs antiguos. Nueva fecha de inicio: <fg=yellow>{$siguienteDia}</>");
                return $siguienteDia;
            }
        }

        return $inicioOriginal;
    }

    private function registrarUltimaSincronizacion(string $relPath, string $fechaFin): void
    {
        $syncFile = "{$relPath}/solicitudes/last_sync.json";

        Storage::disk('local')->put(
            $syncFile,
            json_encode([
                'ultima_fecha_fin' => $fechaFin,
                'actualizado_en'   => now()->toDateTimeString(),
            ], JSON_PRETTY_PRINT)
        );
    }

    // ─────────────────────────────────────────────
    //  SAT: construir servicio (Detección Automática)
    // ─────────────────────────────────────────────

    private function construirServicio(string $relPath): Service
    {
        $configPath = "{$relPath}/credenciales/config.json";

        if (! Storage::disk('local')->exists($configPath)) {
            throw new Exception("No existe la configuración en storage/app/{$configPath}");
        }

        $config   = json_decode(Storage::disk('local')->get($configPath), true);
        $password = $config['password'];
        
        $credencialesDir = "{$relPath}/credenciales";
        $archivos = Storage::disk('local')->files($credencialesDir);
        
        $cerPath = null;
        $keyPath = null;

        foreach ($archivos as $archivo) {
            if (pathinfo($archivo, PATHINFO_EXTENSION) === 'cer') {
                $cerPath = $archivo;
            }
            if (pathinfo($archivo, PATHINFO_EXTENSION) === 'key') {
                $keyPath = $archivo;
            }
        }

        if (! $cerPath) {
            throw new Exception("No se encontró ningún archivo .cer en storage/app/{$credencialesDir}");
        }
        if (! $keyPath) {
            throw new Exception("No se encontró ningún archivo .key en storage/app/{$credencialesDir}");
        }

        $this->line('Validando FIEL...');
        $this->line("   Certificado: <fg=cyan>" . basename($cerPath) . "</>");
        $this->line("   Llave:       <fg=cyan>" . basename($keyPath) . "</>");

        $fiel = Fiel::create(
            Storage::disk('local')->get($cerPath),
            Storage::disk('local')->get($keyPath),
            $password
        );

        if (! $fiel->isValid()) {
            throw new Exception('La FIEL no es válida o la contraseña es incorrecta');
        }

        $this->line('   <fg=green>FIEL válida</>');

        return new Service(
            new FielRequestBuilder($fiel),
            new GuzzleWebClient()
        );
    }

    // ─────────────────────────────────────────────
    //  SAT: query + polling de paquetes
    // ─────────────────────────────────────────────

    private function solicitarYEsperarPaquetes(Service $service, string $inicio, string $fin, string $relPath): array
    {
        $this->newLine();
        $this->line('Consultando CFDI en el SAT...');
        
        $requestFile = "{$relPath}/solicitudes/" . md5($inicio . $fin) . '.json';
        
        if (Storage::disk('local')->exists($requestFile)) {
            $data = json_decode(Storage::disk('local')->get($requestFile), true);
            $requestId = $data['request_id'];
            $this->line('Reutilizando RequestId activo: ' . $requestId);
        } else {
            $query = $service->query(
                QueryParameters::create(
                    DateTimePeriod::createFromValues("{$inicio} 00:00:00", "{$fin} 23:59:59")
                )
                ->withDownloadType(DownloadType::received())
                ->withRequestType(RequestType::xml())
                ->withDocumentType(DocumentType::ingreso())
                ->withDocumentStatus(DocumentStatus::active())
            );

            if (! $query->getStatus()->isAccepted()) {
                $statusCode = $query->getStatus()->getCode();
                $message = $query->getStatus()->getMessage();

                if ($statusCode === 5002 || str_contains($message, '5002') || str_contains(strtolower($message), 'limite maximo')) {
                    $this->newLine();
                    $this->warn("⚠️ SAT Código 5002: Se alcanzó el límite máximo de peticiones para este rango/día.");
                    $this->comment("Esto suele significar que ya hay una solicitud en proceso en el SAT o el límite diario se superó.");
                    $this->comment("No se pueden descargar paquetes en este momento. Intenta más tarde o reduce el rango de fechas.");
                    
                    throw new Exception("El SAT rechazó la solicitud por límite de peticiones (Código 5002).");
                }

                throw new Exception($message);
            }

            $requestId = $query->getRequestId();

            Storage::disk('local')->put(
                $requestFile,
                json_encode([
                    'request_id' => $requestId,
                    'inicio'     => $inicio,
                    'fin'        => $fin,
                    'created_at' => now(),
                ])
            );
            $this->line("   Request ID : <fg=cyan>{$requestId}</>");
        }

        $this->newLine();
        $this->line('Esperando paquetes del SAT...');

        $maxIntentos = 5;
        $bar = $this->output->createProgressBar($maxIntentos);
        $bar->setFormat(
            " %current%/%max% [<fg=cyan>%bar%</>] %percent:3s%%   <fg=yellow>paquetes: %message%</>"
        );
        $bar->setMessage('0');
        $bar->start();

        $packages = [];

        for ($i = 1; $i <= $maxIntentos; $i++) {
            sleep(10);

            $verify   = $service->verify($requestId);
            $packages = $verify->getPackagesIds();

            $bar->setMessage((string) count($packages));
            $bar->advance();

            if (count($packages) > 0) {
                break;
            }
        }

        $bar->finish();
        $this->newLine(2);

        if (count($packages) === 0) {
            throw new Exception('No se encontraron paquetes en el SAT para este rango.');
        }

        $this->line("   <fg=green>" . count($packages) . " paquete(s) encontrado(s)</>");

        return $packages;
    }

    // ─────────────────────────────────────────────
    //  SAT: descarga, extracción y clasificación por mes
    // ─────────────────────────────────────────────

    private function descargarYExtraerPaquetes(Service $service, array $packages, string $relPath): void
    {
        if (empty($packages)) {
            return;
        }

        $this->newLine();
        $this->line('Descargando y organizando paquetes nuevos...');

        $bar = $this->output->createProgressBar(count($packages));
        $bar->setFormat(
            " %current%/%max% [<fg=cyan>%bar%</>] %percent:3s%%   <fg=yellow>%message%</>"
        );
        $bar->setMessage('iniciando...');
        $bar->start();

        $tempExtractPathRelative = "{$relPath}/temp_extract";
        $tempExtractPathAbsolute = Storage::disk('local')->path($tempExtractPathRelative);

        foreach ($packages as $packageId) {
            $bar->setMessage($packageId);

            $download = $service->download($packageId);
            
            $tempZipRelative = "temp_{$packageId}.zip";
            Storage::disk('local')->put($tempZipRelative, $download->getPackageContent());
            $tempZipAbsolute = Storage::disk('local')->path($tempZipRelative);

            $zip = new ZipArchive();
            if ($zip->open($tempZipAbsolute) === true) {

                $zip->extractTo($tempExtractPathAbsolute);
                $zip->close();

                $this->organizarXmlPorMes($tempExtractPathRelative, $relPath);
            }
            
            Storage::disk('local')->delete($tempZipRelative);

            $bar->advance();
        }

        if (Storage::disk('local')->exists($tempExtractPathRelative)) {
            Storage::disk('local')->deleteDirectory($tempExtractPathRelative);
        }

        $bar->setMessage('<fg=green>completado</>');
        $bar->finish();
        $this->newLine(2);
    }

    /**
     * Lee los XML sueltos, detecta su fecha real de emisión y los mueve a su carpeta final.
     */
    private function organizarXmlPorMes(string $tempFolder, string $relPath): void
    {
        $archivos = Storage::disk('local')->files($tempFolder);

        foreach ($archivos as $archivo) {
            if (pathinfo($archivo, PATHINFO_EXTENSION) !== 'xml') {
                Storage::disk('local')->delete($archivo);
                continue;
            }

            try {
                $content = Storage::disk('local')->get($archivo);
                
                if (empty($content)) {
                    Storage::disk('local')->delete($archivo);
                    continue;
                }

                $xml = new SimpleXMLElement($content);
                
                if (isset($xml['Fecha'])) {
                    $fechaEmision = (string) $xml['Fecha']; 
                    $año = date('Y', strtotime($fechaEmision));
                    $mesDigito = date('m', strtotime($fechaEmision));
                    
                    $nombreMes = $this->meses[$mesDigito] ?? 'OTROS';
                    $nombreCarpetaMes = "{$nombreMes}-{$año}"; 
                } else {
                    $nombreCarpetaMes = "SIN_FECHA";
                }

            } catch (Exception $e) {

                $nombreCarpetaMes = "CORRUPTOS";
            }

            $nombreArchivo = basename($archivo);
            $destinoFinal = "{$relPath}/xml/{$nombreCarpetaMes}/{$nombreArchivo}";

            Storage::disk('local')->move($archivo, $destinoFinal);
        }
    }

    // ─────────────────────────────────────────────
    //  Crear estructura de carpetas con Storage
    // ─────────────────────────────────────────────

    private function crearEstructura(string $relPath): void
    {
        $carpetas = [
            "{$relPath}/CREDENCIALES",
            "{$relPath}/XML",
            "{$relPath}/PDF",
            "{$relPath}/SOLICITUDES",
        ];

        foreach ($carpetas as $carpeta) {
            if (! Storage::disk('local')->exists($carpeta)) {
                Storage::disk('local')->makeDirectory($carpeta);
            }
        }
    }
}