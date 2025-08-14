<?php
namespace FacturaScripts\Plugins\DianFactCol\Extension;

use Closure;

/**
 * Extensión para agregar elementos al menú de FacturaScripts
 */
class MenuDian
{
    public function menu(): Closure
    {
        return function (&$menu) {
            // Menú de administración - Configuración DIAN
            $menu[] = [
                'menu' => 'admin',
                'name' => 'dian-config',
                'title' => 'Configuración DIAN',
                'url' => 'AdminDianConfig',
                'icon' => 'fas fa-cog',
                'order' => 100
            ];

            // Menú de ventas - Facturas DIAN
            $menu[] = [
                'menu' => 'sales',
                'name' => 'dian-invoices', 
                'title' => 'Facturas DIAN',
                'url' => 'ListDianInvoice',
                'icon' => 'fas fa-file-invoice',
                'order' => 50
            ];

            // Menú de reportes - Reportes DIAN
            $menu[] = [
                'menu' => 'reports',
                'name' => 'dian-reports',
                'title' => 'Reportes DIAN', 
                'url' => 'ReportDian',
                'icon' => 'fas fa-chart-bar',
                'order' => 75
            ];
        };
    }
}