<?php

namespace Modules\Empire\Enums;

/** What one alliance holds toward another. */
enum TreatyStance: int
{
    case War = 1;
    case Pact = 2;
}
