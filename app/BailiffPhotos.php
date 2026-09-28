<?php
declare(strict_types=1);
namespace Temple;
use RuntimeException;
final class BailiffPhotos {
    public function __construct(private Store $s,private string $data) {}
    public function save(int $admin,int $id,?array $upload,bool $remove=false): void {
        if(!$this->s->one("SELECT id FROM users WHERE id=? AND active=1 AND role='admin'",[$admin])) throw new RuntimeException('Administrator access required.');
        if(!$this->s->one("SELECT id FROM users WHERE id=? AND role='bailiff'",[$id])) throw new RuntimeException('Bailiff not found.');
        $filename='';$dir=$this->data.'/bailiff-photos';
        if(!$remove) {
            if(!$upload || ($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name']??'')) throw new RuntimeException('Choose a JPEG or PNG photo up to 2 MB. If upload fails, check PHP upload limits.');
            if(filesize($upload['tmp_name'])>2*1024*1024) throw new RuntimeException('Photo must be no larger than 2 MB.');
            if(!function_exists('imagecreatefromstring')) throw new RuntimeException('Enable the PHP GD extension in Plesk to upload photos.');
            $info=@getimagesize($upload['tmp_name']);
            if(!$info || !in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG],true) || $info[0]*$info[1]>8000000) throw new RuntimeException('Use a JPEG or PNG photo up to 8 megapixels.');
            $src=@imagecreatefromstring(file_get_contents($upload['tmp_name']));
            if(!$src) throw new RuntimeException('This photo could not be read. Export it as JPEG or PNG.');
            if($info[2]===IMAGETYPE_JPEG && function_exists('exif_read_data')) {
                $exif=@exif_read_data($upload['tmp_name']);$orientation=(int)($exif['Orientation']??1);
                if(in_array($orientation,[2,4,5,7],true)) imageflip($src,IMG_FLIP_HORIZONTAL);
                $angle=match($orientation){3,4=>180,5,6=>-90,7,8=>90,default=>0};
                if($angle){$rotated=imagerotate($src,$angle,0);imagedestroy($src);$src=$rotated;}
            }
            $w=imagesx($src);$h=imagesy($src);$scale=min(1,640/max($w,$h));
            $out=imagecreatetruecolor(max(1,(int)round($w*$scale)),max(1,(int)round($h*$scale)));
            imagefill($out,0,0,imagecolorallocate($out,255,255,255));
            imagecopyresampled($out,$src,0,0,0,0,imagesx($out),imagesy($out),$w,$h);imagedestroy($src);
            if(!is_dir($dir) && !mkdir($dir,0700,true)) throw new RuntimeException('Private photo storage is not writable.');
            $filename=bin2hex(random_bytes(24)).'.jpg';
            $written=imagejpeg($out,$dir.'/'.$filename,85);imagedestroy($out);
            if(!$written) throw new RuntimeException('Unable to save photo.');
            chmod($dir.'/'.$filename,0600);
        }
        try {
            $old=$this->s->tx(function() use($admin,$id,$filename){
                $old=$this->s->setting('bailiff_photo_'.$id);
                $this->s->run('INSERT OR REPLACE INTO settings(key,value) VALUES(?,?)',['bailiff_photo_'.$id,$filename]);
                $this->s->audit($admin,$filename?'bailiff_photo_saved':'bailiff_photo_removed',null,'Staff ID '.$id);
                return $old;
            });
        } catch(\Throwable $e) {if($filename) @unlink($dir.'/'.$filename);throw $e;}
        if(preg_match('/^[a-f0-9]{48}\.jpg$/D',$old)) @unlink($dir.'/'.$old);
    }
}
