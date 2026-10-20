<?php
namespace App\Services;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
class RegistrationQrService
{
    /** Lossless monochrome PNG; no GD, Imagick or remote QR service required. */
    public function png(string $url): string
    {
        $matrix=Encoder::encode($url,ErrorCorrectionLevel::M(),'UTF-8')->getMatrix();
        $scale=8; $quiet=4; $modules=$matrix->getWidth(); $size=($modules+2*$quiet)*$scale;
        $raw='';
        for($y=-$quiet;$y<$modules+$quiet;$y++) {
            $row='';
            for($x=-$quiet;$x<$modules+$quiet;$x++) {
                $black=$x>=0 && $y>=0 && $x<$modules && $y<$modules && $matrix->get($x,$y)===1;
                $row.=str_repeat($black ? "\x00" : "\xff",$scale);
            }
            $raw.=str_repeat("\x00".$row,$scale);
        }
        $chunk=fn(string $type,string $bytes)=>pack('N',strlen($bytes)).$type.$bytes.pack('N',crc32($type.$bytes));
        return "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',$size,$size,8,0,0,0,0)).$chunk('IDAT',gzcompress($raw,9)).$chunk('IEND','');
    }
}
