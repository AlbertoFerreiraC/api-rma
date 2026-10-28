<?php
declare(strict_types=1);
require_once __DIR__.'/inventario_service.php';

function rmaCantidadPositiva($v): int {
    $n=rmaInvEntero($v,'Cantidad');
    if ($n<1 || $n>100000) throw new InvalidArgumentException('La cantidad debe estar entre 1 y 100000.');
    return $n;
}
function rmaMotivo($v): string {
    if (!is_string($v) || trim($v)==='' || mb_strlen($v)>2000) throw new InvalidArgumentException('Indicá un motivo de hasta 2000 caracteres.');
    return trim($v);
}
function rmaCasoOperacion(PDO $rma, $id, int $rol, int $actor, bool $abierto=true): ?array {
    $id=rmaInvEntero($id ?? 0,'Caso');
    if (!$id) {
        if ($rol===1) throw new InvalidArgumentException('Seleccioná uno de tus casos RMA.');
        return null;
    }
    $s=$rma->prepare('SELECT id,numero_caso,id_tecnico,fecha_cierre FROM casos WHERE id=? FOR UPDATE');
    $s->execute([$id]); $c=$s->fetch(PDO::FETCH_ASSOC);
    if (!$c || ($abierto && $c['fecha_cierre']!==null)) throw new DomainException('Seleccioná un caso RMA abierto.');
    if ($rol===1 && (int)$c['id_tecnico']!==$actor) throw new DomainException('Este caso no está asignado a tu usuario.');
    return $c;
}
function rmaSaldoCaso(PDO $inv, int $producto, int $caso): int {
    $s=$inv->prepare("SELECT COALESCE(SUM(CASE WHEN tipo='CENTRAL_RMA' THEN cantidad WHEN tipo IN ('RMA_CENTRAL','BAJA_RMA') THEN -cantidad ELSE 0 END),0) FROM operaciones_rma WHERE producto_id=? AND id_caso=?");
    $s->execute([$producto,$caso]); return (int)$s->fetchColumn();
}
/** Cambia ubicaciones del mismo inventario; un traslado conserva stock_actual. */
function rmaOperacionCentral(PDO $inv, array $d, ?array $caso, int $actor, int $rol): int {
    if (!$inv->inTransaction()) throw new LogicException('Se requiere transacción.');
    $tipo=(string)($d['tipo']??'');
    if (!in_array($tipo,['CENTRAL_RMA','RMA_CENTRAL','BAJA_CENTRAL','BAJA_RMA'],true)) throw new InvalidArgumentException('Operación inválida.');
    if ($rol===1 && $tipo!=='BAJA_RMA') throw new DomainException('El técnico puede dar de baja los ítems asignados a su RMA.');
    if ($tipo!=='BAJA_CENTRAL' && !$caso) throw new InvalidArgumentException('Seleccioná el RMA relacionado.');
    $n=rmaCantidadPositiva($d['cantidad']??null); $motivo=rmaMotivo($d['motivo']??null);
    if(str_starts_with($tipo,'BAJA')) {
        $causa=(string)($d['causa']??'');
        if(!in_array($causa,['PERDIDA_DEPOSITO','FALLA_TECNICA'],true))throw new InvalidArgumentException('Seleccioná la causa de la baja.');
        $motivo='['.$causa.'] '.$motivo;
    }
    $id=rmaInvEntero($d['id_stock']??null,'Producto');
    $s=$inv->prepare('SELECT * FROM productos WHERE id=? FOR UPDATE');$s->execute([$id]);$p=$s->fetch(PDO::FETCH_ASSOC);
    if (!$p) throw new DomainException('El producto no existe.');
    $a=(int)$p['stock_actual'];$r=(int)$p['stock_rma']; $nuevo=$a; $reserva=$r;
    if (in_array($tipo,['CENTRAL_RMA','BAJA_CENTRAL'],true) && $n>$a-$r) throw new DomainException('Cantidad superior al stock disponible en central.');
    if (in_array($tipo,['RMA_CENTRAL','BAJA_RMA'],true) && ($n>rmaSaldoCaso($inv,$id,(int)$caso['id']) || $n>$r)) throw new DomainException('La cantidad supera el saldo entregado a este RMA.');
    if ($tipo==='CENTRAL_RMA') $reserva+=$n;
    if ($tipo==='RMA_CENTRAL') $reserva-=$n;
    if ($tipo==='BAJA_CENTRAL') $nuevo-=$n;
    if ($tipo==='BAJA_RMA') { $nuevo-=$n; $reserva-=$n; }
    $s=$inv->prepare('UPDATE productos SET stock_actual=?,stock_rma=?,estado_alerta=?,updated_at=NOW() WHERE id=?');
    $s->execute([$nuevo,$reserva,rmaInvEstado($nuevo,$reserva,(int)$p['stock_minimo']),$id]);
    $s=$inv->prepare('INSERT INTO operaciones_rma (producto_id,id_caso,numero_caso,id_tecnico,tipo,cantidad,stock_anterior,stock_nuevo,rma_anterior,rma_nuevo,id_usuario,motivo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $s->execute([$id,$caso['id']??null,$caso['numero_caso']??null,$caso['id_tecnico']??null,$tipo,$n,$a,$nuevo,$r,$reserva,$actor,$motivo]);
    $op=(int)$inv->lastInsertId();
    $s=$inv->prepare('INSERT INTO movimientos_inventario (producto_id,tipo_movimiento,cantidad,stock_anterior,stock_nuevo,usuario_id,referencia,observacion) VALUES (?,?,?,?,?,?,?,?)');
    $s->execute([$id,$tipo==='CENTRAL_RMA'?'INGRESO_RMA':($tipo==='RMA_CENTRAL'?'SALIDA_RMA':'AJUSTE'),$n,$a,$nuevo,$actor,$caso['numero_caso']??'Baja de depósito',"[$tipo #$op] $motivo"]);
    return $op;
}
function rmaBajaInsumo(PDO $rma,array $d,?array $caso,int $actor): int {
    if (!$rma->inTransaction()) throw new LogicException('Se requiere transacción.');
    $n=rmaCantidadPositiva($d['cantidad']??null);$motivo=rmaMotivo($d['motivo']??null);
    $causa=(string)($d['causa']??'');
    if (!in_array($causa,['PERDIDA_DEPOSITO','FALLA_TECNICA'],true)) throw new InvalidArgumentException('Seleccioná la causa de la baja.');
    $id=rmaInvEntero($d['id_stock']??null,'Insumo');
    $s=$rma->prepare('SELECT cantidad FROM stock WHERE id=? FOR UPDATE');$s->execute([$id]);$a=$s->fetchColumn();
    if ($a===false || $n>(int)$a) throw new DomainException('Stock insuficiente para la baja.');
    $nuevo=(int)$a-$n;
    $s=$rma->prepare('UPDATE stock SET cantidad=?,updated_at=NOW() WHERE id=?');$s->execute([$nuevo,$id]);
    $s=$rma->prepare('INSERT INTO bajas_stock (id_stock,id_caso,cantidad,stock_anterior,stock_nuevo,id_usuario,causa,motivo) VALUES (?,?,?,?,?,?,?,?)');
    $s->execute([$id,$caso['id']??null,$n,$a,$nuevo,$actor,$causa,$motivo]);$op=(int)$rma->lastInsertId();
    $s=$rma->prepare("INSERT INTO movimientos_stock (id_stock,tipo_movimiento,cantidad,stock_anterior,stock_nuevo,id_caso,id_usuario,motivo) VALUES (?,'AJUSTE_INVENTARIO',?,?,?,?,?,?)");
    $s->execute([$id,$n,$a,$nuevo,$caso['id']??null,$actor,mb_substr("BAJA #$op [$causa] $motivo",0,255)]);
    return $op;
}
