<?php

namespace App\Filament;

/**
 * Menu groups of the admin panel, in the order they are shown.
 * Citizens (people who use the bot) and district staff (who check the objects) are kept apart on purpose.
 */
final class NavGroups
{
    public const CITIZENS = 'Tumanlar aholisi boshqaruvi';

    public const STAFF = 'Tuman xodimlari';

    public const CATALOG = 'Katalog';

    public const ORDER = [self::CITIZENS, self::STAFF, self::CATALOG];
}
