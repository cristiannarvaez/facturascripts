<?php
namespace FacturaScripts\Plugins\DianFactCol\Lib;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

class QrGenerator
{
    public static function generate(array $data): string
    {
        $qrData = implode("\n", [
            'NumFac=' . $data['codigo'],
            'FecFac=' . $data['fecha'],
            'HorFac=' . $data['hora'],
            'NitFac=' . $data['nit'],
            'DocAdq=' . $data['cliente'],
            'ValFac=' . number_format($data['neto'], 2, '.', ''),
            'ValIva=' . number_format($data['iva'], 2, '.', ''),
            'ValOtroIm=' . number_format($data['otros'], 2, '.', ''),
            'ValTolFac=' . number_format($data['total'], 2, '.', ''),
            'CUFE=' . $data['cufe']
        ]);

        return Builder::create()
            ->writer(new PngWriter())
            ->data($qrData)
            ->size(300)
            ->margin(10)
            ->build()
            ->getDataUri();
    }
}