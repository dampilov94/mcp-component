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
$_lang['setting_modxmcp.auto_static_desc'] = 'Если включено, создание/обновление через MCP переводит элементы из БД в файлы с уникальными именами внутри настроенного core_path/elements/ (штатное статическое хранение MODX, source=0). Уже статические элементы сохраняют свой путь и Media Source.';


$_lang['setting_modxmcp.allow_run_processor'] = 'Разрешить run_processor';
$_lang['setting_modxmcp.allow_run_processor_desc'] = 'Если включено, действие run_processor может выполнить ЛЮБОЙ процессор MODX напрямую. Мощный универсальный механизм — по умолчанию выключен. Предпочитайте специализированные действия, если они есть.';

$_lang['setting_modxmcp.max_response_bytes'] = 'Максимальный размер ответа';
$_lang['setting_modxmcp.max_response_bytes_desc'] = 'Лимит JSON-ответа в байтах (по умолчанию 4194304, минимум 1024). Превышение возвращает ошибку; действие к этому моменту могло выполниться. Проверьте статус запроса перед повтором.';

$_lang['setting_modxmcp.request_retention_seconds'] = 'Хранение результатов запросов';
$_lang['setting_modxmcp.request_retention_seconds_desc'] = 'Сколько секунд хранить ответы для повторов с тем же ID (по умолчанию 86400, минимум 60). После истечения срока ID остаётся занятым, чтобы повтор не выполнил действие снова. Журнал хранится в core/modxmcp-data/requests вне кеша и пакета компонента.';

$_lang['setting_modxmcp.trusted_proxies'] = 'Доверенные прокси';
$_lang['setting_modxmcp.trusted_proxies_desc'] = 'IP-адреса или диапазоны CIDR доверенных прокси через запятую (IPv4/IPv6). Только от этих адресов REMOTE_ADDR принимается одиночный X-Forwarded-Proto: https при включённом require_https. Пустой список — не доверять заголовку. Прокси должен заменять заголовок клиента, а REMOTE_ADDR должен содержать адрес самого прокси.';

$_lang['setting_modxmcp.require_https'] = 'Требовать HTTPS';
$_lang['setting_modxmcp.require_https_desc'] = 'Отклонять POST-запросы без HTTPS. Проверяется серверный признак HTTPS либо заголовок X-Forwarded-Proto от доверенного прокси. Для завершения TLS на прокси предварительно настройте trusted_proxies. По умолчанию выключено.';

$_lang['setting_modxmcp.allowed_ips'] = 'Разрешённые IP-адреса';
$_lang['setting_modxmcp.allowed_ips_desc'] = 'IP-адреса или диапазоны CIDR через запятую (IPv4/IPv6), проверяемые по REMOTE_ADDR. Пустой список разрешает все адреса. X-Forwarded-For не используется для авторизации, неверные правила игнорируются.';
