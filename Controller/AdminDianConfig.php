<?php
namespace FacturaScripts\Plugins\DianFactCol\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Plugins\DianFactCol\Model\DianConfig;
use FacturaScripts\Plugins\DianFactCol\Model\DianResolution;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;

class AdminDianConfig extends EditController
{
    public function getModelClassName(): string
    {
        return DianConfig::class;
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'Configuración DIAN';
        $data['icon'] = 'fas fa-cog';
        $data['showonmenu'] = true;
        return $data;
    }

    protected function createViews()
    {
        parent::createViews();
        $this->setTabsPosition('bottom');

        // Vista principal - corregir el nombre de la vista
        $viewName = $this->getMainViewName();
        $this->addEditView($viewName, DianConfig::class, 'config-dian', 'Configuración');

        // Vista de resoluciones
        $this->addListView('ListDianResolution', DianResolution::class, 'Resoluciones', 'fas fa-file-signature');
        $this->views['ListDianResolution']->addOrderBy(['fecha_inicio'], 'Fecha Inicio', 2);
        $this->views['ListDianResolution']->addSearchFields(['numero', 'prefijo']);

        // Vista de certificado (HTML)
        $this->addHtmlView('CertificateInfo', 'CertificateInfo', DianConfig::class, 'Certificado', 'fas fa-certificate');
    }

    /**
     * Obtiene el nombre de la vista principal
     */
    protected function getMainViewName(): string
    {
        return 'Edit' . $this->getModelClassName();
    }

    /**
     * Sobre-escribimos loadData para evitar modelos NULL
     */
    protected function loadData($viewName, $view)
    {
        $mainViewName = $this->getMainViewName();
        
        switch ($viewName) {
            case $mainViewName:
                // Forzamos ID 1 y lo creamos si no existe
                $view->loadData('1');
                if (!$view->model->exists()) {
                    $view->model->clear();
                    $view->model->id = 1;
                    // Valores por defecto
                    $view->model->ambiente = 'pruebas';
                    $view->model->activo = true;
                    $view->model->created_at = date('Y-m-d H:i:s');
                    $view->model->save();
                }
                break;

            case 'ListDianResolution':
                // Usamos el modelo ya cargado en la vista principal
                $config = $this->views[$mainViewName]->model;
                if ($config && $config->id) {
                    $where = [new DataBaseWhere('idconfig', $config->id)];
                    $view->loadData('', $where);
                }
                break;

            case 'CertificateInfo':
                $config = DianConfig::getActiveConfig();
                if (!$config) {
                    $config = new DianConfig();
                    $config->id = 1;
                }
                $view->model = $config;
                
                // Verificar si el certificado existe
                if ($config->certificado_path) {
                    $certPath = FS_FOLDER . '/MyFiles/' . $config->certificado_path;
                    $view->certificateExists = file_exists($certPath);
                } else {
                    $view->certificateExists = false;
                }
                break;

            default:
                parent::loadData($viewName, $view);
        }
    }

    protected function execPreviousAction($action)
    {
        switch ($action) {
            case 'test-connection':
                return $this->testDianConnection();
                
            case 'validate-certificate':
                return $this->validateCertificate();
                
            default:
                return parent::execPreviousAction($action);
        }
    }

    private function testDianConnection(): bool
    {
        $config = DianConfig::getActiveConfig();
        if (!$config) {
            $this->toolBox()->i18nLog()->error('No hay configuración DIAN activa');
            return false;
        }

        try {
            $ws = new \FacturaScripts\Plugins\DianFactCol\Lib\DianWebService($config);
            if ($ws->testConnection()) {
                $this->toolBox()->i18nLog()->info('✅ Conexión exitosa con DIAN');
                return true;
            } else {
                $this->toolBox()->i18nLog()->error('❌ No se pudo conectar con DIAN');
                return false;
            }
        } catch (\Exception $e) {
            $this->toolBox()->i18nLog()->error('Error de conexión: ' . $e->getMessage());
            return false;
        }
    }

    private function validateCertificate(): bool
    {
        $config = DianConfig::getActiveConfig();
        if (!$config) {
            $this->toolBox()->i18nLog()->error('No hay configuración DIAN activa');
            return false;
        }

        if ($config->validateCertificate()) {
            $this->toolBox()->i18nLog()->info('✅ Certificado válido');
            
            // Mostrar información del certificado
            $certInfo = $config->getCertificateInfo();
            if ($certInfo) {
                $this->toolBox()->i18nLog()->info('Válido hasta: ' . $certInfo['validTo']);
            }
            return true;
        } else {
            $this->toolBox()->i18nLog()->error('❌ Certificado inválido o no encontrado');
            return false;
        }
    }
}