<?php
declare(strict_types=1);
require_once __DIR__.'/remisiones_auth.php';
rmaPermitir([2,5]);
require_once __DIR__.'/../db_inventario.php';
require_once __DIR__.'/inventario_service.php';
$action=(string)($_GET['action']??'');
try {
    if ($action==='consultar_alertas') {
        $rows=array_map('rmaInvPublicar',$pdo_inv->query('SELECT * FROM productos ORDER BY stock_actual-stock_rma,nombre')->fetchAll(PDO::FETCH_ASSOC));
        $k=['total_productos'=>count($rows),'unidades_totales'=>0,'en_proceso_rma'=>0,'sin_stock'=>0,'stock_bajo'=>0,'stock_ok'=>0];
        $alertas=[];
        foreach ($rows as $p) {
            $k['unidades_totales']+=$p['stock_actual']; $k['en_proceso_rma']+=$p['stock_rma'];
            if ($p['estado_alerta']==='SIN STOCK') $k['sin_stock']++;
            elseif ($p['estado_alerta']==='ALERTA - STOCK BAJO') $k['stock_bajo']++;
            else $k['stock_ok']++;
            if ($p['estado_alerta']!=='OK') $alertas[]=$p;
        }
        echo json_encode(['status'=>'success','alertas'=>$alertas,'kpis'=>$k]); exit;
    }
    if ($action==='reabastecer') {
        if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); echo json_encode(['status'=>'error','message'=>'Se requiere POST.']); exit; }
        rmaCsrfValidar();
        $d=json_decode(file_get_contents('php://input'),true);
        if (!is_array($d)) throw new InvalidArgumentException('Datos inválidos.');
        $id=rmaInvEntero($d['id']??null,'ID'); $cantidad=rmaInvEntero($d['cantidad_ingreso']??null,'Cantidad');
        $motivo=$d['motivo']??'Reposición de stock.';
        if ($cantidad<1 || !is_string($motivo) || trim($motivo)==='' || mb_strlen($motivo)>2000) throw new InvalidArgumentException('Indicá una cantidad positiva y el motivo de reposición.');
        $pdo_inv->beginTransaction();
        $s=$pdo_inv->prepare('SELECT * FROM productos WHERE id=? FOR UPDATE'); $s->execute([$id]); $p=$s->fetch(PDO::FETCH_ASSOC);
        if (!$p) throw new DomainException('El producto no existe.');
        $nuevo=rmaInvEntero((int)$p['stock_actual']+$cantidad,'Stock');
        $s=$pdo_inv->prepare('UPDATE productos SET stock_actual=?,estado_alerta=?,updated_at=NOW() WHERE id=?');
        $s->execute([$nuevo,rmaInvEstado($nuevo,(int)$p['stock_rma'],(int)$p['stock_minimo']),$id]);
        $s=$pdo_inv->prepare("INSERT INTO movimientos_inventario (producto_id,tipo_movimiento,cantidad,stock_anterior,stock_nuevo,usuario_id,observacion) VALUES (?,'REPOSICION',?,?,?,?,?)");
        $s->execute([$id,$cantidad,(int)$p['stock_actual'],$nuevo,rmaUsuarioId(),trim($motivo)]);
        $pdo_inv->commit(); echo json_encode(['status'=>'success','message'=>'Reposición registrada correctamente.']); exit;
    }
    http_response_code(404); echo json_encode(['status'=>'error','message'=>'Acción no reconocida.']);
} catch(Throwable $e) {
    if ($pdo_inv->inTransaction()) $pdo_inv->rollBack();
    rmaEnviarError($e);
}
