<?php
namespace FacturaScripts\Plugins\DianFactCol\Model;

use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Core\Model\FacturaCliente;
use FacturaScripts\Plugins\DianFactCol\Model\DianConfig;
use FacturaScripts\Plugins\DianFactCol\Lib\XmlSigner;
use FacturaScripts\Plugins\DianFactCol\Lib\QrGenerator;
use FacturaScripts\Plugins\DianFactCol\Lib\DianWebService;

class DianInvoice extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;
    
    /** @var int */
    public $idfactura;
    
    /** @var string */
    public $cude;
    
    /** @var string */
    public $cufe;
    
    /** @var string */
    public $xml;
    
    /** @var string */
    public $xml_signed;
    
    /** @var string */
    public $qr_code;
    
    /** @var string */
    public $status = 'PENDIENTE';
    
    /** @var string */
    public $fecha_envio;
    
    /** @var string */
    public $fecha_respuesta;
    
    /** @var string */
    public $respuesta_dian;
    
    /** @var string */
    public $error_message;
    
    /** @var int */
    public $intentos = 0;
    
    /** @var string */
    public $created_at;
    
    /** @var string */
    public $updated_at;

    public static function tableName(): string
    {
        return 'dian_invoices';
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public function clear()
    {
        parent::clear();
        $this->status = 'PENDIENTE';
        $this->intentos = 0;
        $this->created_at = date('Y-m-d H:i:s');
    }

    public function test(): bool
    {
        // Validaciones básicas
        if (empty($this->idfactura)) {
            $this->toolBox()->i18nLog()->error('ID de factura es obligatorio');
            return false;
        }

        if (empty($this->cufe) && $this->status !== 'PENDIENTE') {
            $this->toolBox()->i18nLog()->error('CUFE es obligatorio para facturas procesadas');
            return false;
        }

        // Validar estado
        $validStatuses = ['PENDIENTE', 'PROCESANDO', 'ENVIADO', 'ACEPTADO', 'RECHAZADO', 'ERROR'];
        if (!in_array($this->status, $validStatuses)) {
            $this->toolBox()->i18nLog()->error('Estado inválido: ' . $this->status);
            return false;
        }

        return parent::test();
    }

    /**
     * Envía la factura a DIAN
     */
    public function send(FacturaCliente $factura): array
    {
        try {
            $this->status = 'PROCESANDO';
            $this->intentos++;
            $this->save();

            // Obtener configuración activa
            $config = DianConfig::getActiveConfig();
            if (!$config || !$config->id) {
                return $this->handleError('No hay configuración DIAN activa');
            }

            // Validar resolución
            $resolution = $config->getActiveResolution();
            if (!$resolution || !$resolution->isActive()) {
                return $this->handleError('Resolución DIAN no vigente');
            }

            // Validar número de factura
            $number = (int) str_replace($resolution->prefijo, '', $factura->codigo);
            if (!$resolution->isInvoiceNumberInRange($number)) {
                return $this->handleError('Número de factura fuera del rango autorizado');
            }

            // Generar CUFE
            $this->cufe = $this->generateCufe($factura, $config, $resolution);
            
            // Generar XML
            $this->xml = $this->generateXml($factura, $config, $resolution);
            
            // Firmar XML
            if (!empty($config->certificado_path)) {
                $certPath = FS_FOLDER . '/MyFiles/' . $config->certificado_path;
                if (file_exists($certPath)) {
                    $this->xml_signed = XmlSigner::sign($this->xml, $certPath, $config->certificado_password);
                } else {
                    return $this->handleError('Certificado no encontrado: ' . $config->certificado_path);
                }
            } else {
                $this->xml_signed = $this->xml;
            }

            // Enviar a DIAN
            $ws = new DianWebService($config);
            $result = $ws->sendInvoice($this->xml_signed);

            if ($result['success']) {
                $this->status = 'ENVIADO';
                $this->fecha_envio = date('Y-m-d H:i:s');
                $this->respuesta_dian = json_encode($result['data']);
                
                // Actualizar CUDE si viene en la respuesta
                if (isset($result['data']['cude'])) {
                    $this->cude = $result['data']['cude'];
                }
                
                // Generar código QR
                $this->qr_code = $this->generateQrCode($factura, $config);
                
                $this->error_message = null;
                $this->save();
                
                return [
                    'success' => true,
                    'message' => 'Factura enviada exitosamente a DIAN',
                    'cufe' => $this->cufe,
                    'cude' => $this->cude,
                    'status' => $this->status
                ];
            } else {
                return $this->handleError($result['message'], $result);
            }
            
        } catch (\Exception $e) {
            return $this->handleError('Error inesperado: ' . $e->getMessage());
        }
    }

    /**
     * Maneja errores durante el envío
     */
    private function handleError(string $message, array $data = null): array
    {
        $this->status = 'ERROR';
        $this->error_message = $message;
        
        if ($data) {
            $this->respuesta_dian = json_encode($data);
        }
        
        $this->save();
        
        return [
            'success' => false,
            'message' => $message,
            'status' => $this->status
        ];
    }

    /**
     * Genera el CUFE (Código Único de Facturación Electrónica)
     */
    private function generateCufe(FacturaCliente $factura, DianConfig $config, $resolution): string
    {
        $data = [
            $factura->codigo,                                    // Número de factura
            $factura->fecha,                                     // Fecha de factura
            date('H:i:s', strtotime($factura->hora ?: 'now')), // Hora
            number_format($factura->total, 2, '.', ''),         // Valor total
            '01',                                                // Código de impuesto (IVA)
            $config->nit,                                        // NIT del emisor
            $factura->cifnif,                                    // NIT/CC del adquiriente
            $config->pin_software ?: '693ff6f2-2153-4669-893d-bcd0b0146d68', // PIN del software
            $config->ambiente === 'produccion' ? '1' : '2',     // Ambiente
            $resolution->numero                                  // Número de resolución
        ];
        
        return hash('sha384', implode('', $data));
    }

    /**
     * Genera el XML UBL de la factura
     */
    private function generateXml(FacturaCliente $factura, DianConfig $config, $resolution): string
    {
        // Crear documento XML básico (versión simplificada)
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"></Invoice>');
        
        // Información básica UBL
        $xml->addChild('cbc:UBLVersionID', '2.1', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xml->addChild('cbc:CustomizationID', '20', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xml->addChild('cbc:ProfileID', 'DIAN 2.1: Factura Electrónica de Venta', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xml->addChild('cbc:ID', $factura->codigo, 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xml->addChild('cbc:UUID', $this->cufe, 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xml->addChild('cbc:IssueDate', $factura->fecha, 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xml->addChild('cbc:IssueTime', date('H:i:s', strtotime($factura->hora ?: 'now')), 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xml->addChild('cbc:InvoiceTypeCode', '01', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2'); // Factura de venta
        $xml->addChild('cbc:DocumentCurrencyCode', $factura->coddivisa ?: 'COP', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        // Información del emisor (empresa)
        $supplierParty = $xml->addChild('cac:AccountingSupplierParty', '', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $party = $supplierParty->addChild('cac:Party', '', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $partyName = $party->addChild('cac:PartyName', '', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $partyName->addChild('cbc:Name', $config->razon_social, 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        
        $partyTaxScheme = $party->addChild('cac:PartyTaxScheme', '', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $partyTaxScheme->addChild('cbc:RegistrationName', $config->razon_social, 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $companyID = $partyTaxScheme->addChild('cbc:CompanyID', $config->nit, 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $companyID->addAttribute('schemeAgencyID', '195');
        $companyID->addAttribute('schemeID', '31'); // NIT
        
        // Información del cliente
        $customerParty = $xml->addChild('cac:AccountingCustomerParty', '', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $customerPartyData = $customerParty->addChild('cac:Party', '', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $customerPartyName = $customerPartyData->addChild('cac:PartyName', '', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $customerPartyName->addChild('cbc:Name', $factura->nombrecliente, 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        
        $customerTaxScheme = $customerPartyData->addChild('cac:PartyTaxScheme', '', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $customerTaxScheme->addChild('cbc:RegistrationName', $factura->nombrecliente, 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $customerCompanyID = $customerTaxScheme->addChild('cbc:CompanyID', $factura->cifnif, 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $customerCompanyID->addAttribute('schemeAgencyID', '195');
        $customerCompanyID->addAttribute('schemeID', $this->getDocumentType($factura->cifnif));

        // Líneas de la factura
        $lines = $factura->getLines();
        foreach ($lines as $index => $line) {
            $invoiceLine = $xml->addChild('cac:InvoiceLine', '', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
            $invoiceLine->addChild('cbc:ID', $index + 1, 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
            $invoiceLine->addChild('cbc:InvoicedQuantity', $line->cantidad, 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
            $invoiceLine->addChild('cbc:LineExtensionAmount', number_format($line->pvptotal, 2, '.', ''), 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
            
            $item = $invoiceLine->addChild('cac:Item', '', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
            $item->addChild('cbc:Description', $line->descripcion, 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
            
            $price = $invoiceLine->addChild('cac:Price', '', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
            $price->addChild('cbc:PriceAmount', number_format($line->pvpunitario, 2, '.', ''), 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        }

        // Totales
        $legalMonetaryTotal = $xml->addChild('cac:LegalMonetaryTotal', '', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $legalMonetaryTotal->addChild('cbc:LineExtensionAmount', number_format($factura->neto, 2, '.', ''), 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $legalMonetaryTotal->addChild('cbc:TaxExclusiveAmount', number_format($factura->neto, 2, '.', ''), 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $legalMonetaryTotal->addChild('cbc:TaxInclusiveAmount', number_format($factura->total, 2, '.', ''), 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $legalMonetaryTotal->addChild('cbc:PayableAmount', number_format($factura->total, 2, '.', ''), 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        return $xml->asXML();
    }

    /**
     * Determina el tipo de documento según el formato del NIT/Cédula
     */
    private function getDocumentType(string $document): string
    {
        // Simplificado: si tiene más de 10 dígitos o contiene guión, es NIT
        if (strlen(preg_replace('/[^0-9]/', '', $document)) > 10 || strpos($document, '-') !== false) {
            return '31'; // NIT
        }
        return '13'; // Cédula de ciudadanía
    }

    /**
     * Genera el código QR para la factura
     */
    private function generateQrCode(FacturaCliente $factura, DianConfig $config): string
    {
        try {
            return QrGenerator::generate([
                'codigo' => $factura->codigo,
                'fecha' => $factura->fecha,
                'hora' => date('H:i:s', strtotime($factura->hora ?: 'now')),
                'nit' => $config->nit,
                'cliente' => $factura->cifnif,
                'neto' => $factura->neto,
                'iva' => $factura->totaliva,
                'otros' => $factura->totalrecargo + $factura->totalirpf,
                'total' => $factura->total,
                'cufe' => $this->cufe
            ]);
        } catch (\Exception $e) {
            // Si falla la generación del QR, continuar sin él
            return '';
        }
    }

    /**
     * Consulta el estado de la factura en DIAN
     */
    public function queryStatus(): array
    {
        if (empty($this->cufe)) {
            return ['success' => false, 'message' => 'No hay CUFE para consultar'];
        }

        $config = DianConfig::getActiveConfig();
        if (!$config) {
            return ['success' => false, 'message' => 'No hay configuración DIAN'];
        }

        try {
            $ws = new DianWebService($config);
            $result = $ws->queryInvoiceStatus($this->cufe);
            
            if ($result['success']) {
                // Actualizar estado según la respuesta
                $previousStatus = $this->status;
                
                if (isset($result['data']['status'])) {
                    $this->status = $result['data']['status'];
                    $this->fecha_respuesta = date('Y-m-d H:i:s');
                    $this->respuesta_dian = json_encode($result['data']);
                    
                    if ($this->status !== $previousStatus) {
                        $this->save();
                    }
                }
            }
            
            return $result;
            
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Error consultando estado: ' . $e->getMessage()];
        }
    }

    /**
     * Reintenta el envío de la factura
     */
    public function retry(FacturaCliente $factura): array
    {
        if ($this->intentos >= 3) {
            return ['success' => false, 'message' => 'Máximo de intentos alcanzado'];
        }
        
        if (in_array($this->status, ['ENVIADO', 'ACEPTADO'])) {
            return ['success' => false, 'message' => 'La factura ya fue procesada exitosamente'];
        }
        
        // Resetear estado para reintento
        $this->status = 'PENDIENTE';
        $this->error_message = null;
        
        return $this->send($factura);
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
     * Obtiene el nombre del estado en español
     */
    public function getStatusName(): string
    {
        $statuses = [
            'PENDIENTE' => 'Pendiente',
            'PROCESANDO' => 'Procesando',
            'ENVIADO' => 'Enviado',
            'ACEPTADO' => 'Aceptado',
            'RECHAZADO' => 'Rechazado',
            'ERROR' => 'Error'
        ];
        
        return $statuses[$this->status] ?? $this->status;
    }

    /**
     * Obtiene la clase CSS para el estado
     */
    public function getStatusClass(): string
    {
        $classes = [
            'PENDIENTE' => 'secondary',
            'PROCESANDO' => 'info',
            'ENVIADO' => 'primary',
            'ACEPTADO' => 'success',
            'RECHAZADO' => 'danger',
            'ERROR' => 'danger'
        ];
        
        return $classes[$this->status] ?? 'secondary';
    }

    /**
     * Verifica si la factura puede ser reenviada
     */
    public function canBeResent(): bool
    {
        return !in_array($this->status, ['ACEPTADO']) && $this->intentos < 3;
    }

    /**
     * Obtiene información resumida para mostrar
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'idfactura' => $this->idfactura,
            'cufe_short' => $this->cufe ? substr($this->cufe, 0, 16) . '...' : 'N/A',
            'status' => $this->status,
            'status_name' => $this->getStatusName(),
            'status_class' => $this->getStatusClass(),
            'fecha_envio' => $this->fecha_envio,
            'intentos' => $this->intentos,
            'can_resend' => $this->canBeResent(),
            'has_xml' => !empty($this->xml_signed) || !empty($this->xml),
            'has_qr' => !empty($this->qr_code),
            'error_message' => $this->error_message
        ];
    }
}