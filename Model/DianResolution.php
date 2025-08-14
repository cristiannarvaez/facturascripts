<?php
namespace FacturaScripts\Plugins\DianFactCol\Model;

use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;

class DianResolution extends ModelClass
{
    use ModelTrait;

    public $id;
    public $idconfig;
    public $numero;
    public $prefijo;
    public $fecha_inicio;
    public $fecha_fin;
    public $rango_desde;
    public $rango_hasta;
    public $created_at;
    public $updated_at;

    public static function tableName(): string
    {
        return 'dian_resolutions';
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public function isActive(): bool
    {
        $today = date('Y-m-d');
        return $this->fecha_inicio <= $today && $this->fecha_fin >= $today;
    }

    public function isInvoiceNumberInRange(int $number): bool
    {
        return $number >= $this->rango_desde && $number <= $this->rango_hasta;
    }
}