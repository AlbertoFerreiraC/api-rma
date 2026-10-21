<?php
declare(strict_types=1);
require_once __DIR__.'/remisiones_auth.php';
rmaPermitir([2,5]);
require_once __DIR__.'/../db_inventario.php';
require_once __DIR__.'/inventario_service.php';
$action=(string)($_GET['action']??'listar');
try {
    if ($action==='listar') {
        $items=$pdo_inv->query('SELECT * FROM productos ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status'=>'success','stock'=>array_map('rmaInvPublicar',$items)]); exit;
    }
    if ($action==='detalle') {
        $id=rmaInvEntero($_GET['id']??null,'ID');
        $s=$pdo_inv->prepare('SELECT * FROM productos WHERE id=?'); $s->execute([$id]); $p=$s->fetch(PDO::FETCH_ASSOC);
        if (!$p) { http_response_code(404); echo json_encode(['status'=>'error','message'=>'El producto no existe.']); exit; }
        echo json_encode(['status'=>'success','producto'=>rmaInvPublicar($p)]); exit;
    }
    if (in_array($action,['actualizar','guardar'],true)) {
        if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); echo json_encode(['status'=>'error','message'=>'Se requiere POST.']); exit; }
        rmaCsrfValidar();
        $datos=json_decode(file_get_contents('php://input'),true);
        if (!is_array($datos)) throw new InvalidArgumentException('Datos inválidos.');
        $pdo_inv->beginTransaction();
        if ($action==='actualizar') $p=rmaInvActualizar($pdo_inv,$datos,rmaUsuarioId());
        else {
            $nuevo=rmaInvValidar($datos);
            $s=$pdo_inv->prepare('INSERT INTO productos (codigo,nombre,categoria,marca,stock_actual,stock_rma,stock_minimo,estado_alerta) VALUES (?,?,?,?,?,?,?,?)');
            $s->execute([$nuevo['codigo'],$nuevo['nombre'],$nuevo['categoria'],$nuevo['marca'],$nuevo['stock_actual'],$nuevo['stock_rma'],$nuevo['stock_minimo'],
                rmaInvEstado($nuevo['stock_actual'],$nuevo['stock_rma'],$nuevo['stock_minimo'])]);
            $id=(int)$pdo_inv->lastInsertId();
            $s=$pdo_inv->prepare("INSERT INTO movimientos_inventario (producto_id,tipo_movimiento,cantidad,stock_anterior,stock_nuevo,usuario_id,referencia,observacion) VALUES (?,'AJUSTE',?,0,?,?,'Alta de producto','Stock inicial de inventario')");
            $s->execute([$id,$nuevo['stock_actual'],$nuevo['stock_actual'],rmaUsuarioId()]);
            $s=$pdo_inv->prepare('SELECT * FROM productos WHERE id=?'); $s->execute([$id]); $p=rmaInvPublicar($s->fetch(PDO::FETCH_ASSOC));
        }
        $pdo_inv->commit();
        echo json_encode(['status'=>'success','message'=>'Inventario guardado correctamente.','producto'=>$p]); exit;
    }
    http_response_code(404); echo json_encode(['status'=>'error','message'=>'Acción no reconocida.']);
} catch(Throwable $e) {
    if ($pdo_inv->inTransaction()) $pdo_inv->rollBack();
    if ($e instanceof UnexpectedValueException) { http_response_code(409); echo json_encode(['status'=>'error','message'=>$e->getMessage()]); }
    elseif ($e instanceof PDOException && ($e->errorInfo[1]??null)===1062) { http_response_code(422); echo json_encode(['status'=>'error','message'=>'Ya existe un producto con ese código.']); }
    else rmaEnviarError($e);
}
