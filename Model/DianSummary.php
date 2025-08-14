<?php
namespace FacturaScripts\Plugins\DianFactCol\Model;

use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;

class DianSummary extends ModelClass
{
    use ModelTrait;

    public $id;
    public $fecha;
    public $enviadas = 0;
    public $aceptadas = 0;
    public $rechazadas = 0;

    public static function tableName(): string
    {
        return 'dian_summary';
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }
}