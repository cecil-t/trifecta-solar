<?php
use App\Csrf;
use App\Projects;
use App\Municipalities;

$v = static fn (string $k, $d = '') => $p[$k] ?? $d;
$sel = static fn ($a, $b) => (string) $a === (string) $b && (string) $a !== '' ? 'selected' : '';
$price = $p['contract_price'] ?? (isset($p['contract_price_cents']) && $p['contract_price_cents'] !== null ? number_format($p['contract_price_cents'] / 100, 2, '.', '') : '');
$fundingIds = array_map('intval', $p['funding'] ?? $p['funding_ids'] ?? []);
$eqRows = static function (string $key) use ($p): array {
    $rows = array_values((array) ($p[$key] ?? []));
    return $rows ?: [[]];
};
$isNew = $id === null;
?>
<div class="page-head">
    <div>
        <a href="<?= $isNew ? '/projects' : '/projects/' . (int) $id ?>" class="back">&larr; <?= $isNew ? 'Projects' : e($p['project_number'] . ' ' . $p['name']) ?></a>
        <h1><?= $isNew ? 'New project' : 'Edit project' ?></h1>
    </div>
</div>

<form method="post" action="<?= $isNew ? '/projects' : '/projects/' . (int) $id ?>" class="project-form">
    <?= Csrf::field() ?>

    <section class="card">
        <h2>Basics</h2>
        <div class="grid-form">
            <label>Project #
                <input type="text" name="project_number" value="<?= e($v('project_number')) ?>" required class="input-short">
            </label>
            <label class="span-2">Project name <span class="hint-inline">(unique, e.g. "Maple Hollow Farms LLC" or "Riverside Warehouse")</span>
                <input type="text" name="name" value="<?= e($v('name')) ?>" required>
            </label>
            <?php if ($isNew): ?>
                <label>Contract signed
                    <input type="date" name="contract_signed" value="<?= e($v('contract_signed')) ?>">
                </label>
            <?php endif; ?>
            <label>Salesperson
                <select name="salesperson_id">
                    <option value="">Choose</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= $sel($v('salesperson_id'), $u['id']) ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Customer type
                <select name="customer_type">
                    <option value="">Choose</option>
                    <?php foreach (Projects::CUSTOMER_TYPES as $k => $label): ?>
                        <option value="<?= $k ?>" <?= $sel($v('customer_type'), $k) ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="check self-end"><input type="checkbox" name="is_agricultural" value="1" <?= $v('is_agricultural') ? 'checked' : '' ?>> Agricultural</label>
            <label>OpenSolar quote #
                <input type="text" name="quote_number" value="<?= e($v('quote_number')) ?>" class="input-short">
            </label>
            <label class="span-2">Google Drive project folder
                <input type="url" name="drive_url" value="<?= e($v('drive_url')) ?>" placeholder="https://drive.google.com/drive/folders/...">
            </label>
        </div>
    </section>

    <section class="card">
        <h2>Customer</h2>
        <div class="grid-form">
            <label class="span-2">Customer
                <select name="customer_id" data-reveal="new" data-target="#new-customer">
                    <option value="">Choose</option>
                    <option value="new" <?= $sel($v('customer_id'), 'new') ?>>+ New customer</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $sel($v('customer_id'), $c['id']) ?>><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div id="new-customer" class="grid-form reveal" hidden>
            <label class="span-2">New customer name <input type="text" name="new_customer_name" value="<?= e($v('new_customer_name')) ?>"></label>
            <label>Phone <input type="text" name="new_customer_phone" value="<?= e($v('new_customer_phone')) ?>"></label>
            <label>Email <input type="email" name="new_customer_email" value="<?= e($v('new_customer_email')) ?>"></label>
            <p class="hint span-all">Add more contacts on the customer's page afterward.</p>
        </div>
    </section>

    <section class="card">
        <h2>Site and jurisdiction</h2>
        <div class="grid-form">
            <label class="span-2">Site street <input type="text" name="site_street" value="<?= e($v('site_street')) ?>"></label>
            <label>City <input type="text" name="site_city" value="<?= e($v('site_city')) ?>"></label>
            <label>State <input type="text" name="site_state" value="<?= e($v('site_state', 'PA')) ?>" maxlength="2" class="input-short"></label>
            <label>ZIP <input type="text" name="site_zip" value="<?= e($v('site_zip')) ?>" class="input-short"></label>
            <label>Utility
                <select name="utility_id">
                    <option value="">Choose</option>
                    <?php foreach ($utilities as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= $sel($v('utility_id'), $u['id']) ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="span-2">Municipality (AHJ)
                <select name="municipality_id" data-reveal="new" data-target="#new-muni">
                    <option value="">Choose</option>
                    <option value="new" <?= $sel($v('municipality_id'), 'new') ?>>+ Add a municipality</option>
                    <?php foreach ($municipalities as $m): ?>
                        <option value="<?= (int) $m['id'] ?>" <?= $sel($v('municipality_id'), $m['id']) ?>><?= e(Municipalities::label($m)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div id="new-muni" class="grid-form reveal" hidden>
            <label class="span-2">Municipality name <input type="text" name="new_muni_name" value="<?= e($v('new_muni_name')) ?>" placeholder="e.g. Penn Township"></label>
            <label>County
                <select name="new_muni_county_id">
                    <option value="">Choose</option>
                    <?php foreach ($counties as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $sel($v('new_muni_county_id'), $c['id']) ?>><?= e($c['name'] . ' (' . $c['state_code'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <?php if (!$isNew): ?>
            <div class="grid-form mt-sm">
                <?php foreach (['zoning' => 'Zoning', 'plan_review' => 'Plan review', 'inspection' => 'Inspections'] as $slot => $label): ?>
                    <div class="provider">
                        <label><?= $label ?> by
                            <select name="<?= $slot ?>_mode" data-reveal="agency" data-target="#<?= $slot ?>-org">
                                <?php foreach (Municipalities::MODES as $mk => $ml): ?>
                                    <option value="<?= $mk ?>" <?= $sel($v($slot . '_mode'), $mk) ?>><?= e($ml) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label id="<?= $slot ?>-org" class="reveal" hidden>Agency
                            <select name="<?= $slot ?>_org_id">
                                <option value="">Choose</option>
                                <?php foreach ($agencies as $a): ?>
                                    <option value="<?= (int) $a['id'] ?>" <?= $sel($v($slot . '_org_id'), $a['id']) ?>><?= e($a['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="hint">Defaults come from the municipality when it's chosen. Admins add agencies under Lists &rsaquo; Directory.</p>
        <?php else: ?>
            <p class="hint">Zoning, plan review and inspection providers are copied from the municipality's usual setup and can be changed after the project is created.</p>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>System</h2>
        <div class="grid-form">
            <label>Install type
                <select name="install_type">
                    <option value="">Choose</option>
                    <?php foreach (Projects::INSTALL_TYPES as $k => $label): ?>
                        <option value="<?= $k ?>" <?= $sel($v('install_type'), $k) ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="span-2">Racking / tracker
                <input type="text" name="racking" value="<?= e($v('racking')) ?>" list="racking-list" placeholder="e.g. SunAction 48, Mechatron, IronRidge">
                <datalist id="racking-list"><?php foreach ($racking as $r): ?><option value="<?= e($r) ?>"><?php endforeach; ?></datalist>
            </label>
            <label class="check self-end"><input type="checkbox" name="has_pv" value="1" <?= $v('has_pv', 1) ? 'checked' : '' ?>> Solar PV</label>
            <label class="check self-end"><input type="checkbox" name="has_batteries" value="1" <?= $v('has_batteries') ? 'checked' : '' ?> data-toggle="#battery-block"> Batteries</label>
            <label>Designer
                <select name="designer_org_id">
                    <option value="">Choose</option>
                    <?php foreach ($designers as $d): ?>
                        <option value="<?= (int) $d['id'] ?>" <?= $sel($v('designer_org_id'), $d['id']) ?>><?= e($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Installer
                <select name="installer_org_id">
                    <option value="">Trifecta (in-house)</option>
                    <?php foreach ($contractors as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $sel($v('installer_org_id'), $c['id']) ?>><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <h3 class="sub">Modules</h3>
        <div class="eq" data-eq="modules">
            <div class="eq-head"><span>Qty</span><span>Watts</span><span>Description (optional)</span><span></span></div>
            <?php foreach ($eqRows('modules') as $i => $r): ?>
                <div class="eq-row">
                    <input type="number" name="modules[<?= $i ?>][qty]" value="<?= e($r['qty'] ?? '') ?>" min="0" placeholder="Qty">
                    <input type="number" name="modules[<?= $i ?>][watts]" value="<?= e($r['watts'] ?? '') ?>" step="any" min="0" placeholder="590">
                    <input type="text" name="modules[<?= $i ?>][description]" value="<?= e($r['description'] ?? '') ?>" placeholder="SEG 590 Grade B">
                    <button type="button" class="btn btn-ghost btn-small eq-del" title="Remove">&times;</button>
                </div>
            <?php endforeach; ?>
            <button type="button" class="btn btn-ghost btn-small eq-add">+ Module line</button>
        </div>

        <h3 class="sub">Inverters</h3>
        <div class="eq" data-eq="inverters">
            <div class="eq-head"><span>Qty</span><span>AC kW each</span><span>Description (optional)</span><span></span></div>
            <?php foreach ($eqRows('inverters') as $i => $r): ?>
                <div class="eq-row">
                    <input type="number" name="inverters[<?= $i ?>][qty]" value="<?= e($r['qty'] ?? '') ?>" min="0" placeholder="Qty">
                    <input type="number" name="inverters[<?= $i ?>][ac_kw]" value="<?= e($r['ac_kw'] ?? '') ?>" step="any" min="0" placeholder="11.4">
                    <input type="text" name="inverters[<?= $i ?>][description]" value="<?= e($r['description'] ?? '') ?>" placeholder="SolarEdge Home Hub">
                    <button type="button" class="btn btn-ghost btn-small eq-del" title="Remove">&times;</button>
                </div>
            <?php endforeach; ?>
            <button type="button" class="btn btn-ghost btn-small eq-add">+ Inverter line</button>
        </div>

        <div id="battery-block" <?= $v('has_batteries') ? '' : 'hidden' ?>>
            <h3 class="sub">Batteries</h3>
            <div class="eq eq-4" data-eq="batteries">
                <div class="eq-head"><span>Qty</span><span>kWh each</span><span>kW each</span><span>Description (optional)</span><span></span></div>
                <?php foreach ($eqRows('batteries') as $i => $r): ?>
                    <div class="eq-row">
                        <input type="number" name="batteries[<?= $i ?>][qty]" value="<?= e($r['qty'] ?? '') ?>" min="0" placeholder="Qty">
                        <input type="number" name="batteries[<?= $i ?>][kwh]" value="<?= e($r['kwh'] ?? '') ?>" step="any" min="0" placeholder="10">
                        <input type="number" name="batteries[<?= $i ?>][kw]" value="<?= e($r['kw'] ?? '') ?>" step="any" min="0" placeholder="5">
                        <input type="text" name="batteries[<?= $i ?>][description]" value="<?= e($r['description'] ?? '') ?>" placeholder="SolarEdge Energy Bank">
                        <button type="button" class="btn btn-ghost btn-small eq-del" title="Remove">&times;</button>
                    </div>
                <?php endforeach; ?>
                <button type="button" class="btn btn-ghost btn-small eq-add">+ Battery line</button>
            </div>
        </div>
    </section>

    <section class="card">
        <h2>Contract and funding</h2>
        <div class="grid-form">
            <label>Contract price (incl. change orders)
                <input type="text" name="contract_price" value="<?= e($price) ?>" inputmode="decimal" placeholder="0.00">
            </label>
            <label>Est. annual production (kWh)
                <input type="text" name="est_annual_kwh" value="<?= e($v('est_annual_kwh')) ?>" inputmode="numeric">
            </label>
            <label>Tax exempt
                <select name="tax_exempt">
                    <option value="" <?= $v('tax_exempt') === null || $v('tax_exempt') === '' ? 'selected' : '' ?>>Unknown</option>
                    <option value="1" <?= $sel($v('tax_exempt'), 1) ?>>Yes (certificate)</option>
                    <option value="0" <?= $sel($v('tax_exempt'), 0) ?>>No</option>
                </select>
            </label>
        </div>
        <fieldset class="checks">
            <legend>Funding</legend>
            <?php foreach ($fundingSources as $f): ?>
                <label class="check"><input type="checkbox" name="funding[]" value="<?= (int) $f['id'] ?>" <?= in_array((int) $f['id'], $fundingIds, true) ? 'checked' : '' ?>> <?= e($f['name']) ?></label>
            <?php endforeach; ?>
        </fieldset>
        <label>Funding note
            <input type="text" name="funding_note" value="<?= e($v('funding_note')) ?>" placeholder="e.g. REAP awarded 6/2026, loan through Fulton">
        </label>
    </section>

    <section class="card">
        <h2>Status</h2>
        <div class="grid-form">
            <label class="span-2">Status note <span class="hint-inline">(where it stands, shown on the project list)</span>
                <input type="text" name="status_note" value="<?= e($v('status_note')) ?>">
            </label>
            <label>Hold / cancel
                <select name="hold_state" data-reveal-any="on_hold,cancelled" data-target="#hold-reason">
                    <option value="">Active</option>
                    <option value="on_hold" <?= $sel($v('hold_state'), 'on_hold') ?>>On hold</option>
                    <option value="cancelled" <?= $sel($v('hold_state'), 'cancelled') ?>>Cancelled</option>
                </select>
            </label>
            <label id="hold-reason" class="span-2 reveal" hidden>Reason
                <input type="text" name="hold_reason" value="<?= e($v('hold_reason')) ?>" placeholder="e.g. Waiting on Mechatron, zoning hearing">
            </label>
            <label class="check span-all"><input type="checkbox" name="archived" value="1" <?= $v('archived_at') || $v('archived') ? 'checked' : '' ?>> Archived <span class="hint-inline">(finished; moves it out of Active into the Archived tab regardless of open items)</span></label>
        </div>
    </section>

    <div class="form-actions">
        <button class="btn btn-primary"><?= $isNew ? 'Create project' : 'Save changes' ?></button>
        <a href="<?= $isNew ? '/projects' : '/projects/' . (int) $id ?>" class="btn btn-ghost">Cancel</a>
    </div>
</form>

<script src="<?= asset('assets/js/forms.js') ?>"></script>
