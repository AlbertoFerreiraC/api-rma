<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../api-rma/db_config.php';
require_once __DIR__.'/../api-rma/inventario/inventario_service.php';
date_default_timezone_set('America/Asuncion');
$rma=rmaConexion('rma');$inv=rmaConexion('inventario');
$tecnicos=$rma->query('SELECT id FROM usuarios WHERE id_rol=1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$tipos=$rma->query('SELECT id FROM tipos_caso ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
if(!$tecnicos||!$tipos)throw new RuntimeException('Se requiere al menos un técnico y un tipo de caso.');
$estados=$rma->query('SELECT nombre,id FROM estados_caso')->fetchAll(PDO::FETCH_KEY_PAIR);
$marcas=['Lenovo','HP','Dell','Asus','Acer','Samsung'];$equipos=['Notebook','PC de escritorio','Monitor','Impresora','Servidor'];
$fallas=['No enciende','Reinicios intermitentes','Disco no detectado','Imagen con líneas','Sobrecalentamiento','Puertos USB sin respuesta','Batería no carga','Sin conexión de red'];
$insumos=['Memoria DDR4 8 GB','SSD SATA 480 GB','Cable HDMI','Fuente ATX 500 W','Pasta térmica','Ventilador 120 mm','Batería notebook','Adaptador USB de red','Cable SATA','Teclado USB'];
$nuevos=['casos'=>0,'stock_central'=>0,'insumos_rma'=>0];
try {
 $rma->beginTransaction();$clientes=[];$proveedores=[];
 for($i=1;$i<=20;$i++){
  $ci='DEMO-CLIENTE-'.str_pad((string)$i,3,'0',STR_PAD_LEFT);$s=$rma->prepare('SELECT id FROM clientes WHERE cedula=?');$s->execute([$ci]);$id=$s->fetchColumn();
  if(!$id){$s=$rma->prepare("INSERT INTO clientes (nombre,cedula,celular) VALUES (?,?,'')");$s->execute(['DEMO Cliente '.$i,$ci]);$id=$rma->lastInsertId();}$clientes[]=(int)$id;
 }
 for($i=1;$i<=4;$i++){
  $nombre='DEMO Proveedor '.$i;$s=$rma->prepare('SELECT id FROM proveedores WHERE nombre=?');$s->execute([$nombre]);$id=$s->fetchColumn();
  if(!$id){$s=$rma->prepare('INSERT INTO proveedores (nombre,contacto) VALUES (?,?)');$s->execute([$nombre,'Datos ficticios para demostración. Sin contacto real.']);$id=$rma->lastInsertId();}$proveedores[]=(int)$id;
 }
 $categorias=$rma->query('SELECT id FROM categorias_stock ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
 for($i=1;$i<=100;$i++){
  $sku='DEMO-INS-'.str_pad((string)$i,3,'0',STR_PAD_LEFT);$s=$rma->prepare('SELECT id FROM stock WHERE codigo_sku=?');$s->execute([$sku]);if($s->fetchColumn())continue;
  $s=$rma->prepare('INSERT INTO stock (codigo_sku,id_categoria,nombre,descripcion,id_proveedor_habitual,cantidad,stock_minimo) VALUES (?,?,?,?,?,?,?)');
  $s->execute([$sku,$categorias? $categorias[($i-1)%count($categorias)]:null,'DEMO '.$insumos[($i-1)%count($insumos)].' #'.$i,'Insumo ficticio de demostración. No corresponde a existencias reales.',$proveedores[($i-1)%4],random_int(0,40),random_int(3,8)]);$nuevos['insumos_rma']++;
 }
 $png=__DIR__.'/../uploads/DEMO_EVIDENCIA.png';
 if(!is_file($png)){$im=imagecreatetruecolor(800,450);imagefill($im,0,0,imagecolorallocate($im,235,242,250));$ink=imagecolorallocate($im,15,23,42);imagestring($im,5,35,45,'RMA - EVIDENCIA DE DEMOSTRACION',$ink);imagestring($im,5,35,85,'Equipo ficticio para verificar fotos adjuntas.',$ink);imagerectangle($im,190,150,610,350,$ink);imagerectangle($im,205,165,595,320,$ink);imageline($im,160,370,640,370,$ink);imagepng($im,$png);imagedestroy($im);}
 for($i=1;$i<=100;$i++){
  $num='RMA-DEMO-'.str_pad((string)$i,3,'0',STR_PAD_LEFT);$s=$rma->prepare('SELECT id FROM casos WHERE numero_caso=?');$s->execute([$num]);if($s->fetchColumn())continue;
  $tec=(int)$tecnicos[($i-1)%count($tecnicos)];$inicio=(new DateTimeImmutable())->modify('-'.random_int(5,90).' days')->setTime(random_int(8,16),random_int(0,59));
  $estadoNombre=['ingresado','en_diagnostico','en_reparacion','enviado_a_proveedor','recibido_de_proveedor','listo_para_entrega','entregado','cerrado','cancelado'][($i-1)%9];
  $estado=(int)($estados[$estadoNombre]??reset($estados));$cerrado=in_array($estadoNombre,['entregado','cerrado','cancelado'],true);$fin=$cerrado?$inicio->modify('+'.random_int(1,4).' days'):null;
  $externo=in_array($estadoNombre,['enviado_a_proveedor','recibido_de_proveedor'],true);
  $foto=$i<=20?'DEMO_EVIDENCIA.png':null;
  $s=$rma->prepare('INSERT INTO casos (numero_caso,id_cliente,id_tecnico,id_tipo_caso,id_estado_actual,id_proveedor,equipo,marca,modelo,numero_serie,descripcion_problema,diagnostico_final,foto_archivo,fecha_ingreso,fecha_cierre,fecha_envio_proveedor,referencia_proveedor) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
  $s->execute([$num,$clientes[($i-1)%20],$tec,$tipos[($i-1)%count($tipos)],$estado,$externo?$proveedores[($i-1)%4]:null,$equipos[array_rand($equipos)],$marcas[array_rand($marcas)],'DEMO Modelo '.random_int(1,12),'DEMO-SERIE-'.str_pad((string)$i,4,'0',STR_PAD_LEFT),'[DEMOSTRACIÓN] '.$fallas[array_rand($fallas)],$cerrado?'[DEMOSTRACIÓN] Prueba y cierre técnico ficticio.':null,$foto,$inicio->format('Y-m-d H:i:s'),$fin?->format('Y-m-d H:i:s'),$externo?$inicio->modify('+1 day')->format('Y-m-d H:i:s'):null,$externo?'DEMO-EXT-'.$i:null]);
  $id=(int)$rma->lastInsertId();$s=$rma->prepare('INSERT INTO historial_estados (id_caso,id_estado,fecha,observacion,id_usuario) VALUES (?,?,?,?,?)');$s->execute([$id,$estados['ingresado']??$estado,$inicio->format('Y-m-d H:i:s'),'[DEMOSTRACIÓN] Ingreso ficticio.',$tec]);
  if($estado!==($estados['ingresado']??$estado))$s->execute([$id,$estado,($fin??$inicio->modify('+1 day'))->format('Y-m-d H:i:s'),'[DEMOSTRACIÓN] Evolución ficticia del caso.',$tec]);
  if($foto){$s=$rma->prepare('INSERT IGNORE INTO caso_fotos (id_caso,archivo) VALUES (?,?)');$s->execute([$id,$foto]);}$nuevos['casos']++;
 }
 $rma->commit();
 $inv->beginTransaction();
 for($i=1;$i<=100;$i++){
  $codigo='DEMO-STK-'.str_pad((string)$i,3,'0',STR_PAD_LEFT);$s=$inv->prepare('SELECT id FROM productos WHERE codigo=?');$s->execute([$codigo]);if($s->fetchColumn())continue;
  $n=random_int(0,60);$min=random_int(3,10);$s=$inv->prepare('INSERT INTO productos (codigo,nombre,categoria,marca,stock_actual,stock_rma,stock_minimo,estado_alerta) VALUES (?,?,?,?,?,0,?,?)');
  $s->execute([$codigo,'DEMO '.$insumos[($i-1)%count($insumos)].' #'.$i,'Demostración',$marcas[array_rand($marcas)],$n,$min,rmaInvEstado($n,0,$min)]);$nuevos['stock_central']++;
 }
 $inv->commit();
 echo json_encode(['creados'=>$nuevos,'totales_demo'=>['casos'=>(int)$rma->query("SELECT COUNT(*) FROM casos WHERE numero_caso LIKE 'RMA-DEMO-%'")->fetchColumn(),'insumos_rma'=>(int)$rma->query("SELECT COUNT(*) FROM stock WHERE codigo_sku LIKE 'DEMO-INS-%'")->fetchColumn(),'stock_central'=>(int)$inv->query("SELECT COUNT(*) FROM productos WHERE codigo LIKE 'DEMO-STK-%'")->fetchColumn()]],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
}catch(Throwable $e){if($rma->inTransaction())$rma->rollBack();if($inv->inTransaction())$inv->rollBack();throw $e;}
