<?php
namespace FacturaScripts\Plugins\DianFactCol\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Model\FacturaCliente;
use FacturaScripts\Plugins\DianFactCol\Model\DianInvoice;

class SendDianInvoice extends Controller
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['showonmenu'] = false;
        return $data;
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        $idfactura = $this->request->get('code');
        $factura = new FacturaCliente();
        if ($factura->loadFromCode($idfactura)) {
            $dian = new DianInvoice();
            $result = $dian->send($factura);
            $this->response->setContent(json_encode($result));
        } else {
            $this->response->setContent(json_encode(['success' => false, 'message' => 'Factura no encontrada']));
        }
    }
}