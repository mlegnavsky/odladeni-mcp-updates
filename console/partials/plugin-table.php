<?php
/**
 * Compact inventory template. Requires existing authenticated console context:
 * $siteDiagnostics, $siteDetail, $user and $_SESSION['csrf'].
 * All output is escaped through the console h() helper.
 */
if (!isset($siteDiagnostics, $siteDetail, $user)) { return; }
?>
<div class="plugin-table-wrap">
<table class="plugin-table">
<thead><tr><th>Plugin</th><th>Verze</th><th>Aktivní</th><th>Podrobnosti</th><th>Aktualizace</th><th>Automaticky</th></tr></thead>
<tbody>
<?php foreach (($siteDiagnostics['plugins'] ?? []) as $plugin):
 $file=(string)($plugin['file']??'');
 $autoKnown=array_key_exists('auto_update',$plugin);
 $auto=$autoKnown && !empty($plugin['auto_update']);
 $canUpdate=!empty($plugin['update_available'])&&!empty($siteDiagnostics['capabilities']['update'])&&in_array($user['role'],['admin','superadmin'],true);
?>
<tr>
<td><div class="plugin-name" title="<?=h((string)($plugin['name']??$file))?>"><?=h((string)($plugin['name']??$file))?></div></td>
<td><?=h((string)($plugin['version']??''))?></td>
<td><span class="<?=!empty($plugin['active'])?'icon-ok':'icon-off'?>" title="<?=!empty($plugin['active'])?'Aktivní':'Neaktivní'?>"><?=!empty($plugin['active'])?'&#10003;':'&#8212;'?></span></td>
<td><details><summary aria-label="Zobrazit podrobnosti" title="Podrobnosti">&#9432;</summary><div class="plugin-info">
<strong><?=h((string)($plugin['name']??$file))?></strong>
<p><?=h((string)($plugin['description']??'Popis není dostupný.'))?></p>
<p><strong>Poslední změny:</strong> <?=h((string)($plugin['changelog']??'Historie změn není dostupná.'))?></p>
</div></details></td>
<td><?php if($canUpdate):?><form method="post" action="/console/?action=site_plugin_update">
<input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
<input type="hidden" name="site" value="<?=h($siteDetail['site_url'])?>">
<input type="hidden" name="plugin" value="<?=h($file)?>">
<button type="submit" class="btn">Aktualizovat</button>
</form><?php else:?>&#8212;<?php endif;?></td>
<td><form method="post" action="/console/?action=site_auto_update">
<input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
<input type="hidden" name="site" value="<?=h($siteDetail['site_url'])?>">
<input type="hidden" name="plugin" value="<?=h($file)?>">
<input type="hidden" name="enabled" value="<?=$auto?'0':'1'?>">
<button type="submit" class="auto-toggle <?=$auto?'on':''?>" aria-label="Automatické aktualizace: <?=$auto?'zapnuty':'vypnuty'?>" title="<?=$auto?'Vypnout':'Zapnout'?> automatické aktualizace" <?=!$autoKnown?'disabled':''?>><span class="knob"></span></button>
</form></td>
</tr>
<?php endforeach;?>
</tbody></table></div>
