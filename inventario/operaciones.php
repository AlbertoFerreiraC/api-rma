<?php
declare(strict_types=1);
require_once __DIR__.'/remisiones_auth.php';rmaPermitir([1,2,5]);
require_once __DIR__.'/../db_config.php';require_once __DIR__.'/operaciones_service.php';
$rma=rmaConexion('rma');$inv=rmaConexion('inventario');
$action=(string)($_GET['action']??'listar');$rol=rmaRol();$actor=rmaUsuarioId();
try {
    if ($action==='auxiliares') {
        $s=$rma->prepare('SELECT id,numero_caso,equipo,id_tecnico FROM casos WHERE fecha_cierre IS NULL'.($rol===1?' AND id_tecnico=?':'').' ORDER BY id DESC');$s->execute($rol===1?[$actor]:[]);
        $productos=$inv->query('SELECT id,codigo,nombre,stock_actual,stock_rma,stock_actual-stock_rma AS cantidad FROM productos ORDER BY nombre')->fetchAll();
        echo json_encode(['status'=>'success','casos'=>$s->fetchAll(),'central'=>$productos,'insumos'=>$rma->query('SELECT id,codigo_sku,nombre,cantidad FROM stock ORDER BY nombre')->fetchAll()]);exit;
    }
    if ($action==='listar') {
        $s=$inv->prepare('SELECT o.*,p.nombre,p.codigo FROM operaciones_rma o JOIN productos p ON p.id=o.producto_id'.($rol===1?' WHERE o.id_tecnico=?':'').' ORDER BY o.id DESC');$s->execute($rol===1?[$actor]:[]);$central=$s->fetchAll();
        $s=$rma->prepare('SELECT b.*,s.nombre,s.codigo_sku,c.numero_caso,u.nombre AS usuario_nombre FROM bajas_stock b JOIN stock s ON s.id=b.id_stock LEFT JOIN casos c ON c.id=b.id_caso JOIN usuarios u ON u.id=b.id_usuario'.($rol===1?' WHERE b.id_usuario=?':'').' ORDER BY b.id DESC');$s->execute($rol===1?[$actor]:[]);
        $users=$rma->query('SELECT id,nombre FROM usuarios')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach($central as &$o) { $o['usuario_nombre']=$users[$o['id_usuario']]??'Usuario #'.$o['id_usuario'];$o['tecnico_nombre']=$users[$o['id_tecnico']]??''; }unset($o);
        $historial=[];
        if($rol!==1) {
            $historial=$inv->query("SELECT m.id,m.created_at AS fecha,m.tipo_movimiento,m.cantidad,m.stock_anterior,m.stock_nuevo,m.referencia AS numero_caso,m.observacion AS motivo,m.usuario_id,p.nombre,p.codigo FROM movimientos_inventario m JOIN productos p ON p.id=m.producto_id WHERE m.tipo_movimiento IN ('ENTREGA_TECNICO','INGRESO_RMA','SALIDA_RMA') AND LEFT(COALESCE(m.observacion,''),14) NOT IN ('[CENTRAL_RMA #','[RMA_CENTRAL #') ORDER BY m.id DESC")->fetchAll();
            foreach($historial as &$h){$h['registro']='H-'.$h['id'];$h['tipo']=$h['tipo_movimiento']==='SALIDA_RMA'?'RMA_CENTRAL':'CENTRAL_RMA';$h['usuario_nombre']=$users[$h['usuario_id']]??'Usuario #'.$h['usuario_id'];}unset($h);
        }
        echo json_encode(['status'=>'success','central'=>$central,'historial'=>$historial,'bajas'=>$s->fetchAll()]);exit;
    }
    if ($action==='guardar') {
        if ($_SERVER['REQUEST_METHOD']!=='POST') {http_response_code(405);exit;}
        rmaCsrfValidar();$d=json_decode(file_get_contents('php://input'),true);
        if (!is_array($d)) throw new InvalidArgumentException('Datos inválidos.');
        $fuente=(string)($d['fuente']??'');
        if (!in_array($fuente,['central','insumos'],true)) throw new InvalidArgumentException('Seleccioná el inventario.');
        $tipo=(string)($d['tipo']??'');
        if ($rol===1 && !in_array($tipo,['BAJA_RMA','BAJA_INSUMO'],true)) {http_response_code(403);exit;}
        $rma->beginTransaction();
        $caso=rmaCasoOperacion($rma,$d['id_caso']??0,$rol,$actor,$tipo==='CENTRAL_RMA');
        if ($fuente==='central') {
            $inv->beginTransaction();$id=rmaOperacionCentral($inv,$d,$caso,$actor,$rol);$inv->commit();
        } else {
            if ($tipo!=='BAJA_INSUMO') throw new InvalidArgumentException('Operación no válida para insumos.');
            $id=rmaBajaInsumo($rma,$d,$caso,$actor);
        }
        $rma->commit();echo json_encode(['status'=>'success','id'=>$id,'message'=>'Operación registrada con existencias, motivo y responsable.']);exit;
    }
    http_response_code(404);echo json_encode(['status'=>'error','message'=>'Acción inválida.']);
}catch(Throwable $e) {if($inv->inTransaction())$inv->rollBack();if($rma->inTransaction())$rma->rollBack();rmaEnviarError($e);}
