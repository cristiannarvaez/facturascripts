<?php
namespace FacturaScripts\Plugins\DianFactCol\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Plugins\DianFactCol\Model\DianInvoice;
use FacturaScripts\Plugins\DianFactCol\Model\DianLog;

class ReportDian extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'reports';
        $data['title'] = 'Reportes DIAN';
        $data['icon'] = 'fas fa-chart-bar';
        $data['showonmenu'] = true;
        return $data;
    }

    protected function createViews()
    {
        $this->createViewDianInvoices();
        $this->createViewDianLogs();
    }

    protected function createViewDianInvoices(string $viewName = 'ListDianInvoice')
    {
        $this->addView($viewName, DianInvoice::class, 'Facturas DIAN', 'fas fa-file-invoice');
        $this->addOrderBy($viewName, ['fecha_envio'], 'Fecha envío', 2);
        $this->addOrderBy($viewName, ['status'], 'Estado');
        $this->addFilterSelect($viewName, 'status', 'Estado', 'status', [
            '' => '-- Todos --',
            'PENDIENTE' => 'Pendiente',
            'ENVIADO' => 'Enviado',
            'ACEPTADO' => 'Aceptado',
            'RECHAZADO' => 'Rechazado',
            'ERROR' => 'Error'
        ]);
        $this->addFilterPeriod($viewName, 'fecha', 'Fecha', 'fecha_envio');
    }

    protected function createViewDianLogs(string $viewName = 'ListDianLog')
    {
        $this->addView($viewName, DianLog::class, 'Registros DIAN', 'fas fa-history');
        $this->addOrderBy($viewName, ['fecha'], 'Fecha', 2);
        $this->addOrderBy($viewName, ['tipo'], 'Tipo');
        $this->addFilterSelect($viewName, 'tipo', 'Tipo', 'tipo', [
            '' => '-- Todos --',
            'ENVIO' => 'Envío',
            'CONSULTA' => 'Consulta',
            'ERROR' => 'Error',
            'FIRMA' => 'Firma'
        ]);
        $this->addFilterPeriod($viewName, 'fecha', 'Fecha', 'fecha');
    }
}