<?php
require __DIR__.'/console/pairing-service-v2.php';
foreach([[200,null,false,true],[301,null,false,true],[302,null,false,true],[303,null,false,true],[307,null,false,true],[308,null,false,true],[404,null,false,true],[200,['ok'=>true],false,false],[403,['code'=>'pairing_denied'],false,false],[409,['code'=>'pairing_used'],false,false],[500,null,false,false],[200,null,true,false],[404,null,true,false]] as [$http,$data,$plain,$expected]){
 if(odlPairNeedsPlainRoute($http,$data,$plain)!==$expected)throw new RuntimeException('Unexpected retry for HTTP '.$http.' plain='.(int)$plain);
}
echo "PASS: HTML responses and redirects retry same-origin plain route; JSON, auth failures and second attempts never retry\n";
