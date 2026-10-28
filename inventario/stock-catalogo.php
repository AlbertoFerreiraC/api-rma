<?php
declare(strict_types=1);
require_once __DIR__.'/remisiones_auth.php';rmaPermitir([2,5]);
require_once __DIR__.'/../db.php';require_once __DIR__.'/operaciones_service.php';
$action=(string)($_GET['action']??'');
$select='SELECT s.id,s.codigo_sku,s.id_categoria,s.nombre,s.descripcion,s.id_proveedor_habitual,s.cantidad,s.stock_minimo,c.nombre AS categoria_nombre,p.nombre AS proveedor_nombre FROM stock s LEFT JOIN categorias_stock c ON c.id=s.id_categoria LEFT JOIN proveedores p ON p.id=s.id_proveedor_habitual';
try {
 if($action==='listar'){echo json_encode(['status'=>'success','stock'=>$pdo->query($select.' ORDER BY s.id DESC')->fetchAll(PDO::FETCH_ASSOC)]);exit;}
 if($action==='obtener'){$s=$pdo->prepare($select.' WHERE s.id=?');$s->execute([rmaInvEntero($_GET['id']??null,'ID')]);$item=$s->fetch(PDO::FETCH_ASSOC);if(!$item)throw new DomainException('El insumo no existe.');echo json_encode(['status'=>'success','stock'=>$item]);exit;}
 if($action==='aux_categorias'||$action==='aux_proveedores'){$key=$action==='aux_categorias'?'categorias':'proveedores';$table=$key==='categorias'?'categorias_stock':'proveedores';echo json_encode(['status'=>'success',$key=>$pdo->query("SELECT id,nombre FROM $table ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC)]);exit;}
 if($action==='eliminar')throw new DomainException('Para retirar existencias usá Bajas de stock. Se conserva el historial del insumo.');
 if(in_array($action,['guardar','actualizar'],true)) {
  if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}rmaCsrfValidar();
  $d=json_decode(file_get_contents('php://input'),true);if(!is_array($d))throw new InvalidArgumentException('Datos inválidos.');
  $nombre=trim((string)($d['nombre']??''));$sku=trim((string)($d['codigo_sku']??''));$desc=trim((string)($d['descripcion']??''));
  if($nombre===''||mb_strlen($nombre)>150||mb_strlen($sku)>50||mb_strlen($desc)>10000)throw new InvalidArgumentException('Completá el nombre (hasta 150 caracteres), SKU (hasta 50) y descripción.');
  $min=rmaInvEntero($d['stock_minimo']??0,'Stock mínimo');$cat=rmaInvEntero(($d['id_categoria']??0)?:0,'Categoría');$prov=rmaInvEntero(($d['id_proveedor_habitual']??0)?:0,'Proveedor');
  $pdo->beginTransaction();
  if($action==='guardar') {
   $n=rmaInvEntero($d['cantidad']??0,'Cantidad');$s=$pdo->prepare('INSERT INTO stock (codigo_sku,id_categoria,nombre,descripcion,id_proveedor_habitual,cantidad,stock_minimo) VALUES (?,?,?,?,?,?,?)');$s->execute([$sku?:null,$cat?:null,$nombre,$desc?:null,$prov?:null,$n,$min]);$id=(int)$pdo->lastInsertId();
   if($n){$s=$pdo->prepare("INSERT INTO movimientos_stock (id_stock,tipo_movimiento,cantidad,stock_anterior,stock_nuevo,id_usuario,motivo) VALUES (?,'ENTRADA',?,0,?,?,'Alta inicial de insumo')");$s->execute([$id,$n,$n,rmaUsuarioId()]);}
  }else {
   $id=rmaInvEntero($d['id']??null,'ID');$s=$pdo->prepare('SELECT id FROM stock WHERE id=? FOR UPDATE');$s->execute([$id]);if(!$s->fetchColumn())throw new DomainException('El insumo no existe.');
   $s=$pdo->prepare('UPDATE stock SET codigo_sku=?,id_categoria=?,nombre=?,descripcion=?,id_proveedor_habitual=?,stock_minimo=?,updated_at=NOW() WHERE id=?');$s->execute([$sku?:null,$cat?:null,$nombre,$desc?:null,$prov?:null,$min,$id]);
  }
  $pdo->commit();echo json_encode(['status'=>'success','message'=>'Insumo guardado. Para modificar existencias usá Reabastecimiento, Asignaciones o Bajas.']);exit;
 }
 http_response_code(404);echo json_encode(['status'=>'error','message'=>'Acción inválida.']);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($e instanceof PDOException&&($e->errorInfo[1]??null)===1062){http_response_code(422);echo json_encode(['status'=>'error','message'=>'El código SKU ya existe.']);}else rmaEnviarError($e);}
