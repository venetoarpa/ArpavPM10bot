<?php
$botName = 'ArpavPM10bot';
cli_set_process_title($botName);
set_time_limit(0);
include("pChart/class/pData.class.php");
include("pChart/class/pDraw.class.php");
include("pChart/class/pImage.class.php");
require_once $botName.'.php';
$botInstance = new $botName();
$botInstance->start();
