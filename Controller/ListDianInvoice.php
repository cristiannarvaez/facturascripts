<?php
namespace FacturaScripts\Plugins\DianFactCol\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Plugins\DianFactCol\Model\DianInvoice;
use FacturaScripts\Core\Model\FacturaCliente;

class ListDianInvoice extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'Facturas DIAN';
        $data['icon'] = 'fas fa-file-invoice';
        return $data;
    }

    protected function createViews()
    {
        $this->createViewInvoices();
    }

    protected function createViewInvoices(string $viewName = 'ListDianInvoice')
    {
        $this->addView($viewName, DianInvoice::class, 'Facturas DIAN', 'fas fa-file-invoice');
        
        // Ordenación
        $this->addOrderBy($viewName, ['fecha_envio'], 'Fecha envío', 2);
        $this->addOrderBy($viewName, ['idfactura'], 'Factura');
        $this->addOrderBy($viewName, ['status'], 'Estado');
        $this->addOrderBy($viewName, ['created_at'], 'Fecha creación');

        // Filtros
        $this->addFilterSelect($viewName, 'status', 'Estado', 'status', [
            '' => '-- Todos --',
            'PENDIENTE' => 'Pendiente',
            'PROCESANDO' => 'Procesando', 
            'ENVIADO' => 'Enviado',
            'ACEPTADO' => 'Aceptado',
            'RECHAZADO' => 'Rechazado',
            'ERROR' => 'Error'
        ]);

        $this->addFilterDatePicker($viewName, 'fecha_desde', 'Desde', 'fecha_envio', '>=');
        $this->addFilterDatePicker($viewName, 'fecha_hasta', 'Hasta', 'fecha_envio', '<=');
        $this->addFilterNumber($viewName, 'idfactura', 'ID Factura', 'idfactura');
        $this->addSearchFields($viewName, ['cufe', 'cude', 'error_message']);

        // Configuraciones
        $this->setSettings($viewName, 'btnNew', false);
        $this->setSettings($viewName, 'btnDelete', false);
        
        // Botones
        $this->addButton($viewName, [
            'action' => 'resend-selected',
            'confirm' => true,
            'icon' => 'fas fa-paper-plane',
            'label' => 'Reenviar seleccionados',
            'type' => 'action'
        ]);

        $this->addButton($viewName, [
            'action' => 'download-xml-selected', 
            'icon' => 'fas fa-download',
            'label' => 'Descargar XML',
            'type' => 'action'
        ]);
    }

    protected function execPreviousAction($action)
    {
        switch ($action) {
            case 'resend-selected':
                return $this->resendSelectedInvoices();
                
            case 'download-xml-selected':
                return $this->downloadXmlSelected();
                
            default:
                return parent::execPreviousAction($action);
        }
    }

    private function resendSelectedInvoices(): bool
    {
        $codes = $this->request->request->get('code', []);
        if (empty($codes)) {
            $this->toolBox()->i18nLog()->warning('No hay facturas seleccionadas');
            return false;
        }

        $count = 0;
        $errors = 0;
        
        foreach ((array)$codes as $code) {
            try {
                $dianInvoice = new DianInvoice();
                if ($dianInvoice->loadFromCode($code)) {
                    $factura = new FacturaCliente();
                    if ($factura->loadFromCode($dianInvoice->idfactura)) {
                        $result = $dianInvoice->send($factura);
                        if ($result['success']) {
                            $count++;
                        } else {
                            $errors++;
                            $this->toolBox()->i18nLog()->error('Error reenviando factura ' . $factura->codigo . ': ' . $result['message']);
                        }
                    }
                }
            } catch (\Exception $e) {
                $errors++;
                $this->toolBox()->i18nLog()->error('Error procesando factura: ' . $e->getMessage());
            }
        }

        if ($count > 0) {
            $this->toolBox()->i18nLog()->info("✅ Reenviadas {$count} facturas correctamente");
        }
        
        if ($errors > 0) {
            $this->toolBox()->i18nLog()->warning("⚠️ {$errors} facturas con errores");
        }

        return $count > 0;
    }

    private function downloadXmlSelected(): bool
    {
        $codes = $this->request->request->get('code', []);
        if (empty($codes)) {
            $this->toolBox()->i18nLog()->warning('No hay facturas seleccionadas');
            return false;
        }

        if (count($codes) === 1) {
            return $this->downloadSingleXml($codes[0]);
        }
        
        return $this->downloadMultipleXml($codes);
    }

    private function downloadSingleXml(string $code): bool
    {
        $dianInvoice = new DianInvoice();
        if (!$dianInvoice->loadFromCode($code)) {
            $this->toolBox()->i18nLog()->error('Factura DIAN no encontrada');
            return false;
        }

        $xml = $dianInvoice->xml_signed ?: $dianInvoice->xml;
        if (!$xml) {
            $this->toolBox()->i18nLog()->error('XML no disponible para esta factura');
            return false;
        }

        $factura = new FacturaCliente();
        $factura->loadFromCode($dianInvoice->idfactura);
        $filename = "DIAN_{$factura->codigo}_{$dianInvoice->cufe}.xml";
        
        $this->sendFileResponse($xml, $filename, 'application/xml');
        return true;
    }

    private function downloadMultipleXml(array $codes): bool
    {
        if (!class_exists('ZipArchive')) {
            $this->toolBox()->i18nLog()->error('Extensión ZIP no disponible en el servidor');
            return false;
        }

        $zip = new \ZipArchive();
        $tempFile = tempnam(sys_get_temp_dir(), 'dian_xml_');
        
        if ($zip->open($tempFile, \ZipArchive::CREATE) !== true) {
            $this->toolBox()->i18nLog()->error('No se pudo crear el archivo ZIP');
            return false;
        }

        $addedFiles = 0;
        foreach ($codes as $code) {
            try {
                $dianInvoice = new DianInvoice();
                if ($dianInvoice->loadFromCode($code)) {
                    $xml = $dianInvoice->xml_signed ?: $dianInvoice->xml;
                    if ($xml) {
                        $factura = new FacturaCliente();
                        $factura->loadFromCode($dianInvoice->idfactura);
                        $filename = "DIAN_{$factura->codigo}_{$dianInvoice->cufe}.xml";
                        
                        if ($zip->addFromString($filename, $xml)) {
                            $addedFiles++;
                        }
                    }
                }
            } catch (\Exception $e) {
                // Continuar con el siguiente archivo
                continue;
            }
        }
        
        $zip->close();

        if ($addedFiles === 0) {
            unlink($tempFile);
            $this->toolBox()->i18nLog()->error('No se encontraron XMLs válidos');
            return false;
        }

        $zipContent = file_get_contents($tempFile);
        unlink($tempFile);
        
        $filename = 'facturas_dian_' . date('Y-m-d_H-i-s') . '.zip';
        $this->sendFileResponse($zipContent, $filename, 'application/zip');
        
        return true;
    }

    /**
     * Envía un archivo como respuesta de descarga
     */
    private function sendFileResponse(string $content, string $filename, string $mimeType): void
    {
        // Limpiar cualquier salida previa
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Configurar headers
        header('Content-Type: ' . $mimeType . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Enviar contenido
        echo $content;
        exit;
    }
}