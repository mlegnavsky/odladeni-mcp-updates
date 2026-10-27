<?php
declare(strict_types=1);

// Called only after console authentication, site authorization and CSRF checks.
function odlDisconnectRegistry(PDO $db,string $site,string $expectedKey,string $actor,string $mode,callable $disconnect):void {
 if(!in_array($mode,['bridge','registry'],true))throw new InvalidArgumentException('Neplatný způsob odpojení.');
 if($mode==='bridge')$disconnect($site,$expectedKey);
 $db->exec('BEGIN IMMEDIATE');
 try{
  $q=$db->prepare('DELETE FROM connected_sites WHERE site_url=? AND shared_key=?');$q->execute([$site,$expectedKey]);
  if($q->rowCount()!==1)throw new RuntimeException('Propojení se mezitím změnilo. Obnovte stránku a zkuste to znovu.');
  // A newly paired installation must not inherit the previous membership or cache.
  foreach(['account_memberships','fleet_overview'] as $table){
   $q=$db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);$exists=(bool)$q->fetchColumn();$q->closeCursor();
   if($exists){$q=$db->prepare('DELETE FROM '.$table.' WHERE site_url=?');$q->execute([$site]);}
  }
  odlAccountsAudit($db,$actor,'site_disconnect_'.$mode,$site);
  $db->exec('COMMIT');
 }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}

function odlDisconnectForm(string $site,string $csrf,bool $super):void { ?>
<details class="odl-disconnect" style="margin-top:16px;border-top:1px solid #e0e7f5;padding-top:12px">
 <summary style="cursor:pointer;color:#b42335;font-weight:700">Odpojit web</summary>
 <p>Web: <strong><?=h($site)?></strong>. Odpojení nesmaže obsah ani pluginy WordPressu.</p>
 <form method="post" action="/console/?action=disconnect">
  <input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="site" value="<?=h($site)?>">
  <label style="display:block;margin:12px 0"><input type="checkbox" name="confirm" value="disconnect" required> Potvrzuji odpojení webu <?=h($site)?></label>
  <?php if($super):?><label style="display:block;margin:12px 0"><input type="checkbox" name="registry_only" value="1"> Odebrat pouze z Odladění — původní WordPress byl přeinstalován nebo není dostupný.</label>
  <p class="muted">Při této volbě se WordPress nekontaktuje a jeho původní klíč se nezneplatní. Novou instalaci připojte znovu; původní přidělení správců se odebere.</p><?php endif;?>
  <button type="submit" class="btn danger">Potvrdit odpojení webu</button>
 </form>
</details>
<?php }
