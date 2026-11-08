<?php
// Run in a WordPress test bootstrap after loading the patched native content service.
if(!in_array('paragraph_spacing',odlContentSettingKeys(),true))throw new RuntimeException('paragraph_spacing is missing from the native whitelist.');
$cases=[
 [['unit'=>'px','size'=>0],true],
 [['unit'=>'px','size'=>1000],true],
 [['unit'=>'em','size'=>1.25],true],
 [['unit'=>'rem','size'=>1],true],
 [['unit'=>'px','size'=>'8','sizes'=>[]],true],
 [['size'=>8],true],
 [['unit'=>'px','size'=>-1],false],
 [['unit'=>'px','size'=>1001],false],
 [['unit'=>'px','size'=>INF],false],
 [['unit'=>'px','size'=>NAN],false],
 [['unit'=>'px','size'=>''],false],
 [['unit'=>'px','size'=>true],false],
 [['unit'=>'px'],false],
 [['unit'=>'%','size'=>8],false],
 [['unit'=>'px','size'=>'8;display:none'],false],
 [['unit'=>'px','size'=>8,'css'=>'display:none'],false],
 ['8px',false],
];
foreach($cases as [$value,$wanted]){
 $accepted=true;try{odlContentSettingValue('paragraph_spacing',$value);}catch(OdlContentError $e){$accepted=false;}
 if($accepted!==$wanted)throw new RuntimeException('Unexpected paragraph spacing validation: '.json_encode($value,JSON_PARTIAL_OUTPUT_ON_ERROR));
}
echo count($cases)." paragraph-spacing cases passed\n";
