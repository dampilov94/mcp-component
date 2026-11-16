<?php
namespace ModxMCP\Prompt;

final class AuditPrompt extends Prompt {
    public function name() { return 'audit'; }
    public function title() { return 'Провести аудит'; }
    public function description() { return 'Найти проблемы сайта и предложить исправления; менять только по выбору человека.'; }

    public function arguments() {
        return array(self::argument('focus', 'На чём сосредоточиться, например «только битые ссылки»', false));
    }

    protected function text(array $args) {
        return "Проведи аудит сайта.\n\n"
            . "1. Вызови `modx_audit_site`.\n"
            . "2. Сгруппируй находки по важности и по объектам.\n"
            . "3. Для каждой ошибки объясни причину одной строкой; где нужно, используй `modx_find_usages` и `modx_view_code`.\n"
            . "4. Предложи исправления нумерованным списком.\n"
            . "5. Ничего не меняй, пока человек не выберет пункты. Для исправлений нужен токен с областью выше read; если её нет, скажи об этом.\n"
            . "6. Для каждого выбранного пункта: `modx_checkpoint`, исправление, затем `modx_check_page` затронутых страниц.";
    }
}
