<?php
require __DIR__.'/accounts-approval.php';
require_once dirname(__DIR__).'/assets/accounts-oauth.php';
$root=sys_get_temp_dir().'/odl-oauth-test-'.bin2hex(random_bytes(8));mkdir($root,0700);
try{
 $user=odlAccountsUser($db,'client@example.com');$now=1800000000;
 $client=odlOauthRegister($root,['redirect_uris'=>['https://chatgpt.com/callback']],$now);
 $verifier=str_repeat('a',43);$request=['client_id'=>$client['client_id'],'redirect_uri'=>'https://chatgpt.com/callback','response_type'=>'code','code_challenge_method'=>'S256','code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=')];
 $code=odlOauthCode($root,$request,$user,$now);$tokens=odlOauthExchange($root,['grant_type'=>'authorization_code','client_id'=>$client['client_id'],'code'=>$code,'redirect_uri'=>$request['redirect_uri'],'code_verifier'=>$verifier],$db,'owner@example.com',$now);
 check(odlAccountsConnection($db,$user,$root,$now)['count']===1,'connection status shows actual live grant');
 $next=odlOauthExchange($root,['grant_type'=>'refresh_token','client_id'=>$client['client_id'],'refresh_token'=>$tokens['refresh_token']],$db,'owner@example.com',$now+3600);
 check($next['refresh_token']!==$tokens['refresh_token'],'refresh rotates without new Google consent');
 try{odlOauthExchange($root,['grant_type'=>'refresh_token','client_id'=>$client['client_id'],'refresh_token'=>$tokens['refresh_token']],$db,'owner@example.com',$now+3601);throw new LogicException('replay accepted');}catch(InvalidArgumentException $e){check(true,'used refresh token cannot be replayed');}
 odlAccountsReview($db,$owner,$user['email'],'rejected');
 check(odlAccountsConnection($db,$user,$root,$now+3600)['count']===0,'blocked account shows no valid grants');
 try{odlOauthExchange($root,['grant_type'=>'refresh_token','client_id'=>$client['client_id'],'refresh_token'=>$next['refresh_token']],$db,'owner@example.com',$now+3601);throw new LogicException('blocked refresh accepted');}catch(InvalidArgumentException $e){check(true,'blocking immediately rejects refresh');}
 echo "ALL OAUTH CHECKS PASSED\n";
}finally{foreach(glob($root.'/*') as $f)unlink($f);rmdir($root);}
