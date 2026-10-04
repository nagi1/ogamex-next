<?php
require '/var/www/vendor/autoload.php';
$app = require '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$ai = DB::table('ai_profiles')->pluck('player_id')->all();
$rows = DB::table('planets')->whereIn('user_id',$ai)->where('solar_satellite','>',100)->orderByDesc('solar_satellite')->limit(12)->get(['id','user_id','solar_satellite','solar_plant','fusion_plant','energy_max','energy_used','planet_type']);
foreach($rows as $r) echo "p$r->id u$r->user_id sats=$r->solar_satellite plant=$r->solar_plant fusion=$r->fusion_plant energy_max=$r->energy_max used=$r->energy_used\n";
echo "planets with sats and surplus energy > 50%: ";
echo DB::table('planets')->whereIn('user_id',$ai)->where('solar_satellite','>',0)->whereRaw('energy_max > energy_used*1.5')->count()." of ".DB::table('planets')->whereIn('user_id',$ai)->where('solar_satellite','>',0)->count()."\n";
echo "sat orders 24h: ".DB::table('unit_queues')->where('object_id',212)->where('created_at','>=',now()->subDay())->count()." amount ".DB::table('unit_queues')->where('object_id',212)->where('created_at','>=',now()->subDay())->sum('object_amount')."\n";
$r = DB::table('ai_action_receipts')->where('result','like','%role:energy:solar_satellite%')->where('created_at','>=',now()->subDay())->count(); echo "role:energy receipts $r\n";
