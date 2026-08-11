<?php

namespace App\Services\Sms;

class SmsSegmentCalculator
{
    public function calculate(string $message): array
    {
        $gsm = preg_match('/^[\x{000A}\x{000D}\x{0020}-\x{007E}£¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ¤¡ÄÖÑÜ§¿äöñüà^{}\\\[~\]|€]*$/u', $message) === 1;
        $length = mb_strlen($message);
        $single = $gsm ? 160 : 70;
        $multipart = $gsm ? 153 : 67;
        $segments = $length <= $single ? 1 : (int) ceil($length / $multipart);

        return ['encoding' => $gsm ? 'gsm7' : 'unicode', 'characters' => $length, 'segments' => max(1, $segments)];
    }
}
