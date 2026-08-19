<?php

declare(strict_types=1);

namespace App\Enums;

enum QrCodeType: string
{
    case Url = 'url';
    case Whatsapp = 'whatsapp';
    case File = 'file';
    case Vcard = 'vcard';
    case Linkpage = 'linkpage';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
