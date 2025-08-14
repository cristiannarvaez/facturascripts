<?php
namespace FacturaScripts\Plugins\DianFactCol\Model;

use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;

class DianLog extends ModelClass
{
    use ModelTrait;

    public $id;
    public $fecha;
    public $tipo;
    public $mensaje;
    public $detalles;

    public static function tableName(): string
    {
        return 'dian_logs';
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }
}