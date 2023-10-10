<?php

use Soneso\StellarSDK\StellarSDK;

require 'vendor/autoload.php';

$CalcVoices = new MTLA\CalcVoices(
    StellarSDK::getPublicNetInstance(),
    'GCNVDZIHGX473FEI7IXCUAEXUJ4BGCKEMHF36VYP5EMS7PX2QBLAMTLA',
    'MTLAP'
);

$CalcVoices->isDebugMode(false);

$CalcVoices->run();
