<?php
function pairingSiteUrl(string $raw): ?string {
 $parts=parse_url($raw);
 if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])||isset($parts['query'])||isset($parts['fragment'])||!in_array((string)($parts['path']??''),['','/'],true))return null;
 $host=strtolower((string)($parts['host']??''));
 if(strlen($host)>253||!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$/D',$host))return null;
 $records=dns_get_record($host,DNS_A|DNS_AAAA);
 if(!is_array($records)||!$records)return null;
 foreach($records as $record){
  $ip=(string)($record['ip']??$record['ipv6']??'');
  if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return null;
 }
 return 'https://'.$host;
}
