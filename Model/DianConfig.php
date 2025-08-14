<?php
namespace FacturaScripts\Plugins\DianFactCol\Model;

use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;

class DianConfig extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;
    
    /** @var string */
    public $ambiente = 'pruebas';
    
    /** @var string */
    public $nit;
    
    /** @var string */
    public $razon_social;
    
    /** @var string */
    public $prefijo_factura;
    
    /** @var string */
    public $certificado_path;
    
    /** @var string */
    public $certificado_password;
    
    /** @var string */
    public $pin_software;
    
    /** @var bool */
    public $activo = true;
    
    /** @var string */
    public $created_at;
    
    /** @var string */
    public $updated_at;

    public static function tableName(): string
    {
        return 'dian_config';
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public function clear()
    {
        parent::clear();
        $this->ambiente = 'pruebas';
        $this->activo = true;
        $this->created_at = date('Y-m-d H:i:s');
    }

    public function test(): bool
    {
        // Limpiar datos
        $this->nit = trim($this->nit ?? '');
        $this->razon_social = trim($this->razon_social ?? '');
        $this->certificado_path = trim($this->certificado_path ?? '');
        
        // Validaciones
        if (empty($this->nit)) {
            $this->toolBox()->i18nLog()->error('El NIT es obligatorio');
            return false;
        }

        if (empty($this->razon_social)) {
            $this->toolBox()->i18nLog()->error('La razón social es obligatoria');
            return false;
        }

        // Validar formato del NIT
        if (!$this->validateNit($this->nit)) {
            $this->toolBox()->i18nLog()->error('El formato del NIT es inválido');
            return false;
        }

        // Validar certificado si está configurado
        if (!empty($this->certificado_path) && !$this->validateCertificate()) {
            $this->toolBox()->i18nLog()->warning('Advertencia: El certificado no es válido');
        }

        return parent::test();
    }

    public function save(): bool
    {
        if (empty($this->created_at)) {
            $this->created_at = date('Y-m-d H:i:s');
        }
        $this->updated_at = date('Y-m-d H:i:s');
        
        return parent::save();
    }

    /**
     * Obtiene la configuración DIAN activa
     */
    public static function getActiveConfig(): ?DianConfig
    {
        $config = new static();
        $where = [new DataBaseWhere('activo', true)];
        
        if ($config->loadFromCode('', $where)) {
            return $config;
        }

        // Si no existe configuración activa, crear una por defecto
        $config = new static();
        $config->id = 1;
        $config->activo = true;
        $config->ambiente = 'pruebas';
        $config->created_at = date('Y-m-d H:i:s');
        
        return $config;
    }

    /**
     * Valida el certificado digital
     */
    public function validateCertificate(): bool
    {
        if (empty($this->certificado_path)) {
            return false;
        }

        $path = FS_FOLDER . '/MyFiles/' . $this->certificado_path;
        if (!file_exists($path)) {
            return false;
        }

        if (empty($this->certificado_password)) {
            return false;
        }

        try {
            $cert = file_get_contents($path);
            $certs = [];
            return openssl_pkcs12_read($cert, $certs, $this->certificado_password);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Obtiene información del certificado
     */
    public function getCertificateInfo(): ?array
    {
        if (!$this->validateCertificate()) {
            return null;
        }

        try {
            $cert = file_get_contents(FS_FOLDER . '/MyFiles/' . $this->certificado_path);
            $certs = [];
            
            if (!openssl_pkcs12_read($cert, $certs, $this->certificado_password)) {
                return null;
            }

            $info = openssl_x509_parse($certs['cert']);

            return [
                'subject' => $info['subject'] ?? [],
                'issuer' => $info['issuer'] ?? [],
                'validFrom' => date('Y-m-d H:i:s', $info['validFrom_time_t']),
                'validTo' => date('Y-m-d H:i:s', $info['validTo_time_t']),
                'isValid' => $info['validTo_time_t'] > time(),
                'serialNumber' => $info['serialNumber'] ?? '',
                'version' => $info['version'] ?? 0
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Obtiene la URL del web service según el ambiente
     */
    public function getWebServiceUrl(): string
    {
        return $this->ambiente === 'produccion'
            ? 'https://vpfe.dian.gov.co/WcfDianCustomerServices.svc'
            : 'https://vpfe-hab.dian.gov.co/WcfDianCustomerServices.svc';
    }

    /**
     * Obtiene la resolución activa
     */
    public function getActiveResolution(): ?DianResolution
    {
        $resolution = new DianResolution();
        $where = [
            new DataBaseWhere('idconfig', $this->id),
            new DataBaseWhere('fecha_inicio', date('Y-m-d'), '<='),
            new DataBaseWhere('fecha_fin', date('Y-m-d'), '>=')
        ];
        
        return $resolution->loadFromCode('', $where) ? $resolution : null;
    }

    /**
     * Valida el formato del NIT colombiano
     */
    private function validateNit(string $nit): bool
    {
        // Eliminar caracteres no numéricos excepto el guión
        $nit = preg_replace('/[^0-9\-]/', '', $nit);
        
        if (empty($nit)) {
            return false;
        }

        // Si tiene guión, separar NIT y dígito verificador
        if (strpos($nit, '-') !== false) {
            $parts = explode('-', $nit);
            if (count($parts) !== 2) {
                return false;
            }
            $nitNumber = $parts[0];
            $checkDigit = $parts[1];
        } else {
            // Si no tiene guión, los últimos dígitos pueden ser el verificador
            if (strlen($nit) < 8) {
                return false;
            }
            $nitNumber = substr($nit, 0, -1);
            $checkDigit = substr($nit, -1);
        }

        // Validar que sean números
        if (!is_numeric($nitNumber) || !is_numeric($checkDigit)) {
            return false;
        }

        // Calcular dígito verificador
        $calculatedDigit = $this->calculateNitCheckDigit($nitNumber);
        
        return $calculatedDigit == $checkDigit;
    }

    /**
     * Calcula el dígito verificador del NIT
     */
    private function calculateNitCheckDigit(string $nit): int
    {
        $factors = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];
        $sum = 0;
        
        for ($i = 0; $i < strlen($nit); $i++) {
            $digit = intval($nit[strlen($nit) - 1 - $i]);
            $factor = $factors[$i] ?? 0;
            $sum += $digit * $factor;
        }
        
        $remainder = $sum % 11;
        
        if ($remainder < 2) {
            return $remainder;
        } else {
            return 11 - $remainder;
        }
    }
}