<?php

namespace OGame\Enums;

/**
 * The declared hostility mode of one universe.
 *
 * `Ordinary` leaves every hostile path exactly as it always was. `Cooperative` turns on the
 * read-only hostility guard, which fails closed when no module policy is registered, so a PvE
 * world can never silently become PvP.
 */
enum UniverseMode: string
{
    case Ordinary = 'ordinary';
    case Cooperative = 'cooperative';
}
