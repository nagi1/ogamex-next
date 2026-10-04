<?php
require '/var/www/vendor/autoload.php';
$app = require '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$bp = app(Modules\AI\Domain\Decision\QueueableBuildingPlanner::class);
foreach ($bp->steps(38) as $s) echo get_class($s)." p".$s->planetId." ".($s->buildingId??$s->researchId)." ".$s->reason."\n";
