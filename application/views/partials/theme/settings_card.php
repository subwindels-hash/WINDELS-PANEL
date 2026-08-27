<?php defined('BASEPATH') OR exit('No direct script access allowed');?>
<!-- Settings card theme - glassmorphism with neon accents -->
<div class="card bg-[rgba(17,17,27,0.8)] backdrop-blur-xl border border-white/5 shadow-2xl shadow-black/30 max-w-3xl mx-auto my-6">
  <div class="p-6 space-y-4">
    <?php foreach ($grouped as $category => $fields): ?>
      <div class="space-y-3">
        <h3 class="text-sm uppercase tracking-widest text-slate-400 mb-2<?= in_array($category, array('general','api','branding')) ? ' text-purple-400' : ''?> font-medium"><?=htmlspecialchars($category_titles[$category] ?? ucfirst($category))?></h3>
        <?php foreach ($fields as $key => $def): ?>
          <?php list($type, , $label, , $default) = $def; ?>
          <?php $id = 'set-'.$key; ?>
          <div class="field space-y-1" style="margin-bottom:1rem">
            <?php if ($type === 'bool'): ?>
              <?php $on = ($value === true || $value === 1 || $value === '1' || $value === 'true'); ?>
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="checkbox" id="<?=$id?>" name="<?=$key?>" value="1" <?=($on ? 'checked' : '')?> class="w-4 h-4 rounded bg-purple-500/20 border border-purple-500/50 hover:bg-purple-500/30 transition-check checked:bg-purple-500/40">
                <span class="label select-none"><?=htmlspecialchars($label)?></span>
              </label>
            <?php elseif (strpos($type, 'choice:') === 0): ?>
              <label class="label text-slate-300 mb-1 block"><?=htmlspecialchars($label)?></label>
              <select id="<?=$id?>" name="<?=$key?>" class="select w-full bg-[rgba(30,30,45,0.6)] border border-white/5 rounded px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-purple-500/50 transition-colors">
                <?php $opts = explode('|', substr($type, 7)); ?>
                <?php foreach ($opts as $o): ?>
                  <option value="<?=htmlspecialchars($o)?>" <?=((string)$value === $o ? 'selected' : '')?>><?=htmlspecialchars($o)?></option>
                <?php endforeach; ?>
              </select>
            <?php elseif ($type === 'secret'): ?>
              <label class="label text-slate-300 mb-1 block"><?=htmlspecialchars($label)?></label>
              <input class="input w-full bg-[rgba(30,30,45,0.6)] border border-white/5 rounded px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-purple-500/50 transition-colors" type="password" autocomplete="new-password" spellcheck="false"
                id="<?=$id?>" name="<?=$key?>" value="<?=($value !== null && $value !== '' && $value !== SettingsService::SECRET_PLACEHOLDER ? htmlspecialchars(SettingsService::SECRET_PLACEHOLDER) : '')?>" placeholder="<?=($value !== null && $value !== '' && $value !== SettingsService::SECRET_PLACEHOLDER ? 'Configured — type a new value to replace it' : 'Not configured')?>">
              <?php if ($value !== null && $value !== '' && $value !== SettingsService::SECRET_PLACEHOLDER): ?>
                <p class="muted text-xs mt-1">A value is stored. Leave the field untouched to keep it, or clear it to remove it.</p>
              <?php endif; ?>
            <?php elseif ($type === 'longtext'): ?>
              <label class="label text-slate-300 mb-1 block"><?=htmlspecialchars($label)?></label>
              <textarea class="textarea w-full bg-[rgba(30,30,45,0.6)] border border-white/5 rounded h-32 px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-purple-500/50 resize-none transition-colors" id="<?=$id?>" name="<?=$key?>" rows="4"><?=htmlspecialchars((string)$value)?></textarea>
            <?php else: ?>
              <?php $input_type = in_array($type, array('int','money','percent'), true) ? 'number' : ($type === 'email' ? 'email' : ($type === 'url' ? 'url' : 'text')); ?>
              <?php $step = $type === 'money' ? '0.01' : ($type === 'percent' ? '0.01' : '1'); ?>
              <label class="label text-slate-300 mb-1 block"><?=htmlspecialchars($label)?></label>
              <input class="input w-full bg-[rgba(30,30,45,0.6)] border border-white/5 rounded px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-purple-500/50 transition-colors<?= in_array($input_type, array('number','email','url')) ? ' input-mono' : ''?>" type="<?=$input_type?>" <?=($input_type === 'number' ? 'step="'.$step.'" min="0"' : '')?> id="<?=$id?>" name="<?=$key?>" value="<?=htmlspecialchars((string)$value)?>">
            <?php endif; ?>
            <?php if ($help): ?><p class="muted text-xs mt-1"><?=htmlspecialchars($help)?></p><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    
    <!-- Action buttons -->
    <div class="pt-6 border-t border-white/10 flex justify-end">
      <button class="btn btn-primary px-6 py-2.5 rounded-lg text-white font-medium hover:bg-purple-600/80 transition-colors" type="submit">Save settings</button>
    </div>
  </div>
</div>