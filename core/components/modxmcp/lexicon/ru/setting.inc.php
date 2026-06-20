<?php
$_lang['setting_modxmcp.enabled'] = 'Включить MODX MCP';
$_lang['setting_modxmcp.enabled_desc'] = 'Глобально включает или отключает API-компонент MODX MCP. Если настройка выключена, все запросы к API будут отклоняться.';

$_lang['setting_modxmcp.api_token'] = 'API-токен MODX MCP';
$_lang['setting_modxmcp.api_token_desc'] = 'Секретный токен, который передаётся в заголовке X-MCP-Token для авторизации всех запросов к MCP API.';

$_lang['setting_modxmcp.service_user_id'] = 'ID сервисного пользователя';
$_lang['setting_modxmcp.service_user_id_desc'] = 'ID пользователя MODX, от имени которого MCP выполняет процессоры и административные действия. Пользователь должен существовать и быть активным.';

$_lang['setting_modxmcp.debug'] = 'Режим отладки MCP';
$_lang['setting_modxmcp.debug_desc'] = 'Если включено, API будет возвращать подробности внутренних ошибок в ответе. В продакшене рекомендуется держать выключенным.';

$_lang['setting_modxmcp.audit_log'] = 'Аудит-лог MCP';
$_lang['setting_modxmcp.audit_log_desc'] = 'Если включено, операции create/update/delete и обновления TV будут записываться в лог MODX для аудита и диагностики.';

$_lang['setting_modxmcp.max_payload_bytes'] = 'Максимальный размер payload';
$_lang['setting_modxmcp.max_payload_bytes_desc'] = 'Максимально допустимый размер JSON-запроса к API в байтах. Защищает компонент от слишком больших или ошибочных запросов.';

$_lang['setting_modxmcp.allow_root_filesystem_read'] = 'Разрешить чтение корневого Filesystem';
$_lang['setting_modxmcp.allow_root_filesystem_read_desc'] = 'Если включено, MCP сможет просматривать и читать файлы через корневой media source Filesystem. Лучше держать выключенной, если нужно только безопасное изучение кода компонентов.';

$_lang['setting_modxmcp.component_code_roots'] = 'Корни кода компонентов';
$_lang['setting_modxmcp.component_code_roots_desc'] = 'Список каталогов через запятую, которые MCP может сканировать для изучения кода компонентов, например core/components,assets/components.';

$_lang['setting_modxmcp.core_path'] = 'Путь к ядру';
$_lang['setting_modxmcp.core_path_desc'] = 'Путь в файловой системе к директории ядра компонента modxMCP. По умолчанию {core_path}components/modxmcp/.';

$_lang['area_modxmcp:main'] = 'modxMCP: Основное';
$_lang['area_modxmcp:limits'] = 'modxMCP: Лимиты';
$_lang['area_modxmcp:security'] = 'modxMCP: Безопасность';
$_lang['area_modxmcp:paths'] = 'modxMCP: Пути';

$_lang['setting_modxmcp.auto_static'] = 'Авто-статика элементов';
$_lang['setting_modxmcp.auto_static_desc'] = 'Если включено, создание/обновление чанка/сниппета/шаблона/плагина через MCP автоматически переводит его в статический файл в core/elements/ (источник — Filesystem). Дальше правьте эти файлы напрямую.';


$_lang['setting_modxmcp.allow_run_processor'] = 'Разрешить run_processor';
$_lang['setting_modxmcp.allow_run_processor_desc'] = 'Если включено, действие run_processor может выполнить ЛЮБОЙ процессор MODX напрямую. Мощный универсальный механизм — по умолчанию выключен. Предпочитайте специализированные действия, если они есть.';
