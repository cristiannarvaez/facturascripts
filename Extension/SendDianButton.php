<?php
namespace FacturaScripts\Plugins\DianFactCol\Extension;

use Closure;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Plugins\DianFactCol\Model\DianInvoice;

/**
 * Extensión para agregar botones DIAN en las vistas de facturas
 */
class SendDianButton
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
                // Botón para enviar a DIAN
                $this->addButton($viewName, [
                    'action' => 'send-to-dian',
                    'color' => 'success',
                    'icon' => 'fas fa-paper-plane',
                    'label' => 'Enviar a DIAN',
                    'type' => 'action'
                ]);

                // Botón para descargar XML
                $this->addButton($viewName, [
                    'action' => 'download-xml-dian',
                    'color' => 'info',
                    'icon' => 'fas fa-download', 
                    'label' => 'XML DIAN',
                    'type' => 'action'
                ]);

                // Botón para generar PDF DIAN
                $this->addButton($viewName, [
                    'action' => 'generate-pdf-dian',
                    'color' => 'warning',
                    'icon' => 'fas fa-file-pdf',
                    'label' => 'PDF DIAN',
                    'type' => 'action'
                ]);
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
            $this->toolBox()->i18nLog()->error('Factura inválida: ' . $validation['message']);
            return false;
        }

        // Verificar configuración DIAN
        $config = DianConfig::getActiveConfig();
        if (!$config) {
            $this->toolBox()->i18nLog()->error('No hay configuración DIAN activa. Configure primero en Administración > Configuración DIAN');
            return false;
        }

        // Verificar si ya fue enviada
        $dianInvoice = new DianInvoice();
        $where = [new DataBaseWhere('idfactura', $factura->idfactura)];
        
        if ($dianInvoice->loadFromCode('', $where) && in_array($dianInvoice->status, ['ENVIADO', 'ACEPTADO'])) {
            $this->toolBox()->i18nLog()->warning('Esta factura ya fue enviada a DIAN exitosamente');
            return false;
        }
        
        try {
            $result = $dianInvoice->send($factura);
            
            if ($result['success']) {
                $this->toolBox()->i18nLog()->info('✅ Factura enviada exitosamente a DIAN');
                if (isset($result['cufe'])) {
                    $this->toolBox()->i18nLog()->info('CUFE: ' . $result['cufe']);
                }
                if (isset($result['cude'])) {
                    $this->toolBox()->i18nLog()->info('CUDE: ' . $result['cude']);
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

    protected function downloadXmlDianAction(): bool
    {
        $factura = $this->getModel();
        $dianInvoice = new DianInvoice();
        
        $where = [new DataBaseWhere('idfactura', $factura->idfactura)];
        if (!$dianInvoice->loadFromCode('', $where)) {
            $this->toolBox()->i18nLog()->error('Esta factura no ha sido enviada a DIAN aún');
            return false;
        }

        $xmlContent = $dianInvoice->xml_signed ?: $dianInvoice->xml;
        
        if (empty($xmlContent)) {
            $this->toolBox()->i18nLog()->error('El XML no está disponible para esta factura');
            return false;
        }

        // Generar nombre del archivo
        $filename = "DIAN_{$factura->codigo}_{$dianInvoice->cufe}.xml";
        
        // Enviar archivo como descarga
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($xmlContent));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        echo $xmlContent;
        exit;
    }

    protected function generatePdfDianAction(): bool
    {
        $factura = $this->getModel();
        $dianInvoice = new DianInvoice();
        
        $where = [new DataBaseWhere('idfactura', $factura->idfactura)];
        if (!$dianInvoice->loadFromCode('', $where)) {
            $this->toolBox()->i18nLog()->error('Esta factura no ha sido enviada a DIAN aún');
            return false;
        }

        try {
            // Generar PDF con información DIAN
            $pdfContent = $this->generateDianPdf($factura, $dianInvoice);
            
            $filename = "DIAN_{$factura->codigo}_{$dianInvoice->cufe}.pdf";
            
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($pdfContent));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            echo $pdfContent;
            exit;
            
        } catch (\Exception $e) {
            $this->toolBox()->i18nLog()->error('Error generando PDF: ' . $e->getMessage());
            return false;
        }
    }

    protected function loadDianStatusData($view)
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
        if (!$config) {
            $errors[] = 'No hay configuración DIAN activa';
        } else {
            $resolution = $config->getActiveResolution();
            if (!$resolution || !$resolution->isActive()) {
                $errors[] = 'La resolución DIAN no está vigente';
            }
            
            $numeroFactura = intval(str_replace($resolution->prefijo, '', $factura->codigo));
            if (!$resolution->isInvoiceNumberInRange($numeroFactura)) {
                $errors[] = 'El número de factura está fuera del rango autorizado';
            }
        }

        // Validaciones de advertencia
        if (strlen($factura->observaciones) > 500) {
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
     * Genera PDF con información DIAN
     */
    private function generateDianPdf($factura, $dianInvoice): string
    {
        // Implementación simplificada - en producción usar TCPDF o similar
        $html = $this->generateDianPdfHtml($factura, $dianInvoice);
        
        // Crear PDF con TCPDF
        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        
        // Configurar documento
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor($factura->nombrecliente);
        $pdf->SetTitle('Factura Electrónica ' . $factura->codigo);
        $pdf->SetSubject('Factura Electrónica DIAN');
        $pdf->SetKeywords('DIAN, Factura Electrónica, Colombia');
        
        // Configurar márgenes
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetHeaderMargin(10);
        $pdf->SetFooterMargin(10);
        
        // Añadir página
        $pdf->AddPage();
        
        // Escribir HTML
        $pdf->writeHTML($html, true, false, true, false, '');
        
        // Generar PDF
        return $pdf->Output('', 'S');
    }

    /**
     * Genera HTML para el PDF DIAN
     */
    private function generateDianPdfHtml($factura, $dianInvoice): string
    {
        $config = DianConfig::getActiveConfig();
        $resolution = $config ? $config->getActiveResolution() : null;
        
        $html = "<!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Factura Electrónica DIAN - {$factura->codigo}</title>
            <style>
                body { font-family: Arial, sans-serif; font-size: 12px; margin: 20px; }
                .header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
                .company-info { margin: 20px 0; }
                .invoice-details { margin: 20px 0; }
                .items-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                .items-table th, .items-table td { border: 1px solid #ccc; padding: 8px; text-align: left; }
                .items-table th { background-color: #f5f5f5; font-weight: bold; }
                .totals { margin-top: 20px; text-align: right; }
                .dian-info { margin-top: 30px; border: 2px solid #000; padding: 15px; background-color: #f9f9f9; }
                .qr-code { text-align: center; margin: 20px 0; }
                .footer { margin-top: 30px; font-size: 10px; text-align: center; color: #666; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>FACTURA ELECTRÓNICA DE VENTA</h1>
                <h2>" . htmlspecialchars($config->razon_social) . "</h2>
                <p><strong>NIT:</strong> {$config->nit}</p>
                <p><strong>Resolución DIAN:</strong> {$resolution->numero}</p>
                <p><strong>Rango autorizado:</strong> {$resolution->rango_desde} - {$resolution->rango_hasta}</p>
            </div>";
            
        // ... (resto del HTML como en el código original)
        
        return $html;
    }
}