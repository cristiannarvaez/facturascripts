<?php
namespace FacturaScripts\Plugins\DianFactCol\Extension\Controller;

use Closure;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Plugins\DianFactCol\Model\DianInvoice;
use FacturaScripts\Plugins\DianFactCol\Model\DianConfig;

/**
 * Extensión del controlador EditFacturaCliente para funcionalidad DIAN
 */
class EditFacturaCliente
{
    public function createViews(): Closure
    {
        return function () {
            // Agregar pestaña de estado DIAN
            $this->addHtmlView('DianStatus', 'DianStatus', 'FacturaCliente', 'Estado DIAN', 'fas fa-certificate');
        };
    }

    public function createButtons(): Closure
    {
        return function ($viewName) {
            if ($viewName === 'EditFacturaCliente') {
                $config = DianConfig::getActiveConfig();
                if ($config && $config->id) {
                    // Botón para enviar a DIAN
                    $this->addButton($viewName, [
                        'action' => 'send-to-dian',
                        'color' => 'success',
                        'icon' => 'fas fa-paper-plane',
                        'label' => 'Enviar a DIAN',
                        'type' => 'action',
                        'confirm' => true
                    ]);

                    // Botón para descargar XML DIAN
                    $this->addButton($viewName, [
                        'action' => 'download-xml-dian',
                        'color' => 'info',
                        'icon' => 'fas fa-download',
                        'label' => 'XML DIAN',
                        'type' => 'action'
                    ]);

                    // Botón para generar PDF con datos DIAN
                    $this->addButton($viewName, [
                        'action' => 'generate-pdf-dian',
                        'color' => 'warning',
                        'icon' => 'fas fa-file-pdf',
                        'label' => 'PDF DIAN',
                        'type' => 'action'
                    ]);
                }
            }
        };
    }

    public function execPreviousAction(): Closure
    {
        return function ($action) {
            switch ($action) {
                case 'send-to-dian':
                    return $this->sendToDianAction();
                    
                case 'download-xml-dian':
                    return $this->downloadXmlDianAction();
                    
                case 'generate-pdf-dian':
                    return $this->generatePdfDianAction();
                    
                default:
                    return parent::execPreviousAction($action);
            }
        };
    }

    public function loadData(): Closure
    {
        return function ($viewName, $view) {
            switch ($viewName) {
                case 'DianStatus':
                    $this->loadDianStatusData($view);
                    break;
                    
                default:
                    parent::loadData($viewName, $view);
                    break;
            }
        };
    }

    /**
     * Acción para enviar factura a DIAN
     */
    protected function sendToDianAction(): bool
    {
        $factura = $this->getModel();
        if (!$factura->exists()) {
            $this->toolBox()->i18nLog()->error('Factura no encontrada');
            return false;
        }

        // Validaciones previas
        $validation = $this->validateFacturaForDian($factura);
        if (!$validation['valid']) {
            $this->toolBox()->i18nLog()->error('❌ Factura inválida: ' . $validation['message']);
            return false;
        }

        // Verificar configuración DIAN
        $config = DianConfig::getActiveConfig();
        if (!$config || !$config->id) {
            $this->toolBox()->i18nLog()->error('❌ No hay configuración DIAN activa. Configure primero en Administración > Configuración DIAN');
            return false;
        }

        // Verificar si ya fue enviada exitosamente
        $dianInvoice = new DianInvoice();
        $where = [new DataBaseWhere('idfactura', $factura->idfactura)];
        
        if ($dianInvoice->loadFromCode('', $where)) {
            if (in_array($dianInvoice->status, ['ENVIADO', 'ACEPTADO'])) {
                $this->toolBox()->i18nLog()->warning('⚠️ Esta factura ya fue enviada a DIAN exitosamente');
                return false;
            }
        } else {
            // Crear nuevo registro DIAN
            $dianInvoice->idfactura = $factura->idfactura;
        }

        try {
            $this->toolBox()->i18nLog()->info('📤 Enviando factura ' . $factura->codigo . ' a DIAN...');
            
            $result = $dianInvoice->send($factura);
            
            if ($result['success']) {
                $this->toolBox()->i18nLog()->info('✅ Factura enviada exitosamente a DIAN');
                
                if (isset($result['cufe'])) {
                    $this->toolBox()->i18nLog()->info('🔑 CUFE: ' . substr($result['cufe'], 0, 16) . '...');
                }
                
                if (isset($result['cude'])) {
                    $this->toolBox()->i18nLog()->info('🔑 CUDE: ' . substr($result['cude'], 0, 16) . '...');
                }
                
                return true;
            } else {
                $this->toolBox()->i18nLog()->error('❌ Error al enviar a DIAN: ' . $result['message']);
                return false;
            }
            
        } catch (\Exception $e) {
            $this->toolBox()->i18nLog()->error('💥 Error inesperado: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Acción para descargar XML de DIAN
     */
    protected function downloadXmlDianAction(): bool
    {
        $factura = $this->getModel();
        $dianInvoice = new DianInvoice();
        
        $where = [new DataBaseWhere('idfactura', $factura->idfactura)];
        if (!$dianInvoice->loadFromCode('', $where)) {
            $this->toolBox()->i18nLog()->error('❌ Esta factura no ha sido enviada a DIAN aún');
            return false;
        }

        $xmlContent = $dianInvoice->xml_signed ?: $dianInvoice->xml;
        
        if (empty($xmlContent)) {
            $this->toolBox()->i18nLog()->error('❌ El XML no está disponible para esta factura');
            return false;
        }

        try {
            // Generar nombre del archivo
            $cufeShort = substr($dianInvoice->cufe ?: 'sin-cufe', 0, 8);
            $filename = "DIAN_{$factura->codigo}_{$cufeShort}.xml";
            
            // Limpiar cualquier salida previa
            if (ob_get_level()) {
                ob_end_clean();
            }
            
            // Enviar archivo como descarga
            header('Content-Type: application/xml; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($xmlContent));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            echo $xmlContent;
            exit;
            
        } catch (\Exception $e) {
            $this->toolBox()->i18nLog()->error('❌ Error descargando XML: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Acción para generar PDF con información DIAN
     */
    protected function generatePdfDianAction(): bool
    {
        $factura = $this->getModel();
        $dianInvoice = new DianInvoice();
        
        $where = [new DataBaseWhere('idfactura', $factura->idfactura)];
        if (!$dianInvoice->loadFromCode('', $where)) {
            $this->toolBox()->i18nLog()->error('❌ Esta factura no ha sido enviada a DIAN aún');
            return false;
        }

        try {
            // Por ahora, generar un PDF simple con la información básica
            $htmlContent = $this->generateDianPdfHtml($factura, $dianInvoice);
            
            // Si está disponible TCPDF o similar, usarlo aquí
            // Por simplicidad, enviar como HTML
            $cufeShort = substr($dianInvoice->cufe ?: 'sin-cufe', 0, 8);
            $filename = "DIAN_{$factura->codigo}_{$cufeShort}.html";
            
            if (ob_get_level()) {
                ob_end_clean();
            }
            
            header('Content-Type: text/html; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($htmlContent));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            echo $htmlContent;
            exit;
            
        } catch (\Exception $e) {
            $this->toolBox()->i18nLog()->error('❌ Error generando PDF: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Cargar datos para la vista de estado DIAN
     */
    protected function loadDianStatusData($view): void
    {
        $factura = $this->getModel();
        $dianInvoice = new DianInvoice();
        
        $where = [new DataBaseWhere('idfactura', $factura->idfactura)];
        
        $dianData = null;
        if ($dianInvoice->loadFromCode('', $where)) {
            $dianData = $dianInvoice;
        }

        // Obtener configuración DIAN
        $config = DianConfig::getActiveConfig();

        // Pasar datos a la vista
        $view->model = $factura;
        $view->dianData = $dianData;
        $view->dianConfig = $config;
        $view->validation = $this->validateFacturaForDian($factura);
    }

    /**
     * Valida si una factura puede ser enviada a DIAN
     */
    private function validateFacturaForDian($factura): array
    {
        $errors = [];
        $warnings = [];

        // Validaciones obligatorias
        if ($factura->total <= 0) {
            $errors[] = 'El total de la factura debe ser mayor a cero';
        }

        if (empty($factura->cifnif)) {
            $errors[] = 'El cliente debe tener NIT o Cédula';
        }

        if (empty($factura->nombrecliente)) {
            $errors[] = 'El cliente debe tener nombre o razón social';
        }

        // Validar que tenga líneas
        $lines = $factura->getLines();
        if (empty($lines)) {
            $errors[] = 'La factura debe tener al menos una línea de producto/servicio';
        } else {
            // Validar cada línea
            foreach ($lines as $line) {
                if (empty($line->descripcion)) {
                    $warnings[] = "Línea {$line->idlinea}: Falta descripción del producto";
                }
                if ($line->cantidad <= 0) {
                    $errors[] = "Línea {$line->idlinea}: La cantidad debe ser mayor a cero";
                }
                if ($line->pvpunitario < 0) {
                    $errors[] = "Línea {$line->idlinea}: El precio unitario no puede ser negativo";
                }
            }
        }

        // Validaciones de configuración
        $config = DianConfig::getActiveConfig();
        if (!$config || !$config->id) {
            $errors[] = 'No hay configuración DIAN activa';
        } else {
            $resolution = $config->getActiveResolution();
            if (!$resolution || !$resolution->isActive()) {
                $errors[] = 'La resolución DIAN no está vigente';
            } else {
                $numeroFactura = intval(str_replace($resolution->prefijo, '', $factura->codigo));
                if (!$resolution->isInvoiceNumberInRange($numeroFactura)) {
                    $errors[] = 'El número de factura está fuera del rango autorizado';
                }
            }
        }

        // Validaciones de advertencia
        if (strlen($factura->observaciones ?: '') > 500) {
            $warnings[] = 'Las observaciones son muy largas (máximo recomendado: 500 caracteres)';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'message' => empty($errors) ? 'Factura válida para envío a DIAN' : implode('; ', $errors)
        ];
    }

    /**
     * Genera HTML para mostrar información de la factura DIAN
     */
    private function generateDianPdfHtml($factura, $dianInvoice): string
    {
        $config = DianConfig::getActiveConfig();
        $resolution = $config ? $config->getActiveResolution() : null;
        
        $html = "<!DOCTYPE html>
        <html lang='es'>
        <head>
            <meta charset='UTF-8'>
            <title>Factura Electrónica DIAN - {$factura->codigo}</title>
            <style>
                body { font-family: Arial, sans-serif; font-size: 12px; margin: 20px; }
                .header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
                .company-info { margin: 20px 0; }
                .invoice-details { margin: 20px 0; }
                .dian-info { margin-top: 30px; border: 2px solid #000; padding: 15px; background-color: #f9f9f9; }
                .status-badge { padding: 5px 10px; border-radius: 3px; color: white; }
                .status-success { background-color: #28a745; }
                .status-warning { background-color: #ffc107; color: #000; }
                .status-danger { background-color: #dc3545; }
                .qr-info { text-align: center; margin: 20px 0; }
                .footer { margin-top: 30px; font-size: 10px; text-align: center; color: #666; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>FACTURA ELECTRÓNICA DE VENTA</h1>";
                
        if ($config) {
            $html .= "<h2>" . htmlspecialchars($config->razon_social) . "</h2>
                      <p><strong>NIT:</strong> {$config->nit}</p>";
        }
        
        if ($resolution) {
            $html .= "<p><strong>Resolución DIAN:</strong> {$resolution->numero}</p>
                      <p><strong>Rango autorizado:</strong> {$resolution->rango_desde} - {$resolution->rango_hasta}</p>";
        }
        
        $html .= "</div>
        
            <div class='invoice-details'>
                <h3>Datos de la Factura</h3>
                <p><strong>Número:</strong> {$factura->codigo}</p>
                <p><strong>Fecha:</strong> {$factura->fecha}</p>
                <p><strong>Cliente:</strong> {$factura->nombrecliente}</p>
                <p><strong>NIT/CC:</strong> {$factura->cifnif}</p>
                <p><strong>Total:</strong> $" . number_format($factura->total, 2) . "</p>
            </div>
            
            <div class='dian-info'>
                <h3>Información DIAN</h3>";
                
        $statusClass = 'status-warning';
        if ($dianInvoice->status === 'ACEPTADO') $statusClass = 'status-success';
        elseif ($dianInvoice->status === 'ERROR') $statusClass = 'status-danger';
        
        $html .= "<p><strong>Estado:</strong> <span class='status-badge {$statusClass}'>{$dianInvoice->status}</span></p>";
        
        if ($dianInvoice->cufe) {
            $html .= "<p><strong>CUFE:</strong> <code>{$dianInvoice->cufe}</code></p>";
        }
        
        if ($dianInvoice->fecha_envio) {
            $html .= "<p><strong>Fecha de Envío:</strong> {$dianInvoice->fecha_envio}</p>";
        }
        
        if ($dianInvoice->error_message) {
            $html .= "<p><strong>Error:</strong> <span style='color: red;'>{$dianInvoice->error_message}</span></p>";
        }
        
        $html .= "</div>";
        
        if ($dianInvoice->qr_code) {
            $html .= "<div class='qr-info'>
                        <h4>Código QR</h4>
                        <img src='{$dianInvoice->qr_code}' alt='QR Code' style='max-width: 200px;'>
                      </div>";
        }
        
        $html .= "<div class='footer'>
                    <p>Documento generado automáticamente por el sistema de facturación electrónica</p>
                    <p>Fecha de generación: " . date('Y-m-d H:i:s') . "</p>
                  </div>
                  
        </body>
        </html>";
        
        return $html;
    }
}