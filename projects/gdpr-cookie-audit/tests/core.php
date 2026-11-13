<?php
require __DIR__ . '/../plugin/core.php';
function check($ok, $label) { if (!$ok) { throw new Exception($label); } echo "PASS $label\n"; }
check(gca_normalize_url('example.com') === 'https://example.com/', 'normalizes bare domain');
foreach (['http://127.0.0.1', 'http://[::1]', 'https://user:pass@example.com', 'file:///etc/passwd', 'https://example.com:8080', 'https://localhost', 'https://2130706433', 'https://example.com/?token=secret'] as $bad) {
    check(gca_normalize_url($bad) === null, 'rejects ' . $bad);
}
foreach (['127.0.0.1','10.0.0.2','169.254.169.254','::1','::ffff:127.0.0.1','fc00::1','192.0.2.1'] as $bad) check(!gca_public_ip($bad), 'private/reserved ' . $bad);
check(gca_public_ip('8.8.8.8'), 'public address');
foreach (['2400:cb00::1','2606:4700:3037::ac43:ac3e','2803:f800::1','2405:b500::1','2405:8100::1','2a06:98c0::1','2c0f:f248::1','2a00:1450:4001::1'] as $public) {
    check(gca_public_ip($public), 'public CDN IPv6 '.$public);
}
foreach (['2001:db8::1','3fff::1','3fff:fff:ffff::1','fe80::1','ff02::1','fd00::1','::ffff:10.0.0.1'] as $private) {
    check(!gca_public_ip($private),'nonpublic IPv6 '.$private);
}
check(gca_resolve_url('https://example.com/a/b', '../cookies/') === 'https://example.com/cookies/', 'relative resolution');
check(gca_resolve_url('https://example.com/a/b', '/') === 'https://example.com/', 'root has one slash');
$navigation=gca_analyze_page('https://gdpr-cookies.cz/', '<a href="/">Domů</a><a href="/sluzby/">Služby</a><a href="/zasady-cookies/">Zásady cookies</a>');
check(count($navigation['policies'])===1 && $navigation['policies'][0]['url']==='https://gdpr-cookies.cz/zasady-cookies/','domain name does not classify navigation as policy');
$scope=gca_analyze_page('https://example.com/', '<a href="/privacy-policy/">Zásady ochrany osobních údajů</a><a href="/pravidla-cookies/">Pravidla cookies</a><a href="/gdpr/">OOÚ a GDPR</a>');
check(count($scope['policies'])===1 && $scope['policies'][0]['url']==='https://example.com/pravidla-cookies/','only cookie policy is audited');
$tools=gca_analyze_page('https://example.com/','<a href="/kontrola-cookies/">Kontrola cookies</a><a href="/nastaveni-cookies/">Nastavení cookies</a><a href="/zasady-cookies/">Zásady cookies</a>');
check(count($tools['policies'])===1 && $tools['policies'][0]['url']==='https://example.com/zasady-cookies/','cookie tools do not displace cookie document');
$cfLinks=gca_analyze_page('https://www.stranapro.cz/', '<a href="/cdn-cgi/l/email-protection#abc">E-mail</a><a href="/kontakt/">Kontakt</a>');
check($cfLinks['links']===['https://www.stranapro.cz/kontakt/'],'Cloudflare internal endpoints are not content pages');
$html = '<html lang="cs"><head><title>Test</title><script src="https://www.googletagmanager.com/gtag/js?id=G-123"></script></head><body><a href="/zasady-cookies">Zásady cookies</a><p>Odmítnout vše</p></body></html>';
$page = gca_analyze_page('https://example.com/', $html, ['session=SECRET; Path=/; Secure; HttpOnly; SameSite=Lax'], 200);
check($page['title'] === 'Test', 'title extracted');
check(count($page['services']) === 1 && $page['services'][0]['id'] === 'google-tags', 'source integration detected');
check(count($page['policies']) === 1, 'policy link detected');
check(strpos(json_encode($page), 'SECRET') === false, 'cookie values discarded');
check($page['cookies'][0]['secure'] === true, 'cookie metadata');
check($page['dynamic_verified'] === false, 'source does not imply execution');
$p = gca_analyze_policy('https://example.com/zasady-cookies', '<main><h1>Zásady cookies</h1><p>Správce ABC s.r.o., IČO 12345678, info@example.com. Používáme analytické cookies Google Analytics po dobu 90 dní. Souhlas lze odvolat pomocí nastavení cookies.</p></main>', ['google-tags']);
check(!isset($p['checks']['contact']), 'generic controller/contact audit belongs to separate tool');
check($p['checks']['retention']['status'] === 'pass', 'policy retention evidence');
check($p['checks']['withdrawal']['status'] === 'pass', 'policy withdrawal evidence');
check($p['services'][0]['status'] === 'pass', 'policy provider mention');
$empty=gca_analyze_policy('https://example.com/cookies', '<main>Cookies</main>', ['meta']);
check($empty['checks']['retention']['status'] === 'warning', 'missing text not definite legal breach');
check($empty['services'][0]['status'] === 'warning', 'missing service statement');
$generic=gca_analyze_policy('https://example.com/cookies','<p>Cookies mají dobu platnosti. Používáním stránky souhlasíte s cookies.</p>');
check($generic['checks']['retention']['status']==='warning','generic lifetime heading is not actual lifetime evidence');
check($generic['checks']['implied_consent']['status']==='warning','implied consent wording is highlighted');
$negated=gca_analyze_policy('https://example.com/cookies','<p>Pokračováním v prohlížení webu neudělujete souhlas s volitelnými cookies.</p>');
check($negated['checks']['implied_consent']['status']!=='warning','negated implied-consent statement is not a violation');
$inventory=gca_analyze_policy('https://example.com/cookies','<p>Analytická cookie _ga má platnost 12 měsíců.</p>',[],[['name'=>'_ga']]);
check($inventory['checks']['inventory']['status']==='pass','specific cookie inventory evidence');
$redirected=gca_fetch('https://example.com/',function($url){
    if($url==='https://example.com/')return ['code'=>302,'headers'=>['location'=>['https://www.example.com/'],'set-cookie'=>['first=SECRET; Path=/; Secure']],'body'=>''];
    return ['code'=>200,'headers'=>['content-type'=>['text/html']],'body'=>'<h1>Done</h1>'];
});
$redirectPage=gca_analyze_page($redirected['url'],$redirected['html'],$redirected['cookies']);
check(count($redirectPage['cookies'])===1 && $redirectPage['cookies'][0]['domain']==='example.com','redirect cookies retain origin metadata');
check(strpos(json_encode($redirectPage),'SECRET')===false,'redirect cookie values discarded');
echo "ALL CORE TESTS PASSED\n";
