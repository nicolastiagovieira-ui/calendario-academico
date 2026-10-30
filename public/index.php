<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$appName=htmlspecialchars((string)cal_config('app.name'),ENT_QUOTES,'UTF-8');
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars(cal_csrf(),ENT_QUOTES,'UTF-8') ?>">
  <title><?= $appName ?></title><link rel="stylesheet" href="assets/calendar.css"><link rel="stylesheet" href="assets/compact.css">
</head>
<body>
<div id="toast" class="toast" role="status"></div>
<section id="loginView" class="auth-shell hidden">
  <form id="loginForm" class="auth-card">
    <div class="brand-mark">N</div><h1>Calendário clínico</h1><p>Acesso interno à gestão de datas</p>
    <label>Usuário ou e-mail<input name="email" type="text" autocomplete="username" required></label>
    <label>Senha<input name="senha" type="password" autocomplete="current-password" required></label>
    <button class="primary" type="submit">Entrar</button><div class="form-error" id="loginError"></div>
  </form>
</section>
<section id="passwordView" class="auth-shell hidden">
  <form id="passwordForm" class="auth-card"><h1>Crie sua senha</h1><p>Esta troca é obrigatória no primeiro acesso.</p>
    <label>Senha atual<input name="senhaAtual" type="password" required></label>
    <label>Nova senha<input name="novaSenha" type="password" minlength="10" required></label>
    <label>Confirme a nova senha<input name="confirmacao" type="password" minlength="10" required></label>
    <button class="primary" type="submit">Salvar nova senha</button><div class="form-error" id="passwordError"></div>
  </form>
</section>
<div id="appView" class="app hidden">
  <header class="topbar"><div><span class="eyebrow">GESTÃO ACADÊMICA</span><h1>Calendário de disciplinas</h1></div><div class="userbox"><span id="userEmail"></span><span id="userRole" class="role"></span><button id="logoutBtn" class="ghost">Sair</button></div></header>
  <main>
    <div class="toolbar">
      <div class="unit-picker"><label><span>Cidade / polo</span><select id="citySelect"></select></label><button id="poleStatusBtn" class="danger hidden"></button></div>
      <div class="period-controls"><div class="month-nav"><button id="prevMonth" aria-label="Mês anterior">‹</button><button id="monthTitle" class="month-title" title="Selecionar mês e ano"></button><input id="monthPicker" class="month-picker" type="month" aria-label="Selecionar mês e ano"><button id="nextMonth" aria-label="Próximo mês">›</button></div><button id="todayBtn" class="ghost">Hoje</button></div>
      <div class="unit-summary"><span>Capacidade da unidade</span><strong id="unitDailyCapacity">0 vagas / dia</strong><small id="unitRoomCount">0 salas</small><span id="dirtyBadge" class="dirty hidden">Alterações não salvas</span></div>
    </div>
    <div class="workspace">
      <section class="calendar-panel"><div class="weekdays"><span>Dom</span><span>Seg</span><span>Ter</span><span>Qua</span><span>Qui</span><span>Sex</span><span>Sáb</span></div><div id="calendar" class="calendar"></div></section>
      <aside class="catalog"><div class="catalog-head"><div><span class="eyebrow">CATÁLOGO</span><h2>Atividades</h2></div><input id="stageSearch" type="search" placeholder="Buscar"></div><p class="hint">Selecione uma atividade e use o <b>+</b>. Clique com o botão direito em uma atividade agendada para removê-la ou personalizar as vagas do dia.</p><div id="stageList" class="stage-list"></div></aside>
    </div>
  </main>
  <footer class="savebar"><div><b id="changeCount">Nenhuma alteração</b><small id="saveHint">O calendário está atualizado.</small></div><button id="discardBtn" class="ghost" disabled>Descartar</button><button id="saveBtn" class="primary" disabled>Salvar alterações</button></footer>
</div>
<div id="loadingOverlay" class="loading-overlay hidden" aria-live="polite"><div class="spinner"></div><span>Carregando calendário...</span></div>
<dialog id="blockDialog"><form method="dialog"><h2 class="dialog-title">Adicionar Evento em <span id="blockDate"></span></h2><fieldset class="rule-options"><legend>Tipo</legend><label><input type="radio" name="blockType" value="feriado" checked> Feriado</label><label><input type="radio" name="blockType" value="folga"> Folga</label><label><input type="radio" name="blockType" value="recesso"> Recesso</label><hr><label><input type="radio" name="blockType" value="bloqueado"> Bloqueio</label><label><input type="radio" name="blockType" value="limitado"> Alterar quantidade de vagas</label></fieldset><label id="unitLimitField" class="hidden">Número de vagas<input id="unitLimitQuantity" type="number" min="1" max="500"></label><label>Observação<input id="blockNote" maxlength="255"></label><p class="hint">Feriado, folga e recesso removem as disciplinas no dia <b class="ruleDate"></b>.<br>Bloqueio e alterar quantidade de vagas somente modificam o limite no dia <b class="ruleDate"></b>.</p><div class="dialog-actions"><button id="cancelBlock" type="button" class="ghost">Cancelar</button><button id="confirmBlock" value="default" class="primary">Aplicar</button></div></form></dialog>
<div id="eventMenu" class="context-menu hidden" role="menu"><button id="changeExtraBtn" role="menuitem"></button><button id="blockStageBtn" role="menuitem"></button><button id="removeEventBtn" class="danger-item" role="menuitem"></button></div>
<dialog id="extraDialog"><form method="dialog"><h2 id="extraTitle"></h2><label>Quantidade de vagas<input id="extraQuantity" type="number" min="1" max="500" required></label><p class="hint">Este valor valerá somente para esta atividade nesta data. A capacidade padrão não será alterada.</p><div class="dialog-actions"><button value="cancel" class="ghost">Cancelar</button><button id="confirmExtra" value="default" class="primary">Aplicar</button></div></form></dialog>
<script type="module" src="assets/calendar.js"></script>
</body></html>
