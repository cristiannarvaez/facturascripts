<?php
namespace FacturaScripts\Plugins\DianFactCol;

use FacturaScripts\Core\Base\InitClass;
use FacturaScripts\Core\Base\ToolBox;

/**
 * Inicializador del plugin DianFactCol
 */
class Init extends InitClass
{
    public function init()
    {
        $this->loadExtension(new Extension\MenuDian());
        $this->loadExtension(new Extension\SendDianButton());
    }

    public function update()
    {
        $this->createTables();
        $this->insertDefaultData();
    }

    private function createTables()
    {
        $toolBox = new ToolBox();
        $dataBase = $toolBox->dataBase();

        // Crear tabla dian_config
        $sql = "CREATE TABLE IF NOT EXISTS " . $dataBase->tablePrefix() . "dian_config (
            id INT NOT NULL AUTO_INCREMENT,
            ambiente VARCHAR(20) NOT NULL DEFAULT 'pruebas',
            nit VARCHAR(20) NOT NULL DEFAULT '',
            razon_social VARCHAR(255) NOT NULL DEFAULT '',
            prefijo_factura VARCHAR(10) DEFAULT NULL,
            certificado_path VARCHAR(255) DEFAULT NULL,
            certificado_password VARCHAR(255) DEFAULT NULL,
            pin_software VARCHAR(255) DEFAULT NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            INDEX idx_activo (activo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $dataBase->exec($sql);

        // Crear tabla dian_resolutions
        $sql = "CREATE TABLE IF NOT EXISTS " . $dataBase->tablePrefix() . "dian_resolutions (
            id INT NOT NULL AUTO_INCREMENT,
            idconfig INT NOT NULL,
            numero VARCHAR(50) NOT NULL,
            prefijo VARCHAR(10) NOT NULL DEFAULT '',
            fecha_inicio DATE NOT NULL,
            fecha_fin DATE NOT NULL,
            rango_desde BIGINT NOT NULL,
            rango_hasta BIGINT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            FOREIGN KEY (idconfig) REFERENCES " . $dataBase->tablePrefix() . "dian_config(id) ON DELETE CASCADE,
            INDEX idx_config_fecha (idconfig, fecha_inicio, fecha_fin),
            INDEX idx_numero (numero)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $dataBase->exec($sql);

        // Crear tabla dian_invoices
        $sql = "CREATE TABLE IF NOT EXISTS " . $dataBase->tablePrefix() . "dian_invoices (
            id INT NOT NULL AUTO_INCREMENT,
            idfactura INT NOT NULL,
            cude VARCHAR(255) DEFAULT NULL,
            cufe VARCHAR(255) NOT NULL DEFAULT '',
            xml LONGTEXT DEFAULT NULL,
            xml_signed LONGTEXT DEFAULT NULL,
            qr_code TEXT DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
            fecha_envio DATETIME DEFAULT NULL,
            fecha_respuesta DATETIME DEFAULT NULL,
            respuesta_dian LONGTEXT DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            intentos INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_factura (idfactura),
            INDEX idx_status (status),
            INDEX idx_fecha_envio (fecha_envio),
            INDEX idx_cufe (cufe(50))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $dataBase->exec($sql);

        // Crear tabla dian_logs
        $sql = "CREATE TABLE IF NOT EXISTS " . $dataBase->tablePrefix() . "dian_logs (
            id INT NOT NULL AUTO_INCREMENT,
            fecha DATETIME NOT NULL,
            tipo VARCHAR(20) NOT NULL,
            mensaje TEXT NOT NULL,
            detalles LONGTEXT DEFAULT NULL,
            PRIMARY KEY (id),
            INDEX idx_fecha (fecha),
            INDEX idx_tipo (tipo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $dataBase->exec($sql);

        // Crear tabla dian_summary
        $sql = "CREATE TABLE IF NOT EXISTS " . $dataBase->tablePrefix() . "dian_summary (
            id INT NOT NULL AUTO_INCREMENT,
            fecha DATE NOT NULL,
            enviadas INT NOT NULL DEFAULT 0,
            aceptadas INT NOT NULL DEFAULT 0,
            rechazadas INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uk_fecha (fecha)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $dataBase->exec($sql);
    }

    private function insertDefaultData()
    {
        $toolBox = new ToolBox();
        $dataBase = $toolBox->dataBase();

        // Insertar configuración por defecto si no existe
        $sql = "INSERT IGNORE INTO " . $dataBase->tablePrefix() . "dian_config 
                (id, ambiente, nit, razon_social, activo, created_at, updated_at) 
                VALUES (1, 'pruebas', '', '', 1, NOW(), NOW())";

        $dataBase->exec($sql);
    }

    public function uninstall()
    {
        $toolBox = new ToolBox();
        $dataBase = $toolBox->dataBase();

        // Eliminar tablas en orden correcto (respetando foreign keys)
        $tables = [
            'dian_summary',
            'dian_logs', 
            'dian_invoices',
            'dian_resolutions',
            'dian_config'
        ];

        foreach ($tables as $table) {
            $sql = "DROP TABLE IF EXISTS " . $dataBase->tablePrefix() . $table;
            $dataBase->exec($sql);
        }
    }
}