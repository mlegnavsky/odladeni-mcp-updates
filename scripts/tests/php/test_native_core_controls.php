<?php
// Run in a WordPress test bootstrap after loading the candidate content service.
$cases=[
 ['z_index',20,true],['z_index',-10000,true],['z_index','10000',true],
 ['z_index',10001,false],['z_index',INF,false],['z_index',NAN,false],['z_index',true,false],['z_index',[],false],
 ['text_padding',['unit'=>'px','top'=>'0','right'=>'8','bottom'=>'0','left'=>'8','isLinked'=>false],true],
 ['text_padding','8px',false],['text_padding',['unit'=>'px','top'=>INF],false],['text_padding',['unit'=>'px','top'=>'0;display:none'],false],
 ['link_to','none',true],['link_to','file',true],['link_to','custom',true],['link_to','javascript:alert(1)',false],['link_to',[],false],
];
foreach(['z_index','text_padding','link_to']as $key)if(!in_array($key,odlContentSettingKeys(),true))throw new RuntimeException('Missing key '.$key);
foreach($cases as [$key,$value,$wanted]){
 $accepted=true;try{odlContentSettingValue($key,$value);}catch(OdlContentError $e){$accepted=false;}
 if($accepted!==$wanted)throw new RuntimeException('Unexpected validation for '.$key.' '.json_encode($value,JSON_PARTIAL_OUTPUT_ON_ERROR));
}
// Provider registration is still authoritative even for allowed base keys.
foreach([
 [['type'=>'number'],10001],
 [['type'=>'select','options'=>['none'=>'None','custom'=>'Custom']],'file'],
 [['type'=>'dimensions'],'8px'],
]as [$control,$value]){
 $rejected=false;try{odlContentControlValue($control,$value);}catch(OdlContentError $e){$rejected=true;}
 if(!$rejected)throw new RuntimeException('Provider control validation unexpectedly accepted invalid input.');
}
echo "17 setting cases and 3 provider rejection cases passed\n";
