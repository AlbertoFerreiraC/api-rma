<?php
declare(strict_types=1);
function rmaRecibirFotos(?array $input): array {
    if(!$input)return [];
    $array=is_array($input['name']??null);$count=$array?count($input['name']):1;
    if($count>10)throw new InvalidArgumentException('Podés adjuntar hasta 10 fotos por vez.');
    $archivos=[];$dir=__DIR__.'/../../../uploads/';
    if(!is_dir($dir)&&!mkdir($dir,0775,true))throw new RuntimeException('No se pudo preparar el almacenamiento de fotos.');
    try {
        for($i=0;$i<$count;$i++) {
            $error=$array?$input['error'][$i]:$input['error'];if($error===UPLOAD_ERR_NO_FILE)continue;
            if($error!==UPLOAD_ERR_OK)throw new InvalidArgumentException('No se pudo cargar una foto. Verificá su tamaño y volvé a intentarlo.');
            $tmp=$array?$input['tmp_name'][$i]:$input['tmp_name'];
            if(!is_uploaded_file($tmp)||filesize($tmp)>10*1024*1024)throw new InvalidArgumentException('Cada imagen admite hasta 10 MB.');
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
            if(!$ext || @getimagesize($tmp)===false)throw new InvalidArgumentException('Adjuntá imágenes JPG, PNG o WebP válidas.');
            $file='EVIDENCIA_'.bin2hex(random_bytes(16)).'.'.$ext;
            if(!move_uploaded_file($tmp,$dir.$file))throw new RuntimeException('No se pudo guardar la foto.');
            $archivos[]=$file;
        }
        return $archivos;
    }catch(Throwable $e){rmaRetirarFotos($archivos);throw $e;}
}
function rmaRetirarFotos(array $files):void {foreach($files as $f){$p=__DIR__.'/../../../uploads/'.basename($f);if(is_file($p))unlink($p);}}
function rmaAnexarFotos(PDO $pdo,int $caso,array $files):void {
    $s=$pdo->prepare('INSERT IGNORE INTO caso_fotos (id_caso,archivo) VALUES (?,?)');
    foreach(array_unique(array_filter($files)) as $file)$s->execute([$caso,basename($file)]);
}
