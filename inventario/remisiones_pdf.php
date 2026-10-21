<?php
declare(strict_types=1);

/** Documento carta con medidas tipográficas y recursos locales; sin servicios externos. */
final class RmaRemisionDocumento
{
    private array $pages=[];
    private array $images=[];
    private array $widths;
    private string $stream='';
    private array $nota;
    public float $y=0;
    public string $numero;
    public function __construct(array $nota)
    {
        $this->nota=$nota;
        $this->numero='NR-'.str_pad((string)$nota['id'],6,'0',STR_PAD_LEFT);
        $this->widths=require __DIR__.'/pdf_font_widths.php';
        $this->newPage();
    }
    private function encode(string $text): string
    {
        $text=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$text)??'';
        return iconv('UTF-8','Windows-1252//TRANSLIT',$text) ?: '';
    }
    private function escape(string $s): string { return str_replace(["\\","(",")","\r","\n"],["\\\\","\\(","\\)"," "," "],$this->encode($s)); }
    public function width(string $s,float $size=9.5,bool $bold=false): float
    {
        $metric=$this->widths[$bold?'Helvetica-Bold':'Helvetica']; $w=0;
        foreach (str_split($this->encode($s)) as $char) $w+=$metric[ord($char)]??556;
        return $w*$size/1000;
    }
    public function wrap(string $s,float $width,float $size=9.5,bool $bold=false): array
    {
        $lines=[];
        foreach (explode("\n",str_replace("\r",'',$s)) as $paragraph) {
            $line='';
            foreach (preg_split('/\s+/u',trim($paragraph),-1,PREG_SPLIT_NO_EMPTY)?:[] as $word) {
                if ($line!=='' && $this->width($line.' '.$word,$size,$bold)>$width) { $lines[]=$line; $line=''; }
                if ($this->width($word,$size,$bold)>$width) {
                    if ($line!=='') { $lines[]=$line; $line=''; }
                    foreach (mb_str_split($word) as $char) {
                        if ($line!=='' && $this->width($line.$char,$size,$bold)>$width) { $lines[]=$line; $line=''; }
                        $line.=$char;
                    }
                } else $line.=($line===''?'':' ').$word;
            }
            $lines[]=$line;
        }
        return $lines ?: ['-'];
    }
    public function text(float $x,float $top,string $text,float $size=9.5,bool $bold=false,string $align='left',float $width=0): void
    {
        if ($align==='right') $x+=$width-$this->width($text,$size,$bold);
        if ($align==='center') $x+=($width-$this->width($text,$size,$bold))/2;
        $font=$bold?'F2':'F1'; $baseline=792-$top-$size;
        $this->stream.=sprintf("BT /%s %.2F Tf 0.08 0.08 0.08 rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",$font,$size,$x,$baseline,$this->escape($text));
    }
    public function line(float $x,float $y,float $x2,float $y2,bool $dash=false): void
    {
        $this->stream.=sprintf("0.25 G 0.55 w %s %.2F %.2F m %.2F %.2F l S [] 0 d\n",$dash?'[3 3] 0 d':'[] 0 d',$x,792-$y,$x2,792-$y2);
    }
    public function box(float $x,float $top,float $width,float $height,float $gray=.96): void
    {
        $this->stream.=sprintf("%.2F g %.2F %.2F %.2F %.2F re f\n",$gray,$x,792-$top-$height,$width,$height);
    }
    public function image(string $bytes,float $x,float $top,float $w,float $h): void
    {
        $gd=imagecreatefromstring($bytes);
        if ($gd===false) throw new RuntimeException('No se pudo cargar una imagen del documento.');
        $flat=imagecreatetruecolor(imagesx($gd),imagesy($gd));
        imagefill($flat,0,0,imagecolorallocate($flat,255,255,255)); imagecopy($flat,$gd,0,0,0,0,imagesx($gd),imagesy($gd));
        ob_start(); imagejpeg($flat,null,95); $jpeg=ob_get_clean();
        $key='Im'.count($this->images);
        $this->images[$key]=['data'=>$jpeg,'w'=>imagesx($gd),'h'=>imagesy($gd)];
        imagedestroy($gd); imagedestroy($flat);
        $this->stream.=sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n",$w,$h,$x,792-$top-$h,$key);
    }
    public function date($value): string
    {
        if (!$value) return '-';
        $dt=DateTimeImmutable::createFromFormat('Y-m-d H:i:s',(string)$value);
        if (!$dt) $dt=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$value);
        return $dt ? $dt->format('d/m/Y H:i') : (string)$value;
    }
    public function newPage(): void
    {
        if ($this->stream!=='') $this->pages[]=$this->stream;
        $this->stream='';
        $this->text(32,24,'DEPARTAMENTO TÉCNICO / DEPÓSITO',8,true);
        $this->text(32,41,'Micro Express',24,true);
        $this->text(34,71,'I N F O R M Á T I C A',10,true);
        $this->text(32,93,'VENTA AL POR MAYOR DE EQUIPOS INFORMÁTICOS Y SOFTWARE',7.5);
        $this->text(32,105,'Avda. J. Kubitschek 796 c/ Celsa Speratti - Asunción, Paraguay',8);
        $this->text(32,117,'Tel.: (595-21) 226 979 / 225 037 / 214 539 / 204 886',7.5);
        $this->text(395,27,'NOTA DE REMISIÓN',11,true,'right',185);
        $this->text(395,48,$this->numero,19,true,'right',185);
        $this->text(395,77,'FECHA: '.$this->date($this->nota['fecha_creacion']),8.5,false,'right',185);
        $this->box(428,101,152,23,.93);
        $this->text(432,107,(string)$this->nota['estado'],9,true,'center',144);
        $this->line(32,139,580,139);
        $this->y=151;
    }
    public function ensure(float $h): void { if ($this->y+$h>744) $this->newPage(); }
    public function fields(array $pairs): void
    {
        $width=548/count($pairs); $all=[]; $height=0;
        foreach ($pairs as [$label,$value]) {
            $lines=$this->wrap(trim((string)$value)?:'-',$width-16,9.5);
            $all[]=[$label,$lines]; $height=max($height,19+count($lines)*12);
        }
        $this->ensure($height+10);
        foreach ($all as $i=>[$label,$lines]) {
            $x=32+$i*$width; $this->text($x,$this->y,$label,7.5,true);
            foreach($lines as $j=>$line) $this->text($x,$this->y+13+$j*12,$line,9.5);
        }
        $this->line(32,$this->y+$height+3,580,$this->y+$height+3); $this->y+=$height+13;
    }
    public function table(array $headers,array $values,array $widths): void
    {
        $all=[]; $count=1;
        foreach ($values as $i=>$v) { $all[$i]=$this->wrap((string)$v,$widths[$i]-16,9.5); $count=max($count,count($all[$i])); }
        // Una fila excepcionalmente larga continúa en páginas nuevas con sus encabezados.
        for ($offset=0;$offset<$count;) {
            $this->ensure(60);
            $take=min($count-$offset,max(1,(int)floor((744-$this->y-35)/12)));
            $height=20+$take*12+12; $x=32;
            $this->box(32,$this->y,548,22,.94);
            foreach ($headers as $i=>$head) { $this->text($x+8,$this->y+6,$head,8,true); $x+=$widths[$i]; }
            $x=32;
            foreach ($all as $i=>$lines) {
                foreach (array_slice($lines,$offset,$take) as $j=>$line) $this->text($x+8,$this->y+29+$j*12,$line,9.5);
                $x+=$widths[$i];
            }
            $this->line(32,$this->y,580,$this->y);
            $this->line(32,$this->y+$height,580,$this->y+$height);
            $this->y+=$height+14; $offset+=$take;
            if ($offset<$count) $this->newPage();
        }
    }
    public function note(string $title,string $value): void
    {
        $lines=$this->wrap(trim($value)?:'-',528,9.5); $offset=0;
        while ($offset<count($lines)) {
            $this->ensure(55);
            $take=min(count($lines)-$offset,max(1,(int)floor((744-$this->y-28)/12)));
            $h=26+$take*12;
            $this->box(32,$this->y,548,$h,.975);
            $this->text(42,$this->y+7,$title.($offset?' (continuación)':''),8,true);
            foreach(array_slice($lines,$offset,$take) as $j=>$line) $this->text(42,$this->y+21+$j*12,$line,9.5);
            $this->y+=$h+12; $offset+=$take;
        }
    }
    public function finish(): string
    {
        $this->pages[]=$this->stream; $objects=[1=>'<< /Type /Catalog /Pages 2 0 R >>',2=>'',
            3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];
        $next=5; $resources='';
        foreach ($this->images as $name=>$img) {
            $id=$next++; $resources.="/$name $id 0 R ";
            $objects[$id]="<< /Type /XObject /Subtype /Image /Width {$img['w']} /Height {$img['h']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($img['data'])." >>\nstream\n".$img['data']."\nendstream";
        }
        $kids=[];
        foreach($this->pages as $i=>$stream) {
            $footer=$this->numero.' | Caso '.$this->nota['numero_caso'].' | Página '.($i+1).' de '.count($this->pages);
            $stream.=sprintf("BT /F1 7 Tf 0.4 g 32 22 Td (%s) Tj ET\n",$this->escape($footer));
            $page=$next++; $content=$next++; $kids[]="$page 0 R";
            $objects[$page]="<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << $resources >> >> /Contents $content 0 R >>";
            $objects[$content]="<< /Length ".strlen($stream)." >>\nstream\n".$stream."endstream";
        }
        $objects[2]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.count($this->pages).' >>';
        ksort($objects); $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offsets=[];
        foreach($objects as $id=>$object) { $offsets[]=strlen($pdf); $pdf.="$id 0 obj\n$object\nendobj\n"; }
        $xref=strlen($pdf); $size=count($objects)+1; $pdf.="xref\n0 $size\n0000000000 65535 f \n";
        foreach($offsets as $o) $pdf.=sprintf("%010d 00000 n \n",$o);
        return $pdf."trailer\n<< /Size $size /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }
}

function rmaRemisionPdf(array $n): string
{
    $p=new RmaRemisionDocumento($n);
    $p->fields([['CLIENTE',$n['cliente_nombre']??''],['C.I. / RUC',$n['cliente_cedula']??''],['TELÉFONO',$n['cliente_celular']??'']]);
    $p->fields([['CASO / ORDEN DE TRABAJO',$n['numero_caso']],['SOLICITANTE',$n['creador_nombre']??$n['tecnico_nombre']??'']]);
    $p->table(['SERIE N°','DETALLE DEL EQUIPO','MODELO'],
        [$n['numero_serie']??'',trim(($n['equipo']??'').' '.($n['marca']??'')),$n['modelo']??''],[135,278,135]);
    $p->ensure(80);
    $p->text(32,$p->y,'REPUESTOS / INSUMOS SOLICITADOS',8,true); $p->y+=17;
    $p->table(['CANT.','CÓDIGO / SKU','DESCRIPCIÓN'],[(string)$n['cantidad'],$n['codigo_sku']??'Por asignar',$n['insumo_nombre']],[58,125,365]);
    $p->note('OBSERVACIONES DE LA SOLICITUD',(string)($n['observacion']??''));
    if (!empty($n['observacion_cierre'])) $p->note('GESTIÓN DE DEPÓSITO',(string)$n['observacion_cierre']);
    if (!empty($n['cierre_nombre']) || !empty($n['fecha_cierre'])) $p->fields([['GESTIONADA POR',$n['cierre_nombre']??''],['FECHA DE CIERRE',$p->date($n['fecha_cierre']??null)]]);
    $solicitante=$p->wrap('Solicitante: '.($n['creador_nombre']??$n['tecnico_nombre']??'-'),365,8.5);
    $extra=max(0,count($solicitante)-1)*11;
    $p->ensure(182+$extra);
    $p->text(32,$p->y,'Documento interno de solicitud. La entrega se acredita con la conformidad de los responsables.',8);
    $p->y+=43;
    $p->line(45,$p->y,260,$p->y); $p->line(350,$p->y,565,$p->y);
    $p->text(45,$p->y+5,'Entrega / Depósito - firma y aclaración',8,false,'center',215);
    $p->text(350,$p->y+5,'Recibe / Técnico - firma y aclaración',8,false,'center',215);
    $p->y+=38;
    $p->line(32,$p->y,580,$p->y,true); $p->y+=12;
    $p->text(32,$p->y,'MICRO EXPRESS S.R.L. - TALÓN DE CONTROL',9,true);
    $p->text(32,$p->y+18,$p->numero.'  |  Caso '.$n['numero_caso'],9,true);
    foreach ($solicitante as $i=>$line) $p->text(32,$p->y+34+$i*11,$line,8.5);
    $p->text(32,$p->y+49+$extra,'Cantidad: '.$n['cantidad'].'  |  Estado: '.$n['estado'],8.5);
    // Código de barras con la biblioteca local utilizada por el sistema.
    spl_autoload_register(static function(string $class): void {
        $prefix='Picqer\\Barcode\\';
        if (str_starts_with($class,$prefix)) {
            $file=__DIR__.'/../../barcode/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
            if (is_file($file)) require_once $file;
        }
    });
    $barcode=new \Picqer\Barcode\BarcodeGeneratorPNG();
    $barcode->useGd();
    $p->image($barcode->getBarcode($p->numero,$barcode::TYPE_CODE_128,3,60),420,$p->y+18,150,28);
    $p->text(420,$p->y+50,$p->numero,8,false,'center',150);
    return $p->finish();
}
