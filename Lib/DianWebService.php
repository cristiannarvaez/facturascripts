<?php
namespace FacturaScripts\Plugins\DianFactCol\Lib;

use FacturaScripts\Plugins\DianFactCol\Model\DianConfig;
use FacturaScripts\Core\Base\ToolBox;

/**
 * Servicio web para comunicación con DIAN
 */
class DianWebService
{
    /** @var DianConfig */
    private $config;
    
    /** @var ToolBox */
    private $toolBox;

    public function __construct(DianConfig $config)
    {
        $this->config = $config;
        $this->toolBox = new ToolBox();
    }

    /**
     * Envía una factura a DIAN
     */
    public function sendInvoice(string $xml): array
    {
        try {
            $url = $this->getWebServiceUrl() . '/SendBillAsync';
            $zip = $this->createZip($xml);

            // Usar cURL si GuzzleHttp no está disponible
            if (class_exists('GuzzleHttp\Client')) {
                return $this->sendWithGuzzle($url, $zip);
            } else {
                return $this->sendWithCurl($url, $zip);
            }

        } catch (\Throwable $e) {
            $this->toolBox->log()->error('Error enviando factura DIAN: ' . $e->getMessage());
            return [
                'success' => false, 
                'message' => 'Error de conexión: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Envío usando GuzzleHttp
     */
    private function sendWithGuzzle(string $url, string $zip): array
    {
        $client = new \GuzzleHttp\Client([
            'verify' => false,
            'timeout' => 60
        ]);

        $options = [
            'multipart' => [
                [
                    'name' => 'file',
                    'contents' => $zip,
                    'filename' => 'invoice.zip'
                ]
            ]
        ];

        // Agregar certificado si está configurado
        if (!empty($this->config->certificado_path)) {
            $certPath = FS_FOLDER . '/MyFiles/' . $this->config->certificado_path;
            if (file_exists($certPath)) {
                $options['cert'] = [$certPath, $this->config->certificado_password];
            }
        }

        $response = $client->post($url, $options);
        $body = (string) $response->getBody();
        $data = json_decode($body, true) ?? [];

        return [
            'success' => true,
            'data' => $data,
            'response_code' => $response->getStatusCode()
        ];
    }

    /**
     * Envío usando cURL
     */
    private function sendWithCurl(string $url, string $zip): array
    {
        $ch = curl_init();
        
        // Crear archivo temporal para el ZIP
        $tempFile = tempnam(sys_get_temp_dir(), 'dian_zip_');
        file_put_contents($tempFile, $zip);

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'file' => new \CURLFile($tempFile, 'application/zip', 'invoice.zip')
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: multipart/form-data'
            ]
        ]);

        // Configurar certificado si está disponible
        if (!empty($this->config->certificado_path)) {
            $certPath = FS_FOLDER . '/MyFiles/' . $this->config->certificado_path;
            if (file_exists($certPath)) {
                curl_setopt($ch, CURLOPT_SSLCERT, $certPath);
                if (!empty($this->config->certificado_password)) {
                    curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $this->config->certificado_password);
                }
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        unlink($tempFile); // Limpiar archivo temporal

        if ($response === false || !empty($error)) {
            return [
                'success' => false,
                'message' => 'Error cURL: ' . $error
            ];
        }

        $data = json_decode($response, true) ?? [];

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'data' => $data,
            'response_code' => $httpCode,
            'raw_response' => $response
        ];
    }

    /**
     * Consulta el estado de una factura
     */
    public function queryInvoiceStatus(string $cufe): array
    {
        try {
            $url = $this->getWebServiceUrl() . '/GetStatus';
            
            $data = [
                'trackId' => $cufe
            ];

            if (class_exists('GuzzleHttp\Client')) {
                $client = new \GuzzleHttp\Client(['verify' => false]);
                $response = $client->post($url, [
                    'json' => $data,
                    'timeout' => 30
                ]);
                
                $body = (string) $response->getBody();
                $responseData = json_decode($body, true) ?? [];
                
                return [
                    'success' => true,
                    'data' => $responseData
                ];
            } else {
                return $this->queryStatusWithCurl($url, $data);
            }

        } catch (\Throwable $e) {
            $this->toolBox->log()->error('Error consultando estado DIAN: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error consultando estado: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Consulta estado usando cURL
     */
    private function queryStatusWithCurl(string $url, array $data): array
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen(json_encode($data))
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);

        if ($response === false || !empty($error)) {
            return [
                'success' => false,
                'message' => 'Error cURL: ' . $error
            ];
        }

        $responseData = json_decode($response, true) ?? [];

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'data' => $responseData
        ];
    }

    /**
     * Prueba la conexión con DIAN
     */
    public function testConnection(): bool
    {
        try {
            $url = $this->getWebServiceUrl() . '?wsdl';
            
            if (class_exists('GuzzleHttp\Client')) {
                $client = new \GuzzleHttp\Client(['verify' => false]);
                $response = $client->get($url, ['timeout' => 10]);
                return $response->getStatusCode() === 200;
            } else {
                return $this->testConnectionWithCurl($url);
            }
            
        } catch (\Throwable $e) {
            $this->toolBox->log()->error('Error probando conexión DIAN: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Prueba conexión usando cURL
     */
    private function testConnectionWithCurl(string $url): bool
    {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_NOBODY => true, // Solo HEAD request
            CURLOPT_FOLLOWLOCATION => true
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);

        return empty($error) && $httpCode >= 200 && $httpCode < 400;
    }

    /**
     * Crea un archivo ZIP con el XML
     */
    private function createZip(string $xml): string
    {
        if (!class_exists('ZipArchive')) {
            throw new \Exception('Extensión ZIP no disponible');
        }

        $zip = new \ZipArchive();
        $tempPath = tempnam(sys_get_temp_dir(), 'dian_');
        
        if ($zip->open($tempPath, \ZipArchive::CREATE) !== true) {
            throw new \Exception('No se pudo crear el archivo ZIP');
        }
        
        $zip->addFromString('invoice.xml', $xml);
        $zip->close();
        
        $content = file_get_contents($tempPath);
        unlink($tempPath); // Limpiar archivo temporal
        
        return $content;
    }

    /**
     * Obtiene la URL del web service según el ambiente
     */
    public function getWebServiceUrl(): string
    {
        return $this->config->ambiente === 'produccion'
            ? 'https://vpfe.dian.gov.co/WcfDianCustomerServices.svc'
            : 'https://vpfe-hab.dian.gov.co/WcfDianCustomerServices.svc';
    }

    /**
     * Obtiene información del certificado
     */
    public function getCertificateInfo(): ?array
    {
        if (empty($this->config->certificado_path)) {
            return null;
        }

        $certPath = FS_FOLDER . '/MyFiles/' . $this->config->certificado_path;
        if (!file_exists($certPath)) {
            return null;
        }

        try {
            $certContent = file_get_contents($certPath);
            $certs = [];
            
            if (!openssl_pkcs12_read($certContent, $certs, $this->config->certificado_password)) {
                return null;
            }

            $certInfo = openssl_x509_parse($certs['cert']);
            
            return [
                'subject' => $certInfo['subject'] ?? [],
                'issuer' => $certInfo['issuer'] ?? [],
                'valid_from' => date('Y-m-d H:i:s', $certInfo['validFrom_time_t']),
                'valid_to' => date('Y-m-d H:i:s', $certInfo['validTo_time_t']),
                'is_valid' => $certInfo['validTo_time_t'] > time(),
                'serial_number' => $certInfo['serialNumber'] ?? '',
                'days_until_expiry' => max(0, ceil(($certInfo['validTo_time_t'] - time()) / 86400))
            ];
            
        } catch (\Exception $e) {
            $this->toolBox->log()->error('Error leyendo certificado: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Valida la configuración antes de enviar
     */
    public function validateConfig(): array
    {
        $errors = [];

        if (empty($this->config->nit)) {
            $errors[] = 'NIT no configurado';
        }

        if (empty($this->config->razon_social)) {
            $errors[] = 'Razón social no configurada';
        }

        if (empty($this->config->certificado_path)) {
            $errors[] = 'Certificado no configurado';
        } else {
            $certPath = FS_FOLDER . '/MyFiles/' . $this->config->certificado_path;
            if (!file_exists($certPath)) {
                $errors[] = 'Archivo de certificado no encontrado';
            } elseif (empty($this->config->certificado_password)) {
                $errors[] = 'Contraseña de certificado no configurada';
            } else {
                // Validar certificado
                $certInfo = $this->getCertificateInfo();
                if (!$certInfo) {
                    $errors[] = 'Certificado inválido o contraseña incorrecta';
                } elseif (!$certInfo['is_valid']) {
                    $errors[] = 'Certificado expirado (venció el ' . $certInfo['valid_to'] . ')';
                } elseif ($certInfo['days_until_expiry'] <= 30) {
                    $errors[] = 'Certificado próximo a vencer (' . $certInfo['days_until_expiry'] . ' días restantes)';
                }
            }
        }

        $resolution = $this->config->getActiveResolution();
        if (!$resolution) {
            $errors[] = 'No hay resolución DIAN vigente';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Registra una operación en los logs
     */
    private function logOperation(string $type, string $message, array $details = null): void
    {
        try {
            $log = new \FacturaScripts\Plugins\DianFactCol\Model\DianLog();
            $log->fecha = date('Y-m-d H:i:s');
            $log->tipo = $type;
            $log->mensaje = $message;
            $log->detalles = $details ? json_encode($details) : null;
            $log->save();
        } catch (\Exception $e) {
            // Si falla el log, no interrumpir el proceso principal
            $this->toolBox->log()->error('Error guardando log DIAN: ' . $e->getMessage());
        }
    }

    /**
     * Envía múltiples facturas en lote
     */
    public function sendBatch(array $xmls): array
    {
        $results = [];
        $errors = 0;
        $success = 0;

        foreach ($xmls as $index => $xml) {
            try {
                $result = $this->sendInvoice($xml);
                $results[$index] = $result;
                
                if ($result['success']) {
                    $success++;
                } else {
                    $errors++;
                }
                
                // Pausa entre envíos para no sobrecargar el servidor
                if (count($xmls) > 1) {
                    sleep(1);
                }
                
            } catch (\Exception $e) {
                $results[$index] = [
                    'success' => false,
                    'message' => 'Error procesando factura: ' . $e->getMessage()
                ];
                $errors++;
            }
        }

        return [
            'total' => count($xmls),
            'success' => $success,
            'errors' => $errors,
            'results' => $results
        ];
    }
}