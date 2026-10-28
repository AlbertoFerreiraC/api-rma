<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__.'/../api-rma/db_config.php';
foreach (['inventario'=>'03_correcciones_inventario.sql','rma'=>'04_bajas_insumos.sql'] as $db=>$file) {
    rmaConexion($db)->exec(file_get_contents(__DIR__.'/../sql/'.$file));
    echo "$db: correcciones instaladas.\n";
}
rmaConexion('rma')->exec(file_get_contents(__DIR__.'/../sql/05_evidencias_caso.sql'));
echo "rma: evidencias instaladas.\n";
