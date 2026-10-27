<?php
/** Decode a complete WordPress JSON response, tolerating only leading HTML comments. */
function odlBridgeJsonDecode(string $body): ?array {
 if(strlen($body)>2097152)return null;
 $candidate=ltrim($body);
 while(str_starts_with($candidate,'<!--')){
  $end=strpos($candidate,'-->',4);if($end===false)return null;
  $candidate=ltrim(substr($candidate,$end+3));
 }
 $data=json_decode($candidate,true,64);
 return json_last_error()===JSON_ERROR_NONE&&is_array($data)?$data:null;
}
