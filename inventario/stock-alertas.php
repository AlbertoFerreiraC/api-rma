<?php
declare(strict_types=1);
require_once __DIR__.'/remisiones_auth.php';rmaPermitir([2,5]);
require_once __DIR__.'/../db_config.php';require_once __DIR__.'/operaciones_service.php';
$action=(string)($_GET['action']??'');$fuente=(string)($_GET['fuente']??'central');
try {
    if(!in_array($fuente,['central','insumos'],true))throw new InvalidArgumentException('Inventario inválido.');
    $p=rmaConexion($fuente==='central'?'inventario':'rma');
    if($action==='consultar_alertas') {
        if($fuente==='central')$rows=$p->query("SELECT id,codigo AS codigo_sku,nombre,categoria AS categoria_nombre,'' AS proveedor_nombre,stock_actual-stock_rma AS cantidad,stock_minimo FROM productos WHERE stock_actual-stock_rma<=stock_minimo ORDER BY cantidad,nombre")->fetchAll();
        else $rows=$p->query('SELECT s.id,s.codigo_sku,s.nombre,c.nombre AS categoria_nombre,p.nombre AS proveedor_nombre,s.cantidad,s.stock_minimo FROM stock s LEFT JOIN categorias_stock c ON c.id=s.id_categoria LEFT JOIN proveedores p ON p.id=s.id_proveedor_habitual WHERE s.cantidad<=s.stock_minimo ORDER BY s.cantidad,s.nombre')->fetchAll();
        $k=['agotados'=>0,'criticos'=>0,'proveedores_impactados'=>0,'unidades_reponer'=>0];$proveedores=[];
        foreach($rows as &$r){$r['fuente']=$fuente;$r['unidades_reponer']=max(1,(int)$r['stock_minimo']-(int)$r['cantidad']+1);$k['unidades_reponer']+=$r['unidades_reponer'];$k[(int)$r['cantidad']<=0?'agotados':'criticos']++;if($r['proveedor_nombre'])$proveedores[$r['proveedor_nombre']]=true;}unset($r);
        $k['proveedores_impactados']=count($proveedores);echo json_encode(['status'=>'success','alertas'=>$rows,'kpis'=>$k]);exit;
    }
    if($action==='reabastecer') {
        if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
        rmaCsrfValidar();$d=json_decode(file_get_contents('php://input'),true);if(!is_array($d))throw new InvalidArgumentException('Datos inválidos.');
        $id=rmaInvEntero($d['id']??null,'Ítem');$n=rmaCantidadPositiva($d['cantidad_ingreso']??null);$motivo=rmaMotivo($d['motivo']??null);
        $p->beginTransaction();
        if($fuente==='central') {
            $s=$p->prepare('SELECT * FROM productos WHERE id=? FOR UPDATE');$s->execute([$id]);$row=$s->fetch();if(!$row)throw new DomainException('El producto no existe.');
            $a=(int)$row['stock_actual'];$nuevo=rmaInvEntero($a+$n,'Stock');
            $s=$p->prepare('UPDATE productos SET stock_actual=?,estado_alerta=?,updated_at=NOW() WHERE id=?');$s->execute([$nuevo,rmaInvEstado($nuevo,(int)$row['stock_rma'],(int)$row['stock_minimo']),$id]);
            $s=$p->prepare("INSERT INTO movimientos_inventario (producto_id,tipo_movimiento,cantidad,stock_anterior,stock_nuevo,usuario_id,observacion) VALUES (?,'REPOSICION',?,?,?,?,?)");$s->execute([$id,$n,$a,$nuevo,rmaUsuarioId(),$motivo]);
        }else {
            $s=$p->prepare('SELECT cantidad FROM stock WHERE id=? FOR UPDATE');$s->execute([$id]);$a=$s->fetchColumn();if($a===false)throw new DomainException('El insumo no existe.');$nuevo=rmaInvEntero((int)$a+$n,'Stock');
            $s=$p->prepare('UPDATE stock SET cantidad=?,updated_at=NOW() WHERE id=?');$s->execute([$nuevo,$id]);
            $s=$p->prepare("INSERT INTO movimientos_stock (id_stock,tipo_movimiento,cantidad,stock_anterior,stock_nuevo,id_usuario,motivo) VALUES (?,'ENTRADA',?,?,?,?,?)");$s->execute([$id,$n,$a,$nuevo,rmaUsuarioId(),mb_substr($motivo,0,255)]);
        }
        $p->commit();echo json_encode(['status'=>'success','message'=>'Reposición registrada correctamente.']);exit;
    }
    http_response_code(404);echo json_encode(['status'=>'error','message'=>'Acción inválida.']);
}catch(Throwable $e){if(isset($p)&&$p->inTransaction())$p->rollBack();rmaEnviarError($e);}
