<div class="container" style="padding:18px;max-width:840px;">
    <h2 style="margin-top:0;">modxMCP</h2>
    <p style="color:#666;">{$l.intro}</p>

    <table class="modxmcp-status" cellpadding="6" style="border-collapse:collapse;width:100%;margin:14px 0;">
        <tr><td style="width:240px;color:#888;">{$l.endpoint}</td><td><code>{$endpoint}</code></td></tr>
        <tr><td style="color:#888;">{$l.enabled}</td><td>{if $enabled}<span style="color:#2e7d32;font-weight:bold;">{$l.yes}</span>{else}<span style="color:#c62828;font-weight:bold;">{$l.no}</span> &mdash; {$l.enable_hint}{/if}</td></tr>
        <tr><td style="color:#888;">{$l.auto_static} (<code>modxmcp.auto_static</code>)</td><td>{if $auto_static}{$l.on}{else}{$l.off}{/if}</td></tr>
        <tr><td style="color:#888;">{$l.audit_log} (<code>modxmcp.audit_log</code>)</td><td>{if $audit_log}{$l.on}{else}{$l.off}{/if}</td></tr>
    </table>

    <h3 style="margin:18px 0 6px;">Токены</h3>
    <p style="color:#666;margin-top:0;">Каждому клиенту — свой токен со своим уровнем доступа. Токен показывается один раз при создании; хранится только его хеш.</p>
    {if $tokens}
    <table class="modxmcp-tokens" cellpadding="6" style="border-collapse:collapse;width:100%;margin:6px 0 12px;">
        <tr style="color:#888;text-align:left;">
            <th>Название</th><th>Начало</th><th>Доступ</th><th>Создан</th><th>Использован</th><th>Истекает</th><th></th>
        </tr>
        {foreach $tokens as $t}
        <tr{if $t.revoked || $t.expired} style="color:#aaa;"{/if}>
            <td>{$t.label|escape}</td>
            <td><code>{$t.prefix|escape}…</code></td>
            <td>{$t.scope}{if $t.plan_only} <span style="color:#888;">(только план)</span>{/if}</td>
            <td>{$t.created}</td>
            <td>{if $t.last_used}{$t.last_used}{else}—{/if}</td>
            <td>{if $t.expires}{$t.expires}{else}—{/if}</td>
            <td>{if $t.revoked}отозван{elseif $t.expired}истёк{else}<button class="x-btn modxmcp-revoke" data-id="{$t.id}" style="padding:3px 10px;cursor:pointer;">Отозвать</button>{/if}</td>
        </tr>
        {/foreach}
    </table>
    {else}
    <p style="color:#c62828;">Токенов нет — создайте первый, чтобы подключить клиент.</p>
    {/if}

    <div style="margin:8px 0 4px;">
        <input type="text" id="modxmcp-token-label" placeholder="Название, например «Claude Code»" maxlength="190" style="padding:6px 8px;width:260px;">
        <select id="modxmcp-token-scope" style="padding:6px 8px;">
            <option value="read">read — только чтение</option>
            <option value="content">content — ресурсы и файлы</option>
            <option value="develop" selected>develop — элементы, код, настройки</option>
            <option value="admin">admin — всё, включая доступ и пакеты</option>
        </select>
        <label style="margin-left:8px;cursor:pointer;"><input type="checkbox" id="modxmcp-token-plan"> только план</label>
        <button id="modxmcp-create-token" class="x-btn" style="padding:7px 16px;margin-left:8px;cursor:pointer;">Создать токен</button>
    </div>
    <div id="modxmcp-token-result" style="display:none;margin-top:12px;padding:12px;border:1px solid #cfe3cf;background:#f3faf3;border-radius:4px;">
        <strong>Новый токен (скопируйте сейчас — показывается один раз):</strong>
        <pre id="modxmcp-token-value" style="white-space:pre-wrap;word-break:break-all;margin:6px 0 0;user-select:all;"></pre>
    </div>

    <div style="margin:18px 0;padding:14px 16px;border:1px solid #dfe3e8;border-radius:6px;background:#fbfcfd;">
        <div style="font-weight:bold;margin-bottom:3px;">Граф связей</div>
        <p style="color:#666;margin:0 0 11px;">Карта того, как связаны элементы сайта: что чем используется,
            какие ссылки битые и какие элементы никто не использует. Открывается отдельной страницей.</p>
        <a href="?a=graph&amp;namespace=modxmcp" class="x-btn" style="display:inline-block;padding:8px 16px;cursor:pointer;text-decoration:none;">Открыть граф связей &rarr;</a>
    </div>

    {if $components || $features}
    <h3 style="margin:18px 0 6px;">Возможности</h3>
    <p style="color:#666;margin-top:0;">Выключенные группы не передаются ИИ (экономия токенов) и блокируются на сервере. Изменения применяются автоматически при следующем действии ИИ (переподключение не требуется).</p>

    {if $components}
    <h4 style="margin:12px 0 4px;color:#555;">Компоненты</h4>
    <div class="modxmcp-groups" style="margin:2px 0 8px;">
        {foreach $components as $g}
        <label style="display:block;margin:4px 0;cursor:pointer;">
            <input type="checkbox" class="modxmcp-grp" data-group="{$g.key}"{if $g.enabled} checked{/if}> {$g.label} <span style="color:#bbb;">(<code>{$g.key}</code>)</span>
        </label>
        {/foreach}
    </div>
    {/if}

    {if $features}
    <h4 style="margin:12px 0 4px;color:#555;">Возможности MODX</h4>
    <div class="modxmcp-groups" style="margin:2px 0 8px;">
        {foreach $features as $g}
        <label style="display:block;margin:4px 0;cursor:pointer;">
            <input type="checkbox" class="modxmcp-grp" data-group="{$g.key}"{if $g.enabled} checked{/if}> {$g.label} <span style="color:#bbb;">(<code>{$g.key}</code>)</span>
        </label>
        {/foreach}
    </div>
    {/if}

    <p>
        <button id="modxmcp-savegroups" class="x-btn" style="padding:8px 16px;cursor:pointer;" disabled>Сохранить возможности</button>
        <span id="modxmcp-groups-status" style="margin-left:10px;color:#888;"></span>
    </p>
    {/if}

    {if $integrations}
    <h3 style="margin:18px 0 6px;">{$l.integrations}</h3>
    <p style="color:#666;margin-top:0;">{$l.integrations_intro}</p>
    <table class="modxmcp-integrations" cellpadding="6" style="border-collapse:collapse;width:100%;margin:6px 0 14px;">
        {foreach $integrations as $i}
        <tr>
            <td style="width:160px;font-weight:bold;">{$i.label}</td>
            <td style="width:90px;">{if $i.installed}<span style="color:#2e7d32;">✓ {if $i.version}{$i.version}{else}{$l.yes}{/if}</span>{else}<span style="color:#bbb;">—</span>{/if}</td>
            <td style="color:#666;">{if $i.available}доступно{elseif $i.installed}файлы дополнения не найдены{else}не установлено{/if}</td>
        </tr>
        {/foreach}
    </table>
    {/if}

    <p style="color:#999;font-size:12px;margin-top:18px;">{$l.settings_hint}</p>
</div>
