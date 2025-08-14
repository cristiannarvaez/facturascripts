<?php
namespace FacturaScripts\Plugins\DianFactCol\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;

class DianReports extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'reports';
        $data['title'] = 'Reportes DIAN';
        $data['icon'] = 'fas fa-chart-bar';
        return $data;
    }

    protected function createViews()
    {
        // Vista de facturas DIAN por estado
        $this->addView('ListDianInvoice', 'DianInvoice', 'Facturas por Estado', 'fas fa-file-invoice');
        
        // Vista de logs de envíos
        $this->addView('ListDianLogs', 'DianLog', 'Registros de Envío', 'fas fa-history');
        
        // Vista de resúmenes DIAN
        $this->addView('ListDianSummary', 'DianSummary', 'Resúmenes DIAN', 'fas fa-file-alt');
    }
}